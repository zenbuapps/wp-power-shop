<?php
/**
 * 舊版賣場無法匯入例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當舊版一頁商店資料無法匯入為 ProfitShop 時拋出
 *
 * 攜帶 reason 字串（例如 'partner_term_missing' / 'invalid_status'）
 * 讓 Application 層轉換為前端可識別的錯誤分類。
 */
final class LegacyShopNotImportable extends \DomainException {

	/**
	 * 無法匯入的原因（machine-readable code）
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * 建構子
	 *
	 * @param string $reason  原因代碼（例如 'partner_term_missing'）
	 * @param string $message 例外訊息（預設為固定字樣 'LEGACY_NOT_IMPORTABLE'）
	 */
	public function __construct( string $reason, string $message = 'LEGACY_NOT_IMPORTABLE' ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * 取得原因代碼
	 *
	 * @return string
	 */
	public function getReason(): string {
		return $this->reason;
	}
}
