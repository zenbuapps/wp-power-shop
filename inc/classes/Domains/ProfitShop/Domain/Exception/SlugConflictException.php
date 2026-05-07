<?php
/**
 * Slug 衝突例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\SlugConflict;

/**
 * 當賣場 slug 與既有 ProfitShop / WP page / WC product 衝突時拋出
 *
 * 攜帶 SlugConflict[] 讓前端能精確顯示衝突來源（kind / id / label）。
 */
final class SlugConflictException extends \DomainException {

	/**
	 * 衝突清單
	 *
	 * @var SlugConflict[]
	 */
	private array $conflicts;

	/**
	 * 建構子
	 *
	 * @param SlugConflict[] $conflicts 衝突清單（至少 1 筆）
	 * @param string         $message   例外訊息（預設為固定字樣 'SLUG_CONFLICT'）
	 */
	public function __construct( array $conflicts, string $message = 'SLUG_CONFLICT' ) {
		parent::__construct( $message );
		$this->conflicts = $conflicts;
	}

	/**
	 * 取得衝突清單
	 *
	 * @return SlugConflict[]
	 */
	public function getConflicts(): array {
		return $this->conflicts;
	}
}
