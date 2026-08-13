<?php
/**
 * 匯入舊版一頁商店為分潤賣場 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopOutput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\ProductLookupInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\Support\LegacyShopTranslator;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\LegacyShopNotImportable;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\PartnerNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\ProfitShopNotFound;
use J7\PowerShop\Domains\ProfitShop\Domain\Exception\SlugConflictException;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\PartnerRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\Repository\ProfitShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;

/**
 * 匯入舊版一頁商店為分潤賣場 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 業務規則（OQ-4）：
 *   舊版「一頁商店」沒有 partner 概念，匯入時必須由 admin 指定 partner_term_id；
 *   缺 partner_term_id → 拋 LegacyShopNotImportable( reason='partner_term_missing' )
 *
 * 匯入後的賣場：
 * - mode 預設 page
 * - status 預設 draft（避免立即上線）
 * - title / slug 沿用原資料
 * - **商品與前台設定經 LegacyShopTranslator 轉換帶入**
 * - 不刪除舊版資料（只新增）
 *
 * ## 🔴 商品必須被搬過去
 *
 * 早期實作把 `items` 寫死成空陣列、把整包 legacy raw post_meta 塞進 `settings`，
 * 導致匯入後的賣場前台顯示「此賣場暫無商品」，而站長完全沒有收到任何錯誤訊息。
 * 商品清單存在 legacy 的 `power_shop_meta`，欄位與新版幾乎一對一
 * （見 `LegacyShopTranslator`），必須解析後帶入。
 *
 * ## slug 衝突比照 CreateShop，一律拋例外而非自動改名
 *
 * `ListImportableLegacyShops` 會事先把「slug 已被佔用」標成不可匯入，讓站長在列表上就
 * 看得到原因；這裡的檢查是**第二道防線**，擋的是「站長開著頁面時，別人剛好用掉了同一個
 * slug」這種快取過期情境。刻意不自動加後綴——那會靜默改掉賣場網址，站長不會知道。
 *
 * ## 已刪除的商品直接跳過，不讓整筆匯入失敗
 *
 * legacy 資料可能引用早已被刪掉的商品。匯入是不可逆操作，為了一兩個失效商品就讓整筆
 * 失敗、站長無從補救並不合理；跳過的 product_id 會回報給呼叫端（`skipped_product_ids`），
 * 由 UI 顯性告知。這與專案既有的「降級不阻斷」三層錯誤處理分工一致。
 */
final class ImportLegacyShop {

	/**
	 * 上一次 execute() 跳過的商品 ID（因商品已不存在）
	 *
	 * @var array<int, int>
	 */
	private array $skipped_product_ids = [];

	/**
	 * 建構子
	 *
	 * 後兩個相依刻意可選：既有呼叫端（與大量既有測試）只注入前三個，
	 * 未注入時分別退化為「不檢查 slug 衝突」與「不過濾已刪商品」，行為與改版前一致。
	 *
	 * @param LegacyShopRepositoryInterface      $legacyRepo    Legacy 資料源
	 * @param ProfitShopRepositoryInterface      $shopRepo      Shop Repository
	 * @param PartnerRepositoryInterface         $partnerRepo   Partner Repository
	 * @param SlugConflictDetectorInterface|null $slugDetector  Slug 衝突偵測器
	 * @param ProductLookupInterface|null        $productLookup 商品存在性查找
	 */
	public function __construct(
		private readonly LegacyShopRepositoryInterface $legacyRepo,
		private readonly ProfitShopRepositoryInterface $shopRepo,
		private readonly PartnerRepositoryInterface $partnerRepo,
		private readonly ?SlugConflictDetectorInterface $slugDetector = null,
		private readonly ?ProductLookupInterface $productLookup = null
	) {}

	/**
	 * 執行匯入
	 *
	 * @param int $legacy_id       舊版資料 ID
	 * @param int $partner_term_id 指定的 Partner term ID
	 * @param int $rate            分潤比例（0-100）；legacy 沒有分潤概念，由站長於匯入時指定
	 *
	 * @return ProfitShopOutput 匯入後新建的 ProfitShop
	 *
	 * @throws LegacyShopNotImportable 當 partner_term_id <= 0 或 legacy 不存在
	 * @throws PartnerNotFound         當 partner 不存在
	 * @throws SlugConflictException   當 slug 已被其他資源佔用
	 * @throws ProfitShopNotFound      當寫入後查無資料
	 */
	public function execute( int $legacy_id, int $partner_term_id, int $rate = 0 ): ProfitShopOutput {
		$this->skipped_product_ids = [];

		if ( $partner_term_id <= 0 ) {
			throw new LegacyShopNotImportable( 'partner_term_missing' );
		}

		$legacy = $this->legacyRepo->find( $legacy_id );
		if ( null === $legacy ) {
			throw new LegacyShopNotImportable( 'legacy_not_found' );
		}

		if ( null === $this->partnerRepo->find_by_id( $partner_term_id ) ) {
			throw new PartnerNotFound( "Partner term {$partner_term_id} 不存在" );
		}

		$title = (string) ( $legacy['title'] ?? '舊版匯入賣場' );
		$meta  = is_array( $legacy['meta'] ?? null ) ? $legacy['meta'] : [];

		// slug 推導與 ListImportableLegacyShops 共用同一份實作，見該方法的 docblock。
		$slug = LegacyShopTranslator::resolve_slug( $legacy + [ 'id' => $legacy_id ] );

		if ( null !== $this->slugDetector ) {
			$conflicts = $this->slugDetector->detect( $slug, 'profit_shop_slug' );
			if ( ! empty( $conflicts ) ) {
				throw new SlugConflictException( $conflicts );
			}
		}

		$items = $this->filter_existing_products( LegacyShopTranslator::to_items( $meta ) );

		// 走與 CreateShop 相同的 Hydrator，讓價格 ValueObject 驗證只有一套實作。
		$input = new ProfitShopInput(
			id: null,
			title: $title,
			slug: $slug,
			status: 'draft',
			mode: ShopMode::PAGE->value,
			partner_term_id: $partner_term_id,
			rate: $rate,
			items: $items,
			settings: LegacyShopTranslator::to_settings( $meta )
		);

		$shop = ProfitShopHydrator::from_input( 0, $input );

		$new_id    = $this->shopRepo->save( $shop );
		$persisted = $this->shopRepo->find( $new_id );
		if ( null === $persisted ) {
			throw new ProfitShopNotFound( "匯入後找不到新賣場 ID {$new_id}" );
		}

		return ProfitShopHydrator::to_output( $persisted );
	}

	/**
	 * 取得上一次 execute() 因商品已不存在而跳過的 product_id
	 *
	 * @return array<int, int>
	 */
	public function skipped_product_ids(): array {
		return $this->skipped_product_ids;
	}

	/**
	 * 濾掉指向已不存在商品的項目
	 *
	 * 未注入 ProductLookupInterface 時原樣回傳（不做過濾）。
	 *
	 * @param array<int, array<string, mixed>> $items 轉換後的 items
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_existing_products( array $items ): array {
		if ( null === $this->productLookup ) {
			return $items;
		}

		$kept = [];
		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id > 0 && $this->productLookup->exists( $product_id ) ) {
				$kept[] = $item;
				continue;
			}
			$this->skipped_product_ids[] = $product_id;
		}

		return $kept;
	}
}
