<?php
/**
 * 分潤賣場模式列舉
 */

declare(strict_types=1);

namespace J7\PowerShop\Domains\ProfitShop\Domain\ValueObject;

/**
 * 分潤賣場呈現模式
 *
 * - PAGE：以獨立分潤賣場頁面呈現
 * - SHORTCODE：以 shortcode 嵌入既有頁面
 */
enum ShopMode: string {
	case PAGE      = 'page';
	case SHORTCODE = 'shortcode';
}
