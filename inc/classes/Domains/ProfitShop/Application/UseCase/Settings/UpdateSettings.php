<?php
/**
 * 更新全域設定 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Settings;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SettingsDto;
use J7\PowerShop\Domains\ProfitShop\Application\Service\RewriteRulesFlusherInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SettingsRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;

/**
 * 更新全域設定 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.8、§6.5、§6.11
 *
 * 業務規則：
 *  - 寫入前對 rewrite_slug 與 report_slug 各跑一次 SlugConflictDetector::detect()，
 *    命中即拋出 SlugConflictException（避免 admin 把 slug 設成 wp-admin / shop / cart 等綁架既有路由）
 *  - 寫入後一律呼叫 RewriteRulesFlusher::flush_rules_if_needed()，
 *    由 Flusher 內部判斷 slug 是否異動以避免不必要的 flush
 */
final class UpdateSettings {

	/**
	 * 建構子
	 *
	 * @param SettingsRepositoryInterface   $settingsRepo Settings Repository
	 * @param RewriteRulesFlusherInterface  $flusher      Rewrite rules flusher
	 * @param SlugConflictDetectorInterface $slugDetector Slug 衝突偵測器（防 admin 綁架既有路由）
	 */
	public function __construct(
		private readonly SettingsRepositoryInterface $settingsRepo,
		private readonly RewriteRulesFlusherInterface $flusher,
		private readonly SlugConflictDetectorInterface $slugDetector
	) {}

	/**
	 * 執行更新
	 *
	 * @param SettingsDto $dto 待儲存的設定 DTO
	 *
	 * @throws SlugConflictException 當 rewrite_slug 或 report_slug 與既有資源衝突時拋出
	 *
	 * @return SettingsDto 寫入後的設定 DTO
	 */
	public function execute( SettingsDto $dto ): SettingsDto {
		$conflicts = array_merge(
			$this->slugDetector->detect( $dto->rewrite_slug, 'profit_shop_rewrite_slug' ),
			$this->slugDetector->detect( $dto->report_slug, 'profit_shop_report_slug' )
		);

		if ( ! empty( $conflicts ) ) {
			throw new SlugConflictException( $conflicts );
		}

		$this->settingsRepo->save( $dto );
		$this->flusher->flush_rules_if_needed();
		return $this->settingsRepo->get();
	}
}
