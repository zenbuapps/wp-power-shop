<?php
/**
 * 舊版一頁商店資料 → 新版分潤賣場契約的轉換器
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\Support;

/**
 * 把 legacy 「一頁商店」的 post_meta 轉成新版 ProfitShop 的 items / settings 契約
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 純 PHP，不依賴 WP / WC，可在 tests/Unit 直接測。
 *
 * ## 為什麼需要這個類別
 *
 * 匯入的職責是「把 legacy 的**業務資料**轉成新版的等價表述」，不是把 post_meta 原封搬走。
 * 早期實作把整包 flat post_meta 塞進 `settings`、`items` 寫死空陣列，結果是：
 *   - 商品**一個都沒有被搬過去**（匯入後賣場前台顯示「此賣場暫無商品」）
 *   - `_edit_lock` / `_edit_last` / `power_shop_report_password` 這些 WP 內部欄位與
 *     舊版報表密碼被複製進新賣場的 settings，並經 `GET /profit-shops/{id}` 回傳出去
 *   - 而 `ProfitShopFrontData::settings()` 讀的是 camelCase key，那包 raw meta **零消費者**
 *
 * ## legacy 的資料結構（實測自真實站台）
 *
 * `power_shop_meta`（JSON array，商品清單）：
 * ```json
 * [
 *   { "productId": 83,  "productType": "simple",   "regularPrice": 20, "salesPrice": 18, "extraBuyerCount": 0 },
 *   { "productId": 729, "productType": "variable", "variations": [
 *       { "variationId": 730, "regularPrice": 100, "salesPrice": 80 }
 *   ] }
 * ]
 * ```
 *
 * `power_shop_settings`（JSON object，前台設定）：欄位名與新版
 * `ProfitShopFrontData::settings()` 讀的 key **完全相同**（`btnColor` / `showConfetti` /
 * `showStock` / `showBuyerCount` / `startTime` / `endTime`），因此可直接沿用，
 * 只需過濾白名單以外的鍵。
 *
 * ## 🔴 `salesPrice`／`regularPrice` 為 0 代表「不覆寫」，不是「賣 0 元」
 *
 * 這是本類別最重要的一條規則，寫錯會直接造成金錢損失（商品變成 0 元），
 * 而且 `PriceOverride` **攔不到**（0 是合法的非負數，且 `0 <= regular` 也成立）。
 *
 * legacy 兩端各自獨立地都用 falsy 判斷，交叉印證此語意：
 *   - PHP：`legacy/inc/classes/Cart.php::price_refresh()`
 *     `if (!empty($sales_price)) set_price($sales_price);`
 *     `if (!empty($regular_price) && empty($sales_price)) set_price($regular_price);`
 *     （PHP 的 `empty(0)` 與 `empty('0')` 皆為 true ⇒ `salesPrice=0` 時實收 `regularPrice`）
 *   - React：`legacy/js/.../PowerShopProducts/Cart/index.tsx`
 *     `const price = !!salesPrice ? salesPrice : regularPrice`
 *
 * ⇒ 轉換時一律把 `<= 0` 正規化為 `null`（＝新版「不覆寫該欄位」的語意）。
 * **不要**因為「0 也是數字，直接轉字串比較單純」而改掉這裡。
 */
final class LegacyShopTranslator {

	/**
	 * 舊版商品清單 post_meta key
	 */
	public const LEGACY_ITEMS_META_KEY = 'power_shop_meta';

	/**
	 * 舊版前台設定 post_meta key
	 */
	public const LEGACY_SETTINGS_META_KEY = 'power_shop_settings';

	/**
	 * 前台設定白名單
	 *
	 * 只搬 `ProfitShopFrontData::settings()` 真的會讀的鍵。清單以外的一律丟棄——
	 * 包含 `power_shop_report_password`（舊版報表密碼）與 `_edit_lock` 等 WP 內部欄位，
	 * 它們在新版沒有任何消費者，搬過去只會變成永久躺在 DB 裡、又會被 API 回傳的雜訊。
	 *
	 * @var array<int, string>
	 */
	public const SETTINGS_ALLOWLIST = [
		'btnColor',
		'showConfetti',
		'showStock',
		'showBuyerCount',
		'startTime',
		'endTime',
	];

	/**
	 * 把 legacy flat post_meta 轉成新版 items 契約
	 *
	 * 回傳的是**原始陣列**（不是 Domain 物件），交由 `ProfitShopHydrator::from_input()`
	 * 統一走 ValueObject 驗證——避免這裡出現第二套價格驗證邏輯。
	 *
	 * @param array<string, mixed> $legacy_meta legacy 的 flat post_meta（key => 單值）
	 *
	 * @return array<int, array<string, mixed>> 新版巢狀 items 契約
	 */
	public static function to_items( array $legacy_meta ): array {
		$rows = self::decode_json_list( $legacy_meta[ self::LEGACY_ITEMS_META_KEY ] ?? null );

		$items = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = self::to_positive_int( $row['productId'] ?? null );
			if ( null === $product_id ) {
				continue;
			}

			$items[] = [
				'product_id'     => $product_id,
				'inflated_count' => max( 0, self::to_int( $row['extraBuyerCount'] ?? null ) ),
				'override'       => self::to_override( $row ),
				'variations'     => self::to_variations( $row['variations'] ?? null ),
			];
		}

		return $items;
	}

	/**
	 * 把 legacy flat post_meta 轉成新版 settings
	 *
	 * @param array<string, mixed> $legacy_meta legacy 的 flat post_meta（key => 單值）
	 *
	 * @return array<string, mixed> 只含白名單鍵；legacy 沒設定過時為空陣列
	 */
	public static function to_settings( array $legacy_meta ): array {
		$raw = self::decode_json_map( $legacy_meta[ self::LEGACY_SETTINGS_META_KEY ] ?? null );

		$settings = [];
		foreach ( self::SETTINGS_ALLOWLIST as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$settings[ $key ] = $raw[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * 計算 legacy 資料內的商品筆數（供列表頁預覽用）
	 *
	 * @param array<string, mixed> $legacy_meta legacy 的 flat post_meta
	 *
	 * @return int
	 */
	public static function count_items( array $legacy_meta ): int {
		return count( self::to_items( $legacy_meta ) );
	}

	/**
	 * 推導匯入後要使用的 slug
	 *
	 * 🔴 `ListImportableLegacyShops`（判斷「可不可以匯入」）與 `ImportLegacyShop`
	 * （實際匯入）**必須**呼叫同一個函式取得 slug。兩邊各自推導的話，只要有一邊漏了
	 * 「slug 為空時回退成 legacy-import-{id}」這個分支，就會出現「列表說可以匯入、
	 * 按下去卻 409 slug 衝突」（或反之）的分歧——而分歧正是本功能上一輪出包的根因。
	 *
	 * @param array<string, mixed> $legacy legacy 單筆資料（含 id / slug）
	 *
	 * @return string
	 */
	public static function resolve_slug( array $legacy ): string {
		$slug = (string) ( $legacy['slug'] ?? '' );
		if ( '' !== $slug ) {
			return $slug;
		}

		$id = self::to_positive_int( $legacy['id'] ?? null ) ?? 0;
		return 'legacy-import-' . $id;
	}

	/**
	 * 單筆商品（或變體）的價格覆寫
	 *
	 * @param array<string, mixed> $row legacy 商品 / 變體資料
	 *
	 * @return array{regular_price: string|null, sale_price: string|null, signup_fee: null}
	 */
	private static function to_override( array $row ): array {
		return [
			// 見 class docblock：<= 0 一律視為「不覆寫」，直譯成 '0' 會讓商品變 0 元。
			'regular_price' => self::to_price_string( $row['regularPrice'] ?? null ),
			'sale_price'    => self::to_price_string( $row['salesPrice'] ?? null ),
			// legacy 沒有訂閱註冊費的概念，一律不覆寫。
			'signup_fee'    => null,
		];
	}

	/**
	 * 舊版 variations **陣列**（每項帶 variationId）→ 新版以 variation_id 為鍵的 **map**
	 *
	 * 兩邊的資料形狀不同，這是最容易寫錯的一處：legacy 用
	 * `find($variations, ['variationId' => $id])` 線性查找（見 `legacy/inc/classes/Cart.php`），
	 * 新版則直接以 variation_id 當陣列鍵。
	 *
	 * @param mixed $raw legacy 的 variations 欄位
	 *
	 * @return array<int, array{override: array<string, string|null>, inflated_count: int}>
	 */
	private static function to_variations( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$variations = [];
		foreach ( $raw as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// 正常情況取 variationId；若上游已經是以 id 為鍵的 map，退而用鍵值（防禦性相容）。
			$vid = self::to_positive_int( $row['variationId'] ?? null ) ?? self::to_positive_int( $key );
			if ( null === $vid ) {
				continue;
			}

			$variations[ $vid ] = [
				'override'       => self::to_override( $row ),
				// legacy 的變體沒有灌水人數欄位，一律 0。
				'inflated_count' => max( 0, self::to_int( $row['extraBuyerCount'] ?? null ) ),
			];
		}

		return $variations;
	}

	/**
	 * 價格值 → nullable 十進位字串
	 *
	 * `null` / 空字串 / 非數字 / `<= 0` 一律回 null（＝不覆寫）。
	 *
	 * @param mixed $value 來源值（legacy 可能存成 int / float / 數字字串）
	 *
	 * @return string|null
	 */
	private static function to_price_string( mixed $value ): ?string {
		if ( null === $value || is_bool( $value ) || is_array( $value ) ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		// bccomp 需要字串；float 先經 (string) 轉換可能出現科學記號，用 sprintf 保險。
		$str = is_float( $value ) ? sprintf( '%.4F', $value ) : (string) $value;

		if ( bccomp( $str, '0', 4 ) <= 0 ) {
			return null;
		}

		// 去掉 float 補出來的尾隨 0（'18.0000' → '18'），避免 DB 內出現不必要的長字串。
		if ( str_contains( $str, '.' ) ) {
			$str = rtrim( rtrim( $str, '0' ), '.' );
		}

		return '' === $str ? null : $str;
	}

	/**
	 * 任意值 → int（無法解析時回 0）
	 *
	 * @param mixed $value 來源值
	 *
	 * @return int
	 */
	private static function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * 任意值 → 正整數；非正整數回 null
	 *
	 * @param mixed $value 來源值
	 *
	 * @return int|null
	 */
	private static function to_positive_int( mixed $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/**
	 * JSON 字串（或已解碼的陣列）→ list
	 *
	 * @param mixed $raw 來源值
	 *
	 * @return array<int|string, mixed>
	 */
	private static function decode_json_list( mixed $raw ): array {
		$decoded = self::decode_json( $raw );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * JSON 字串（或已解碼的陣列）→ associative array
	 *
	 * @param mixed $raw 來源值
	 *
	 * @return array<string, mixed>
	 */
	private static function decode_json_map( mixed $raw ): array {
		$decoded = self::decode_json( $raw );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$out = [];
		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * 寬鬆 JSON 解析：字串走 json_decode、已是陣列則原樣回傳
	 *
	 * 舊版 meta 一律是 JSON 字串，但 `get_post_meta()` 在某些情況會回已解序列化的值，
	 * 因此兩種都接受。
	 *
	 * @param mixed $raw 來源值
	 *
	 * @return mixed
	 */
	private static function decode_json( mixed $raw ): mixed {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		return json_decode( $raw, true );
	}
}
