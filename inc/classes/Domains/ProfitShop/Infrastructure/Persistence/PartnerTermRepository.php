<?php
/**
 * 分潤夥伴 Repository（Term + termmeta 實作）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Infrastructure\Persistence;

use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Snapshot\PartnerSnapshot;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\PartnerSlug;
use J7\PowerShop\Domains\ProfitShop\Infrastructure\WordPress\TaxonomyRegistrar;

/**
 * 以 profit_partner taxonomy term + termmeta 持久化分潤夥伴
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §2.4、§6.3、§6.4
 *
 * termmeta 欄位（spec §2.4）：
 * - _partner_password（wp_hash_password 雜湊）
 * - _partner_contact_email
 * - _partner_password_changed_at（unix timestamp）
 */
final class PartnerTermRepository implements PartnerRepositoryInterface {

	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Term meta keys
	 */
	private const META_PASSWORD            = '_partner_password';
	private const META_CONTACT_EMAIL       = '_partner_contact_email';
	private const META_PASSWORD_CHANGED_AT = '_partner_password_changed_at';

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
	 * 儲存 Partner（含密碼雜湊）
	 *
	 * @param PartnerSnapshot $partner        Partner 資訊
	 * @param string|null     $plain_password 明文密碼（null 代表不變更）
	 *
	 * @return int term ID
	 *
	 * @throws \RuntimeException 當 wp_insert_term / wp_update_term 失敗時拋出
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
			throw new \RuntimeException(
				'儲存分潤夥伴失敗：' . $result->get_error_message()
			);
		}

		$term_id = (int) ( $result['term_id'] ?? 0 );
		if ( $term_id <= 0 ) {
			throw new \RuntimeException( '儲存分潤夥伴失敗：未取得有效 term id' );
		}

		// 寫入 contact_email（null 視為清空）。
		if ( null === $partner->contact_email ) {
			\delete_term_meta( $term_id, self::META_CONTACT_EMAIL );
		} else {
			\update_term_meta( $term_id, self::META_CONTACT_EMAIL, $partner->contact_email );
		}

		// 僅在 plain_password 非 null 時更新密碼雜湊與變更時間。
		if ( null !== $plain_password ) {
			\update_term_meta( $term_id, self::META_PASSWORD, \wp_hash_password( $plain_password ) );
			\update_term_meta( $term_id, self::META_PASSWORD_CHANGED_AT, time() );
		}

		return $term_id;
	}

	/**
	 * 檢查 Partner 是否還掛在任何賣場上
	 *
	 * @param int $term_id Partner term ID
	 *
	 * @return bool 仍被綁定回傳 true
	 */
	public function is_in_use( int $term_id ): bool {
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

		return new PartnerSnapshot(
			term_id: (int) $term->term_id,
			name: (string) $term->name,
			slug: $slug,
			contact_email: $contact_email
		);
	}
}
