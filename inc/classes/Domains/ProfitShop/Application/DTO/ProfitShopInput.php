<?php
/**
 * 分潤賣場輸入 DTO（Admin Create / Update / Partner Update）
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\DTO;

/**
 * 分潤賣場寫入 DTO
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §3 / §6
 *
 * 對齊 OpenAPI 的 ProfitShopInput schema：
 * id (?int) / title / slug / status / mode / partner_term_id / rate / items / settings
 *
 * 注意：DTO 不做業務驗證，驗證留給 UseCase / Service。
 */
final class ProfitShopInput {

	/**
	 * 建構子
	 *
	 * @param int|null                         $id              賣場 ID（建立時為 null）
	 * @param string                           $title           賣場標題
	 * @param string                           $slug            賣場 slug
	 * @param string                           $status          賣場狀態（publish/draft）
	 * @param string                           $mode            賣場模式（page/shortcode）
	 * @param int                              $partner_term_id 對應 Partner term ID
	 * @param int                              $rate            分潤比例（0-100）
	 * @param array<int, array<string, mixed>> $items           商品覆寫項目原始陣列
	 * @param array<string, mixed>             $settings        其它設定
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $title,
		public readonly string $slug,
		public readonly string $status,
		public readonly string $mode,
		public readonly int $partner_term_id,
		public readonly int $rate,
		public readonly array $items,
		public readonly array $settings
	) {}

	/**
	 * 從原始陣列建立 DTO
	 *
	 * @param array<string, mixed> $data 原始輸入陣列
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			id: isset( $data['id'] ) ? (int) $data['id'] : null,
			title: (string) ( $data['title'] ?? '' ),
			slug: (string) ( $data['slug'] ?? '' ),
			status: (string) ( $data['status'] ?? 'draft' ),
			mode: (string) ( $data['mode'] ?? 'page' ),
			partner_term_id: (int) ( $data['partner_term_id'] ?? 0 ),
			rate: (int) ( $data['rate'] ?? 0 ),
			items: (array) ( $data['items'] ?? [] ),
			settings: (array) ( $data['settings'] ?? [] ),
		);
	}

	/**
	 * 序列化為陣列（與 from_array 對稱）
	 *
	 * @return array{
	 *   id: int|null,
	 *   title: string,
	 *   slug: string,
	 *   status: string,
	 *   mode: string,
	 *   partner_term_id: int,
	 *   rate: int,
	 *   items: array<int, array<string, mixed>>,
	 *   settings: array<string, mixed>
	 * }
	 */
	public function to_array(): array {
		return [
			'id'              => $this->id,
			'title'           => $this->title,
			'slug'            => $this->slug,
			'status'          => $this->status,
			'mode'            => $this->mode,
			'partner_term_id' => $this->partner_term_id,
			'rate'            => $this->rate,
			'items'           => $this->items,
			'settings'        => $this->settings,
		];
	}
}
