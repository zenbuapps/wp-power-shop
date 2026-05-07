<?php
/**
 * 權限不足例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當當前使用者無權執行此操作時拋出（例如 Partner 試圖編輯非自己的賣場）
 *
 * 統一 extends \DomainException：Application 層可一併 catch \DomainException。
 */
final class Forbidden extends \DomainException {
}
