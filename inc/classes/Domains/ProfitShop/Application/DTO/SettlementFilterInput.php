<?php
/**
 * 後台結算查詢過濾條件 DTO
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

use J7\PowerShop\Domains\ProfitShop\Domain\Entity\SettlementRecord;

/**
 * 後台結算頁（`/profit-settlements`）的查詢過濾條件
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3.5、§4.3
 *
 * 與 Domain\Criteria\FilterCriteria 的差異（刻意不共用，兩者服務不同的授權邊界）：
 *
 * | | FilterCriteria | SettlementFilterInput |
 * |---|---|---|
 * | 使用端 | partner-reports/*（partner token） | profit-settlements/*（admin） |
 * | partner 範圍 | **不含 partner 欄位**——鎖死於 token，永不可由外部輸入指定 | `partner_term_ids` 是**正常的篩選條件**（admin 本就能看全部 partner） |
 *
 * 🔴 這兩個型別**絕不可**為了 DRY 合併：一旦 partner-reports 端點改吃本 DTO，
 * 「partner_term_id 鎖死於 token」（`profit-shop.rule.md` §7 核心 invariant）就會出現一個
 * 可從 query string 指定 partner 的欄位，等於把跨 partner 越權的門重新打開。
 *
 * 正規化紀律（全部發生在 `from_array()`，Infrastructure 只需再做白名單防禦）：
 *   - 所有 ID 一律 `intval` 後只留正整數，並去重
 *   - `statuses` 走 `SettlementRecord` 四個常數的白名單，**不合法值直接拋例外**
 *     （不可靜默丟棄——理由見 `normalize_statuses()`）
 *   - `page` cap 至 MAX_PAGE、`per_page` clamp 到 1..MAX_PER_PAGE（對齊 V2Api 既有慣例）
 */
final class SettlementFilterInput {

	/**
	 * 頁碼上限（避免極大 OFFSET 拖垮資料庫）
	 *
	 * 與 V2Api::build_filter_criteria_from_request() 的 10000 對齊。
	 *
	 * @var int
	 */
	public const MAX_PAGE = 10000;

	/**
	 * 每頁筆數上限（對齊 V2Api 既有慣例）
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * 合法的 settlement status 白名單
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = [
		SettlementRecord::STATUS_PENDING,
		SettlementRecord::STATUS_PAID,
		SettlementRecord::STATUS_REFUNDED,
		SettlementRecord::STATUS_CANCELLED,
	];

	/**
	 * 建構子
	 *
	 * 建構子**不做正規化**（保持 readonly DTO 的可預測性），外部輸入一律走 `from_array()`。
	 *
	 * @param int[]    $partner_term_ids Partner term ID 過濾（空陣列 = 不限）
	 * @param int[]    $shop_ids         賣場 ID 過濾（空陣列 = 不限）
	 * @param string[] $statuses         結算狀態過濾（空陣列 = 不限）
	 * @param int|null $date_start       訂單建立起始時間（unix timestamp，null = 不限）
	 * @param int|null $date_end         訂單建立結束時間（unix timestamp，null = 不限）
	 * @param int      $page             分頁頁碼（1-based）
	 * @param int      $per_page         每頁筆數
	 */
	public function __construct(
		public readonly array $partner_term_ids = [],
		public readonly array $shop_ids = [],
		public readonly array $statuses = [],
		public readonly ?int $date_start = null,
		public readonly ?int $date_end = null,
		public readonly int $page = 1,
		public readonly int $per_page = 20
	) {}

	/**
	 * 從原始（未信任）輸入陣列建立 DTO，並完成全部正規化
	 *
	 * 同時接受單數與複數鍵名（`partner_id` / `partner_ids` / `partner_term_id` / `partner_term_ids`），
	 * 因為 spec §4.3 寫的是單數（`partner_id` / `shop_id` / `status`），而實務上前端多選會送複數。
	 * 兩者若同時出現則合併取聯集。
	 *
	 * @param array<string, mixed> $data 原始輸入陣列（query string 或 JSON body）
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$partner_ids = self::normalize_ids(
			[
				$data['partner_term_ids'] ?? null,
				$data['partner_term_id'] ?? null,
				$data['partner_ids'] ?? null,
				$data['partner_id'] ?? null,
			]
		);

		$shop_ids = self::normalize_ids(
			[
				$data['shop_ids'] ?? null,
				$data['shop_id'] ?? null,
			]
		);

		$statuses = self::normalize_statuses(
			[
				$data['statuses'] ?? null,
				$data['status'] ?? null,
			]
		);

		$date_start = self::normalize_timestamp( $data['date_start'] ?? null );
		$date_end   = self::normalize_timestamp( $data['date_end'] ?? null );

		$page     = isset( $data['page'] ) ? max( 1, min( self::MAX_PAGE, (int) $data['page'] ) ) : 1;
		$per_page = isset( $data['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $data['per_page'] ) ) : 20;

		return new self(
			partner_term_ids: $partner_ids,
			shop_ids: $shop_ids,
			statuses: $statuses,
			date_start: $date_start,
			date_end: $date_end,
			page: $page,
			per_page: $per_page,
		);
	}

	/**
	 * 複製一份，只換掉分頁參數（供 CSV 匯出逐頁抓取整個過濾結果使用）
	 *
	 * 分頁參數同樣走 clamp——匯出路徑不得因此繞過 per_page 上限。
	 *
	 * @param int $page     新頁碼
	 * @param int $per_page 新每頁筆數
	 *
	 * @return self
	 */
	public function with_pagination( int $page, int $per_page ): self {
		return new self(
			partner_term_ids: $this->partner_term_ids,
			shop_ids: $this->shop_ids,
			statuses: $this->statuses,
			date_start: $this->date_start,
			date_end: $this->date_end,
			page: max( 1, min( self::MAX_PAGE, $page ) ),
			per_page: max( 1, min( self::MAX_PER_PAGE, $per_page ) ),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * @return array{
	 *   partner_term_ids: int[],
	 *   shop_ids: int[],
	 *   statuses: string[],
	 *   date_start: int|null,
	 *   date_end: int|null,
	 *   page: int,
	 *   per_page: int
	 * }
	 */
	public function to_array(): array {
		return [
			'partner_term_ids' => $this->partner_term_ids,
			'shop_ids'         => $this->shop_ids,
			'statuses'         => $this->statuses,
			'date_start'       => $this->date_start,
			'date_end'         => $this->date_end,
			'page'             => $this->page,
			'per_page'         => $this->per_page,
		];
	}

	/**
	 * 把多個候選來源正規化成去重後的正整數陣列
	 *
	 * 每個來源可以是：陣列、逗號分隔字串、單一純量、或 null。
	 *
	 * @param array<int, mixed> $sources 候選來源清單
	 *
	 * @return int[] 去重後的正整數陣列（保持首次出現順序）
	 */
	private static function normalize_ids( array $sources ): array {
		$out = [];
		foreach ( self::flatten( $sources ) as $raw ) {
			$id = (int) $raw;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * 把多個候選來源正規化成白名單過濾後的 status 陣列
	 *
	 * 🔴 **不認得的 status 一律拋例外，不可靜默丟棄**。
	 *
	 * 丟棄看起來比較寬容，但會製造一個危險的靜默失敗：使用者送
	 * `?status=待撥款`（或任何拼錯 / 被竄改的值）時，過濾條件被清空後這個查詢就
	 * **退化成「不過濾」**，站長看到的是全部品項卻以為自己正在看某個子集——
	 * 在一個決定「要轉多少錢給誰」的頁面上，這比直接報錯危險得多。
	 * 反過來若改成「回 0 筆」，站長會把它讀成「沒有待撥款的」，同樣有害。
	 *
	 * 拋 `\DomainException` 經 `ExceptionMapper` 對映成 400 validation_failed，
	 * 前端會拿到明確訊息而不是一份語意不明的清單。
	 *
	 * @param array<int, mixed> $sources 候選來源清單
	 *
	 * @throws \DomainException 出現不在白名單內的 status 時拋出
	 *
	 * @return string[] 通過白名單的 status（去重，保持首次出現順序）
	 */
	private static function normalize_statuses( array $sources ): array {
		$out = [];
		foreach ( self::flatten( $sources ) as $raw ) {
			if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
				throw new \DomainException( '結算狀態必須是字串' );
			}

			$status = trim( (string) $raw );
			if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
				throw new \DomainException(
					sprintf(
						'不支援的結算狀態「%s」，可用值：%s',
						$status,
						implode( ' / ', self::VALID_STATUSES )
					)
				);
			}

			if ( ! in_array( $status, $out, true ) ) {
				$out[] = $status;
			}
		}
		return $out;
	}

	/**
	 * 把候選來源攤平成純量清單
	 *
	 * 字串一律以逗號拆分（前端常以 `a,b,c` 形式送多選值）；巢狀陣列只攤一層即足夠。
	 *
	 * @param array<int, mixed> $sources 候選來源清單
	 *
	 * @return array<int, mixed> 攤平後的純量清單
	 */
	private static function flatten( array $sources ): array {
		$out = [];
		foreach ( $sources as $source ) {
			if ( null === $source || '' === $source ) {
				continue;
			}
			if ( is_array( $source ) ) {
				foreach ( $source as $item ) {
					if ( is_array( $item ) ) {
						continue;
					}
					$out[] = $item;
				}
				continue;
			}
			if ( is_string( $source ) ) {
				foreach ( explode( ',', $source ) as $piece ) {
					$piece = trim( $piece );
					if ( '' !== $piece ) {
						$out[] = $piece;
					}
				}
				continue;
			}
			$out[] = $source;
		}
		return $out;
	}

	/**
	 * 正規化 unix timestamp（非數字或非正數一律視為未指定）
	 *
	 * @param mixed $raw 原始輸入
	 *
	 * @return int|null
	 */
	private static function normalize_timestamp( mixed $raw ): ?int {
		if ( null === $raw || '' === $raw || is_array( $raw ) ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$ts = (int) $raw;
		return $ts > 0 ? $ts : null;
	}
}
