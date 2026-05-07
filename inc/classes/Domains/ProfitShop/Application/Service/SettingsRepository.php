<?php
/**
 * 全域設定 Repository（wp_options 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\Service;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;

/**
 * Profit Shop 全域設定的 wp_options 實作
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5
 *
 * 與 RewriteRules / CptRegistrar 共用同一個 OPTIONS_KEY = 'power_shop_profit_settings'，
 * 確保 PUT /profit-settings 後，後續 RewriteRules::flush_rules_if_needed() 能讀到最新值。
 */
final class SettingsRepository implements SettingsRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 全站設定的 wp_options key（與 RewriteRules / CptRegistrar 一致）
	 */
	public const OPTIONS_KEY = 'power_shop_profit_settings';

	/**
	 * 取得當前設定
	 *
	 * @return SettingsDto 找不到 / 未設定時回傳預設 DTO
	 */
	public function get(): SettingsDto {
		$raw = \get_option( self::OPTIONS_KEY, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		return SettingsDto::from_array( $raw );
	}

	/**
	 * 儲存設定
	 *
	 * @param SettingsDto $dto 待儲存的設定 DTO
	 *
	 * @return void
	 */
	public function save( SettingsDto $dto ): void {
		\update_option( self::OPTIONS_KEY, $dto->to_array(), false );
	}

	/**
	 * 還原為預設值（直接刪除 option key）
	 *
	 * 後續 get() 找不到 option 時會自動 fallback 到 SettingsDto::from_array( [] )，
	 * 取得 spec 定義的預設值。比起 save( from_array([]) ) 的行為等價但意圖更明確。
	 *
	 * @return void
	 */
	public function reset(): void {
		\delete_option( self::OPTIONS_KEY );
	}
}
