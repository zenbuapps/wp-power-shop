<?php
/**
 * 分潤夥伴 slug ValueObject
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

use J7\PowerShop\Domains\ProfitShop\Domain\Exception\InvalidPartnerSlug;

/**
 * 分潤夥伴 slug
 *
 * 規範：
 * - 長度 1-60 字元
 * - 僅允許 [a-z0-9_-]
 * - 不得使用保留字（避免與系統路徑衝突）
 */
final class PartnerSlug {

	/**
	 * 保留字清單（不可作為 partner slug）
	 *
	 * @var string[]
	 */
	public const RESERVED_SLUGS = [
		// 系統識別字
		'admin',
		'root',
		'null',
		'undefined',
		'api',
		// 本外掛預留路徑
		'profit-report',
		'profit-shop',
		'powershop',
		// WordPress 核心路徑
		'wp-admin',
		'wp-json',
		'wp-content',
		'wp-includes',
		// WordPress 通用保留字
		'feed',
		'comments',
		'search',
		'author',
		'category',
		'tag',
		'page',
	];

	/**
	 * Slug 字串
	 *
	 * @var string
	 */
	public readonly string $value;

	/**
	 * 建構子
	 *
	 * @param string $value 候選 slug 字串。
	 *
	 * @throws InvalidPartnerSlug 當 slug 不合法（空字串、超長、含非法字元、為保留字）時拋出。
	 */
	public function __construct( string $value ) {
		$length = strlen( $value );
		if ( $length === 0 || $length > 60 ) {
			throw new InvalidPartnerSlug(
				"分潤夥伴 slug 長度必須在 1-60 字元之間，收到長度 {$length}"
			);
		}

		if ( ! preg_match( '/^[a-z0-9_-]+$/', $value ) ) {
			throw new InvalidPartnerSlug(
				"分潤夥伴 slug 僅允許小寫英數字、底線、連字號，收到「{$value}」"
			);
		}

		if ( in_array( $value, self::RESERVED_SLUGS, true ) ) {
			throw new InvalidPartnerSlug(
				"分潤夥伴 slug「{$value}」為保留字，請改用其他 slug"
			);
		}

		$this->value = $value;
	}

	/**
	 * 取得 slug 字串
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}
}
