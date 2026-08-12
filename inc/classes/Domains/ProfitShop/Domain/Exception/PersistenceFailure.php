<?php
/**
 * Repository 持久化失敗例外
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\Exception;

/**
 * 當 Repository 寫入後端儲存失敗時拋出（DB error、term 建立失敗、wp_insert_post 失敗等）
 *
 * 統一 extends \DomainException：Application 層可一併 catch \DomainException
 * 處理所有 Domain 例外，無需另外掛 \RuntimeException 分支。
 */
final class PersistenceFailure extends \DomainException {
}
