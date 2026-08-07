<?php
/**
 * 分潤夥伴 Repository（Term + termmeta 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PersistenceFailure;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\RewriteRules;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;

/**
 * 以 profit_partner taxonomy term + termmeta 持久化分潤夥伴
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4、§6.3、§6.4
 *
 * termmeta 欄位（spec §2.4）：
 * - _partner_password（wp_hash_password 雜湊，登入驗證的唯一依據）
 * - _partner_password_plain（**明文密碼**，僅供站長在後台查看 / 複製；見下方說明）
 * - _partner_contact_email
 * - _partner_password_changed_at（unix timestamp）
 * - _partner_image_id（夥伴圖片 attachment ID；只存 ID，URL 於讀取時即時解析）
 *
 * ⚠️ **明文密碼是站長要求下的刻意設計，不是遺漏。**
 * 站長需要在後台隨時看到 / 複製夥伴的報表登入密碼再轉交給夥伴（LINE / Email），
 * 這件事在「只存雜湊」的前提下技術上不可能——雜湊不可逆。因此改為**雜湊與明文並存**：
 *
 *   - `_partner_password`（雜湊）**仍是 `verify_password()` 的唯一依據**，登入驗證邏輯完全未變
 *   - `_partner_password_plain`（明文）**只供顯示**，任何驗證路徑都不得讀它
 *
 * 兩者刻意分開而非「只留明文」，是為了讓這個決定可逆：日後若要收回明文功能，
 * 只需停止寫入並刪除這個 meta，登入流程一行都不用改。
 *
 * 已知風險（站長已知悉並接受）：資料庫或備份外洩時，所有夥伴的報表密碼將直接可讀；
 * 若夥伴重複使用了他在其他服務的密碼，該密碼也會一併曝光。
 * 緩解限縮在「這組密碼只能登入唯讀的分潤報表頁，不能改任何資料、不涉金流」。
 *
 * `PartnerSnapshot::$report_url` 沒有對應的 termmeta——它由 slug 加上站台設定的
 * report slug 於讀取時即時組出（見 `resolve_report_url()`），與 `image_url` 同屬
 * 唯讀衍生欄位，`save()` 不會寫入也不會讀取它。
 */
final class PartnerTermRepository implements PartnerRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Term meta keys
	 */
	private const META_PASSWORD            = '_partner_password';
	private const META_PASSWORD_PLAIN      = '_partner_password_plain';
	private const META_CONTACT_EMAIL       = '_partner_contact_email';
	private const META_PASSWORD_CHANGED_AT = '_partner_password_changed_at';
	private const META_IMAGE_ID            = '_partner_image_id';

	/**
	 * 夥伴圖片解析用的 image size
	 *
	 * 使用 thumbnail（預設 150x150 硬裁切）足以應付列表 / selector / 編輯頁預覽；
	 * 若站台未產生該尺寸，`wp_get_attachment_image_url()` 會自動退回 full size。
	 *
	 * @var string
	 */
	private const IMAGE_SIZE = 'thumbnail';

	/**
	 * 依 slug 取得 Partner
	 *
	 * @param string $slug Partner slug 字串
	 *
	 * @return PartnerSnapshot|null 找不到時回傳 null
	 */
	public function find_by_slug( string $slug ): ?PartnerSnapshot {
		$term = \get_term_by( 'slug', $slug, TaxonomyRegistrar::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return $this->hydrate_from_term( $term );
	}

	/**
	 * 依 term ID 取得 Partner
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return PartnerSnapshot|null 找不到時回傳 null
	 */
	public function find_by_id( int $term_id ): ?PartnerSnapshot {
		$term = \get_term( $term_id, TaxonomyRegistrar::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return $this->hydrate_from_term( $term );
	}

	/**
	 * 儲存 Partner（含密碼雜湊與明文）
	 *
	 * ⚠️ 這是**密碼寫入的唯一出口**——五條路徑（建立夥伴 / 更新夥伴 / 站長指定密碼 /
	 * 重新產生密碼 / 夥伴自助改密碼）全部經過這裡，所以雜湊與明文只要在這一處同步寫入，
	 * 五條路徑就不可能分家。新增密碼寫入路徑時**必須**走這個方法，不可自行 update_term_meta。
	 *
	 * `$partner->password` 一律被忽略——它是讀取時填的唯讀衍生欄位（見 hydrate_from_term），
	 * 密碼變更一律以第二參數 `$plain_password` 表達。這與 `image_url` / `report_url` 同一條紀律。
	 *
	 * @param PartnerSnapshot $partner        Partner 資訊（其 `password` 欄位會被忽略）
	 * @param string|null     $plain_password 明文密碼（null 代表不變更）
	 *
	 * @return int term ID
	 *
	 * @throws PersistenceFailure 當 wp_insert_term / wp_update_term 失敗時拋出
	 */
	public function save( PartnerSnapshot $partner, ?string $plain_password = null ): int {
		if ( 0 === $partner->term_id ) {
			$result = \wp_insert_term(
				$partner->name,
				TaxonomyRegistrar::TAXONOMY,
				[ 'slug' => $partner->slug->value() ]
			);
		} else {
			$result = \wp_update_term(
				$partner->term_id,
				TaxonomyRegistrar::TAXONOMY,
				[
					'name' => $partner->name,
					'slug' => $partner->slug->value(),
				]
			);
		}

		if ( \is_wp_error( $result ) ) {
			throw new PersistenceFailure(
				'儲存分潤夥伴失敗：' . $result->get_error_message()
			);
		}

		$term_id = (int) ( $result['term_id'] ?? 0 );
		if ( $term_id <= 0 ) {
			throw new PersistenceFailure( '儲存分潤夥伴失敗：未取得有效 term id' );
		}

		// 寫入 contact_email（null 視為清空）。
		if ( null === $partner->contact_email ) {
			\delete_term_meta( $term_id, self::META_CONTACT_EMAIL );
		} else {
			\update_term_meta( $term_id, self::META_CONTACT_EMAIL, $partner->contact_email );
		}

		// 寫入 image_id（null / <= 0 視為清空）。
		// 注意：**只寫 image_id，不寫 image_url**——URL 為衍生顯示欄位，
		// 站台換網域 / 換 CDN 時會失效，attachment ID 才是穩定的真相來源。
		if ( null === $partner->image_id || $partner->image_id <= 0 ) {
			\delete_term_meta( $term_id, self::META_IMAGE_ID );
		} else {
			\update_term_meta( $term_id, self::META_IMAGE_ID, $partner->image_id );
		}

		// 僅在 plain_password 非 null 時更新密碼雜湊、明文與變更時間。
		//
		// 雜湊與明文**必須成套寫入**：只寫其中一邊會讓「後台顯示的密碼」與「實際能登入的密碼」
		// 分家，而症狀是站長把後台看到的密碼給了夥伴、夥伴卻登不進去，兩邊都沒有任何錯誤訊息。
		if ( null !== $plain_password ) {
			\update_term_meta( $term_id, self::META_PASSWORD, \wp_hash_password( $plain_password ) );

			// ⚠️ 明文**必須** wp_slash() 後再寫入：`update_metadata()` 內部會對值跑一次
			// `wp_unslash()`（它預期收到的是 slashed 資料）。不先 slash 的話，密碼裡的
			// `\` / `'` / `"` 會被**靜默吃掉**，而雜湊是用未經處理的原始明文算的——
			// 結果就是後台顯示的密碼比實際密碼少了幾個字元，站長照著複製給夥伴卻登不進去。
			// 雜湊那一行不需要 slash，因為 wp_hash_password() 的輸出字元集不含這些字元。
			\update_term_meta( $term_id, self::META_PASSWORD_PLAIN, \wp_slash( $plain_password ) );

			\update_term_meta(
				$term_id,
				self::META_PASSWORD_CHANGED_AT,
				$this->next_password_changed_at( $term_id )
			);
		}

		return $term_id;
	}

	/**
	 * 算出下一個 `password_changed_at`，**保證嚴格大於前一次**
	 *
	 * ── 🔴 為何不能直接寫 `time()` ────────────────────────────
	 *
	 * 這個值唯一的用途是讓 `PartnerTokenStore::verify()` 判斷
	 * 「token 簽發之後，密碼有沒有被改過」。`time()` 只到**秒**，於是
	 * 「同一秒內改密」寫回去的值與改密前**完全相同** ⇒ 不論 verify 端是
	 * 比較時間戳還是比對值快照，都**看不出密碼變過**，舊 token 續用。
	 *
	 * 實測（2026-08-07）：`test_old_token_revoked_after_self_change_password` 與
	 * `test_old_token_is_revoked_after_admin_regenerates_password` 兩條會出現
	 * 「預期 401 實得 200」——舊 token 在改密後**仍然有效**。
	 *
	 * Phase 6-A1 reviewer M-1 當時的處置是把 verify 的判斷從 `<` 收緊成 `<=`
	 * （同秒一律視為已撤銷）。方向對，但那是在**消費端**補救資料本身的精度不足，
	 * 代價是把「密碼寫入後同秒簽發的**合法** token」一起誤殺——而
	 * `save()` 在**建立夥伴**時就會寫這個值，於是「建立夥伴 → 同秒登入」
	 * 這個再正常不過的組合會讓 token 一出生就失效
	 * （11 條 partner 認證整合測試長期紅燈的唯一成因）。
	 *
	 * 正解是在**產生端**保證這個值能履行它的契約：**每次密碼寫入都必須產生
	 * 一個嚴格大於前值的數**。同秒改密時退而取 `prev + 1`，
	 * 值會比真實時間略微超前（僅在同秒連續改密時發生，且誤差是秒級），
	 * 對它唯一的用途（撤銷判斷）完全無害。
	 *
	 * 這樣一來兩條驗證路徑同時成立，不需要任何一端做補償：
	 *   - 快照值比對（現行）：值必變 → 快照對不上 → 撤銷 ✅
	 *   - 時間比較（legacy token 的 fallback）：changed_at 變大 → 撤銷 ✅
	 * 而「同秒簽發的合法 token」因為 `password_changed_at` 不再與它相等，
	 * 也不會再被誤殺。
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return int 嚴格大於前值的 unix timestamp
	 */
	private function next_password_changed_at( int $term_id ): int {
		$now  = time();
		$prev = (int) \get_term_meta( $term_id, self::META_PASSWORD_CHANGED_AT, true );

		return $now > $prev ? $now : $prev + 1;
	}

	/**
	 * 檢查 Partner 是否還掛在任何賣場上
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return bool 仍被綁定回傳 true
	 */
	public function is_in_use( int $term_id ): bool {
		// 注意：post_status 明確只查 publish / draft，**不含 trash**——對應 OQ-2：
		// 已被丟到 trash 的賣場視為不再使用 partner，允許 admin 刪除 partner。
		// 雖然 WP_Query 預設不查 trash，這裡顯式列出以避免未來預設值改變。
		$query = new \WP_Query(
			[
				'post_type'      => \J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\CptRegistrar::POST_TYPE,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDB.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => '_profit_partner_term_id',
						'value'   => $term_id,
						'compare' => '=',
					],
				],
			]
		);

		return ! empty( $query->posts );
	}

	/**
	 * 驗證 Partner 密碼
	 *
	 * @param int    $term_id        Partner term ID
	 * @param string $plain_password 明文密碼
	 *
	 * @return bool 密碼正確回傳 true
	 */
	public function verify_password( int $term_id, string $plain_password ): bool {
		$hash = (string) \get_term_meta( $term_id, self::META_PASSWORD, true );
		if ( '' === $hash ) {
			return false;
		}

		return \wp_check_password( $plain_password, $hash );
	}

	/**
	 * 刪除 Partner（含 termmeta cleanup）
	 *
	 * 注意：wp_delete_term() 會連帶刪除該 term 的所有 termmeta（WP 內建行為），
	 * 因此不需手動清理 _partner_password / _partner_contact_email 等 meta。
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @throws PersistenceFailure 當 wp_delete_term 失敗或 term 不存在時拋出
	 *
	 * @return void
	 */
	public function delete( int $term_id ): void {
		$result = \wp_delete_term( $term_id, TaxonomyRegistrar::TAXONOMY );

		if ( \is_wp_error( $result ) ) {
			throw new PersistenceFailure(
				"刪除分潤夥伴 term {$term_id} 失敗：" . $result->get_error_message()
			);
		}

		if ( false === $result || 0 === $result ) {
			throw new PersistenceFailure( "分潤夥伴 term {$term_id} 不存在或刪除失敗" );
		}
	}

	/**
	 * 列出全部 Partner（id 升冪排序）
	 *
	 * @return PartnerSnapshot[]
	 */
	public function all(): array {
		$terms = \get_terms(
			[
				'taxonomy'   => TaxonomyRegistrar::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			]
		);

		if ( \is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$partners = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$snapshot = $this->hydrate_from_term( $term );
			if ( null !== $snapshot ) {
				$partners[] = $snapshot;
			}
		}

		return $partners;
	}

	/**
	 * 取得 Partner 密碼最後變更時間（unix timestamp）
	 *
	 * 對應 spec §6.3 password rotation token revocation：
	 * Phase 3-D Task T-2 將以此 timestamp 與 token issued_at 比對，
	 * 一旦密碼被變更，舊 token（issued_at < password_changed_at）即可被識別為失效。
	 *
	 * 安全規範：空字串 / null / false 一律回 null，**不可回 0**。
	 * 否則 caller 用 (int) cast 會把空字串變 0，0 又是 epoch 起點，
	 * 會讓「token issued_at >= password_changed_at」永遠成立，等於撤銷邏輯失效。
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return int|null 從未變更或 partner 不存在回 null
	 */
	public function get_password_changed_at( int $term_id ): ?int {
		$raw = \get_term_meta( $term_id, self::META_PASSWORD_CHANGED_AT, true );

		// get_term_meta 在「meta 不存在」時回空字串 ''；保險起見 null / false 也視為未設定。
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}

		$ts = (int) $raw;
		return $ts > 0 ? $ts : null;
	}

	/**
	 * 從 WP_Term + termmeta 重建 PartnerSnapshot
	 *
	 * @param \WP_Term $term 來源 term
	 *
	 * @return PartnerSnapshot|null 當 slug 不合法時回傳 null
	 */
	private function hydrate_from_term( \WP_Term $term ): ?PartnerSnapshot {
		try {
			$slug = new PartnerSlug( $term->slug );
		} catch ( \Throwable $e ) {
			// slug 不合法（例如歷史資料含非法字元）—— 回 null 由 caller 決定處理方式。
			return null;
		}

		$contact_email_raw = (string) \get_term_meta( $term->term_id, self::META_CONTACT_EMAIL, true );
		$contact_email     = '' === $contact_email_raw ? null : $contact_email_raw;

		$image_id  = (int) \get_term_meta( $term->term_id, self::META_IMAGE_ID, true );
		$image_id  = $image_id > 0 ? $image_id : null;
		$image_url = null === $image_id ? null : $this->resolve_image_url( $image_id );

		// 明文密碼供站長在後台查看 / 複製。'' 一律正規化為 null——代表這位夥伴是在
		// 明文功能上線**之前**建立的（只有雜湊、沒有明文），UI 需據實顯示「無法顯示」
		// 而不是顯示一個空密碼讓站長誤以為密碼是空的。
		$password_raw = (string) \get_term_meta( $term->term_id, self::META_PASSWORD_PLAIN, true );
		$password     = '' === $password_raw ? null : $password_raw;

		return new PartnerSnapshot(
			term_id: (int) $term->term_id,
			name: (string) $term->name,
			slug: $slug,
			contact_email: $contact_email,
			image_id: $image_id,
			image_url: $image_url,
			report_url: $this->resolve_report_url( $slug ),
			password: $password
		);
	}

	/**
	 * 組出夥伴報表頁網址（`/{report_slug}/{partner-slug}/`）
	 *
	 * 前綴一律取自 `RewriteRules::get_report_slug()`——那是 rewrite rule 實際註冊時用的
	 * 同一個來源。**不要** hardcode `'profit-report'`：雖然它目前是固定常數
	 * （`DEFAULT_REPORT_SLUG`，站長已無法修改），但一旦有人改動那個常數，寫死在這裡的
	 * 網址會安靜地連到 404（頁面正常載入、只是找不到報表），沒有任何錯誤訊息可循。
	 *
	 * 回傳型別保留 nullable 是為了與 `image_url` 對齊（兩者都是唯讀衍生欄位，
	 * caller 一律要處理 null）；實務上 slug 已由 `PartnerSlug` VO 保證非空，
	 * 因此目前不會回 null。
	 *
	 * @param PartnerSlug $slug 已驗證的 Partner slug
	 *
	 * @return string|null 報表頁完整網址
	 */
	private function resolve_report_url( PartnerSlug $slug ): ?string {
		$report_slug = RewriteRules::get_report_slug();

		return \home_url( '/' . $report_slug . '/' . $slug->value() . '/' );
	}

	/**
	 * 將 attachment ID 解析為圖片 URL
	 *
	 * 當 attachment 已被刪除（或不是圖片）時回 null——**不清空 termmeta**：
	 * 保留 image_id 可讓站長從媒體庫救回檔案後圖片自動復原，
	 * 也避免讀取路徑產生非預期的寫入副作用。
	 *
	 * @param int $image_id Attachment ID
	 *
	 * @return string|null 解析失敗回 null
	 */
	private function resolve_image_url( int $image_id ): ?string {
		$url = \wp_get_attachment_image_url( $image_id, self::IMAGE_SIZE );

		return is_string( $url ) && '' !== $url ? $url : null;
	}
}
