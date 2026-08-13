<?php
/**
 * 列出可匯入舊版一頁商店 UseCase
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration;

use J7\PowerShop\Domains\ProfitShop\Application\DTO\ProfitShopInput;
use J7\PowerShop\Domains\ProfitShop\Application\Service\LegacyShopRepositoryInterface;
use J7\PowerShop\Domains\ProfitShop\Application\Service\SlugConflictDetectorInterface;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Migration\Support\LegacyShopTranslator;
use J7\PowerShop\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator;
use J7\PowerShop\Domains\ProfitShop\Domain\ValueObject\ShopMode;

/**
 * 列出可匯入的舊版一頁商店 UseCase
 *
 * 對應規格：specs/2026-05-06-profit-shop-design.md §4.7
 *
 * 每筆輸出的欄位（與前端 `TLegacyShop` 契約一致）：
 *   legacy_id / title / slug / importable / reason / product_count
 *
 * ⚠️ **刻意不回傳 legacy 的原始 post_meta**。早期版本把整包 flat meta 附在回應裡，
 * 其中含 `power_shop_report_password`（舊版報表密碼）與 `_edit_lock` 等 WP 內部欄位——
 * 前端一個都用不到，卻讓這些值出現在 API 回應中。改為只回「商品筆數」這個真正需要的摘要。
 *
 * ## importable 的判斷必須與 ImportLegacyShop 走同一條路
 *
 * 這裡用 `ProfitShopHydrator::from_input()` 做 **dry-run**，而不是自己另寫一套
 * 「大概不會有問題吧」的檢查。理由：實際匯入就是走這個 Hydrator，同一份輸入在這裡建得起來、
 * 在匯入時就一定建得起來。若兩邊各寫一套判斷，遲早出現「列表說可以匯入、按下去卻噴 400」
 * 或反過來「明明能匯入卻被標成不可匯入」——而這種分歧無聲無息，測試也抓不到。
 *
 * 目前 dry-run 能攔到的實際案例：legacy 資料裡 `salesPrice > regularPrice`
 * （`PriceOverride` 會拋 `InvalidPriceOverride`）。
 */
final class ListImportableLegacyShops {

	/**
	 * Slug 衝突偵測時使用的 context
	 */
	private const SLUG_CONTEXT = 'profit_shop_slug';

	/**
	 * 建構子
	 *
	 * `$slugDetector` 刻意可選：未注入時一律視為可匯入（行為與改版前相同），
	 * 讓既有的純 UseCase 單元測試不需要多準備一個替身。
	 *
	 * @param LegacyShopRepositoryInterface      $legacyRepo   Legacy 資料源
	 * @param SlugConflictDetectorInterface|null $slugDetector Slug 衝突偵測器
	 */
	public function __construct(
		private readonly LegacyShopRepositoryInterface $legacyRepo,
		private readonly ?SlugConflictDetectorInterface $slugDetector = null
	) {}

	/**
	 * 執行列表查詢
	 *
	 * @return array<int, array{legacy_id: int, title: string, slug: string, importable: bool, reason: string|null, product_count: int}>
	 */
	public function execute(): array {
		$out = [];
		foreach ( $this->legacyRepo->all() as $legacy ) {
			if ( ! is_array( $legacy ) ) {
				continue;
			}
			$out[] = $this->describe( $legacy );
		}
		return $out;
	}

	/**
	 * 把單筆 legacy 資料轉成列表項
	 *
	 * @param array<string, mixed> $legacy legacy 單筆資料
	 *
	 * @return array{legacy_id: int, title: string, slug: string, importable: bool, reason: string|null, product_count: int}
	 */
	private function describe( array $legacy ): array {
		$legacy_id = isset( $legacy['id'] ) ? (int) $legacy['id'] : 0;
		$slug      = LegacyShopTranslator::resolve_slug( $legacy );
		$meta      = is_array( $legacy['meta'] ?? null ) ? $legacy['meta'] : [];
		$items     = LegacyShopTranslator::to_items( $meta );

		$reason = $this->resolve_blocked_reason( $slug, $items );

		return [
			'legacy_id'     => $legacy_id,
			'title'         => (string) ( $legacy['title'] ?? '' ),
			'slug'          => $slug,
			'importable'    => null === $reason,
			'reason'        => $reason,
			'product_count' => count( $items ),
		];
	}

	/**
	 * 判斷這筆 legacy 不可匯入的原因；可匯入時回 null
	 *
	 * @param string                           $slug  匯入後會使用的 slug
	 * @param array<int, array<string, mixed>> $items 轉換後的商品項目
	 *
	 * @return string|null
	 */
	private function resolve_blocked_reason( string $slug, array $items ): ?string {
		if ( null !== $this->slugDetector ) {
			$conflicts = $this->slugDetector->detect( $slug, self::SLUG_CONTEXT );
			if ( ! empty( $conflicts ) ) {
				$labels = [];
				foreach ( $conflicts as $conflict ) {
					$label = trim( (string) $conflict->conflicting_label );
					if ( '' !== $label ) {
						$labels[] = $label;
					}
				}
				$detail = empty( $labels ) ? '' : '（已被' . implode( '、', array_unique( $labels ) ) . '佔用）';
				return "永久連結「{$slug}」已被使用{$detail}，請先調整舊版賣場的永久連結再匯入";
			}
		}

		// dry-run：用實際匯入會走的 Hydrator 試建一次，攔下價格等 ValueObject 層級的髒資料。
		try {
			ProfitShopHydrator::from_input(
				0,
				new ProfitShopInput(
					id: null,
					title: '',
					slug: $slug,
					status: 'draft',
					mode: ShopMode::PAGE->value,
					// dry-run 不寫入任何東西，這兩個值僅為滿足 DTO 形狀。
					partner_term_id: 1,
					rate: 0,
					items: $items,
					settings: []
				)
			);
		} catch ( \DomainException $e ) {
			return '舊版商品資料無法轉換：' . $e->getMessage();
		}

		return null;
	}
}
