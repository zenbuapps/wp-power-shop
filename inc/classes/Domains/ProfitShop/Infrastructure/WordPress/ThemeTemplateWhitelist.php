<?php
/**
 * 賣場「佈景主題範本」白名單
 *
 * 站長可在賣場編輯頁挑一個佈景主題範本（頁面 / 文章範本）包住賣場內容。
 * 「下拉列得出什麼」與「renderer 載得動什麼」必須是同一組值，這個類別是那組值的
 * 唯一真相來源——三個消費端（REST 端點、renderer、整合測試）一律委派它。
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress;

/**
 * 佈景主題範本白名單
 *
 * 全部是純函式 static method，不持有狀態、不寫 DB。
 *
 * 🔴 **為什麼要用 `locate_template()` 再過濾一次——這段不可以「順手簡化」掉**
 *
 * 「佈景主題登記了哪些範本」與「那個檔名真的找得到檔案」是**兩個不同的定義域**，
 * 兩者不是包含關係：
 *
 * | | 函式 | 涵蓋範圍 |
 * |---|---|---|
 * | 登記 | `WP_Theme::get_page_templates()` | 掃出來的實體檔案 **＋** 經 `theme_templates` / `theme_{$post_type}_templates` filter 注入的**虛擬**範本（`wp-includes/class-wp-theme.php:1420` / `:1442`，WordPress 7.0.3） |
 * | 載入 | `locate_template()` | **只找**三個實體目錄：stylesheet_path、（子主題時）template_path、`wp-includes/theme-compat/`（`wp-includes/template.php:722-752`，WordPress 7.0.3） |
 *
 * 外掛註冊的虛擬範本因此只會出現在左欄。實測（turbo.local，2026-08-11，
 * 佈景主題 blocksy + Elementor，`wp_get_theme()` 直接呼叫）：
 *
 *     page templates : {"elementor_canvas":"Elementor Canvas","elementor_header_footer":"Elementor Full Width","elementor_theme":"Theme"}
 *     post templates : （同上三筆）
 *     union count    : 3
 *       elementor_canvas         locate_template=(EMPTY)
 *       elementor_header_footer  locate_template=(EMPTY)
 *       elementor_theme          locate_template=(EMPTY)
 *
 * 三個選項**全部**在 `locate_template()` 回空字串——這正是舊實作的缺口：
 * 白名單用左欄、載入用右欄，站長從下拉選了任何一個，`resolve_theme_template()`
 * 拿到空字串就靜默 fallback 回內建版型，**畫面與沒選一模一樣、零錯誤訊息**。
 * （`elementor_theme` 更是連檔案概念都沒有，它只是 Elementor 內部「用主題預設版型」的標記值。）
 *
 * 把過濾收進 `allowed()` 之後，「站長選得到」與「renderer 載得了」在結構上
 * 不可能再分歧——這與 Phase 13 用 `OverridePriceResolver` 取代「靠人維持兩份實作
 * 逐字一致」的紀律是同一種收斂：能讓機器保證的事，不要交給人記住。
 *
 * ⚠️ 這代表在只裝了頁面建構器、佈景主題本身沒有 file-based 範本的站台上，
 *    `allowed()` 會回**空陣列**、下拉是空的。那是**正確**的——列出來也載不動。
 *    要支援建構器的虛擬範本得走完全不同的機制（讓建構器自己接管渲染），
 *    不是把這道過濾拿掉。
 *
 * ⚠️ 刻意**不**做快取：`WP_Theme::get_page_templates()` 每次呼叫都會重跑上述兩個
 *    filter，自行快取會讓「外掛在 request 中途才註冊範本」的情況讀到過期結果。
 */
final class ThemeTemplateWhitelist {

	/**
	 * 「不使用佈景主題範本」的保留值
	 *
	 * WordPress 頁面屬性 metabox 對「預設範本」用的就是這個字面值；
	 * 前端下拉清空時送的則是空字串。兩者都代表「走內建版型」。
	 */
	private const DEFAULT_SLUG = 'default';

	/**
	 * 可供選擇、且**確定載得動**的範本清單
	 *
	 * 只包含 `locate_template()` 真的找得到實體檔案的項目（理由見類別 docblock）。
	 *
	 * @return array<string, string> `[ 範本檔名 => 顯示名稱 ]`
	 */
	public static function allowed(): array {
		$allowed = [];

		foreach ( self::registered() as $slug => $label ) {
			if ( '' === self::locate( (string) $slug ) ) {
				continue;
			}

			$allowed[ (string) $slug ] = (string) $label;
		}

		return $allowed;
	}

	/**
	 * 把站長選的範本檔名解析成實體路徑
	 *
	 * 通過白名單且檔案存在時回實體路徑，其餘一律回空字串（fail-closed）。
	 *
	 * 🔴 **白名單檢查必須在 `locate_template()` 之前**：`settings.pageTemplate` 是
	 *    站長可寫入的自由欄位，而 `locate_template()` 內部是 `file_exists( $path . '/' . $name )`
	 *    的字串串接（`wp-includes/template.php:736`，WordPress 7.0.3），
	 *    `../` 之類的相對路徑會被它解析出來。先比對白名單才是那道守門。
	 *
	 * @param string $slug 站長選擇的範本檔名（可能為空字串或 `default`）
	 *
	 * @return string 範本實體路徑；未使用 / 不合法 / 檔案不存在時為空字串
	 */
	public static function resolve( string $slug ): string {
		if ( '' === $slug || self::DEFAULT_SLUG === $slug ) {
			return '';
		}

		if ( ! isset( self::registered()[ $slug ] ) ) {
			return '';
		}

		return self::locate( $slug );
	}

	/**
	 * 佈景主題登記過的範本（頁面 + 文章的聯集，尚未經 `locate_template()` 過濾）
	 *
	 * 站長要的是「用哪一個版型包住賣場」，那個版型登記給 page 還是 post 對他沒有差別。
	 *
	 * 用 `+` 而非 `array_merge()`：鍵是範本檔名，`+` 保留左側（page）的標籤，
	 * `array_merge()` 對字串鍵則會讓右側覆蓋左側。同一檔名同時登記給兩種 post type 時，
	 * 兩者的顯示名稱通常相同，但保留左側才是穩定、可預期的行為。
	 *
	 * @return array<string, string> `[ 範本檔名 => 顯示名稱 ]`
	 */
	private static function registered(): array {
		$theme = \wp_get_theme();

		return $theme->get_page_templates( null, 'page' )
		+ $theme->get_page_templates( null, 'post' );
	}

	/**
	 * 找出範本檔名對應的實體路徑
	 *
	 * @param string $slug 範本檔名
	 *
	 * @return string 實體路徑；找不到時為空字串
	 */
	private static function locate( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		$path = \locate_template( $slug );

		return \is_string( $path ) ? $path : '';
	}
}
