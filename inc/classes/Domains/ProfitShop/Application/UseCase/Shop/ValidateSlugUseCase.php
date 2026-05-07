<?php
/**
 * 驗證賣場 / Partner slug 是否可用 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\SlugValidationOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;

/**
 * 驗證 slug 可用性 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §6.11
 * 對應端點：GET /power-shop/profit-shops/validate-slug?slug=xxx
 *
 * 流程：
 * 1. 格式守門：空值 / 長度 1~60 / 僅允許 [a-z0-9_-]
 *    違反任一條件即拋 InvalidPartnerSlug（不進 detector，避免無效 slug 觸發 SQL）.
 * 2. 通過格式檢查後，委派 SlugConflictDetector 偵測五類資源衝突.
 * 3. 將結果包入 SlugValidationOutput 回傳.
 */
final class ValidateSlugUseCase {

	/**
	 * Slug 格式檢查 regex（與 PartnerSlug ValueObject 對齊）
	 *
	 * @var string
	 */
	private const SLUG_FORMAT_REGEX = '/^[a-z0-9_-]+$/';

	/**
	 * Slug 最大長度（與 PartnerSlug ValueObject 對齊）
	 *
	 * @var int
	 */
	private const SLUG_MAX_LENGTH = 60;

	/**
	 * 建構子
	 *
	 * @param SlugConflictDetectorInterface $slugDetector Slug 衝突偵測抽象
	 */
	public function __construct(
		private readonly SlugConflictDetectorInterface $slugDetector
	) {}

	/**
	 * 執行 slug 驗證
	 *
	 * @param string $slug 待驗證 slug
	 *
	 * @return SlugValidationOutput
	 *
	 * @throws InvalidPartnerSlug 當 slug 為空、長度超過 60、或含特殊字元時拋出
	 */
	public function execute( string $slug ): SlugValidationOutput {
		if ( '' === $slug ) {
			throw new InvalidPartnerSlug( 'slug 不可為空' );
		}

		if ( strlen( $slug ) > self::SLUG_MAX_LENGTH ) {
			throw new InvalidPartnerSlug(
				sprintf( 'slug 長度超過 %d 字元', self::SLUG_MAX_LENGTH )
			);
		}

		if ( ! preg_match( self::SLUG_FORMAT_REGEX, $slug ) ) {
			throw new InvalidPartnerSlug( 'slug 僅允許小寫字母、數字、底線、連字號' );
		}

		$conflicts = $this->slugDetector->detect( $slug, 'profit_shop_slug' );

		return new SlugValidationOutput(
			available: empty( $conflicts ),
			conflicts: $conflicts,
		);
	}
}
