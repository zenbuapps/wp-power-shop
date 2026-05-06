<?php

declare(strict_types=1);

namespace J7\PowerShop\Domains;

/**
 * Loader 載入每個 Resource API
 * 有要做條件載入可以在這邊做
 */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		Report\Dashboard\Core\V2Api::instance();
		ProfitShop\Loader::instance();
	}
}
