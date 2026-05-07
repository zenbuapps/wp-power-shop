---
applyTo: "**/*.php"
---

# Power Shop — WordPress / PHP 開發指引

> 適用於所有 `.php` 檔案。架構概覽見 `architecture.rule.md`。

---

## 1. 環境需求

| 需求 | 最低版本 |
|------|----------|
| PHP | 8.0+ |
| WordPress | 5.7+ |
| WooCommerce | 7.6.0+ |

---

## 2. 強制命名慣例

1. **`declare(strict_types=1);`** — 每個 PHP 檔案頂部，無例外。
2. **Namespace** — 所有 class 在 `J7\PowerShop\<Domain>` 下（PSR-4，映射自 `inc/classes/`）。
3. **`final class`** — 所有具體 class；`abstract class` 用於基礎工具類。
4. **`SingletonTrait`** — 所有被實例化的 class 使用 `\J7\WpUtils\Traits\SingletonTrait`，透過 `ClassName::instance()` 呼叫。
5. **方法/函式註解使用繁體中文**。
6. **命名規範：**
   - `snake_case` — 變數、方法、函式
   - `PascalCase` — class 名稱
   - `UPPER_SNAKE_CASE` — 常數
7. **Hook 註冊** — 使用 `\add_action()` / `\add_filter()` 搭配完全限定的函式參考，偏好 `[ __CLASS__, 'method' ]`。

---

## 3. REST API 開發

### 現有端點速查

**自有端點（`/wp-json/power-shop/`）：**

| Method | 端點 | 說明 |
|--------|------|------|
| GET | `reports/dashboard/stats` | KPI 統計 |
| GET | `reports/dashboard/leaderboard` | 排行榜 |
| GET | `reports/dashboard/trend` | 趨勢圖表 |
| GET | `reports/revenue` | 營收分析 |
| POST | `customers/{id}/notes` | 新增顧客備註 |
| GET | `customers/{id}/notes` | 顧客備註列表 |

**Profit Shop 端點（`/wp-json/power-shop/`，namespace 共用，由 `Domains/ProfitShop/Infrastructure/Rest/V2Api.php` 註冊）：**

> 詳細架構與 auth 規範見 `.claude/rules/profit-shop.rule.md`。
> Permission 共三類：`null`（Powerhouse 預設認證 = `manage_woocommerce`）、`admin_only`（`manage_options`）、`partner_token`（X-Partner-Token / Bearer / Cookie）、`public`。

Phase 3-B（Admin CRUD，17 個）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| GET | `profit-shops` | default | 賣場列表 |
| POST | `profit-shops` | default | 建立賣場 |
| GET | `profit-shops/{id}` | default | 取得賣場 |
| PUT | `profit-shops/{id}` | default | 更新賣場 |
| DELETE | `profit-shops/{id}` | default | 刪除賣場（trash） |
| POST | `profit-shops/{id}/publish` | default | 上架 |
| POST | `profit-shops/{id}/unpublish` | default | 下架 |
| POST | `profit-shops/{id}/duplicate` | default | 複製 |
| GET | `profit-partners` | default | 分潤夥伴列表 |
| POST | `profit-partners` | **admin_only** | 建立夥伴（涉密碼） |
| GET | `profit-partners/{id}` | default | 取得夥伴 |
| PUT | `profit-partners/{id}` | **admin_only** | 更新夥伴（涉密碼） |
| DELETE | `profit-partners/{id}` | **admin_only** | 刪除夥伴（is_in_use 預檢） |
| GET | `profit-migration/legacy-shops` | admin_only | 列舉可遷入的 legacy 一頁商店 |
| POST | `profit-migration/import` | admin_only | 匯入 legacy 商店（強制 partner_term_id） |
| GET | `profit-settings` | admin_only | 取得全域設定 |
| PUT | `profit-settings` | admin_only | 更新設定（含 SlugConflictDetector + flush_rules） |
| POST | `profit-settings/reset` | admin_only | 還原預設值 |

Phase 3-C（Partner Auth + Reports，7 個）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| POST | `profit-partners/{id}/regenerate-password` | **admin_only** | 重新產生密碼（12 字元 wp_generate_password） |
| POST | `partner-auth/login` | **public** | Partner 登入；Set-Cookie HttpOnly/Secure/SameSite=Lax/Path=/wp-json/power-shop/ |
| POST | `partner-auth/logout` | **public** | 登出（idempotent，Cookie Max-Age=0） |
| GET | `partner-auth/me` | **partner_token** | 取得當前 partner 資訊 |
| GET | `partner-reports/kpi` | **partner_token** | KPI 報表（partner_term_id 鎖死於 token） |
| GET | `partner-reports/trend` | **partner_token** | 趨勢報表（partner_term_id 鎖死於 token） |
| GET | `partner-reports/settlements` | **partner_token** | 結算列表（partner_term_id 鎖死於 token） |

**Profit Shop API 慣例差異**：
- Partner endpoint **不裹** `{code, data}`，body 直接是 payload（spec §4.4）
- partner_token 從 `X-Partner-Token` / `Authorization: Bearer` / `Cookie` 取，驗證後寫入 `$request->_partner_term_id`（內部欄位前綴 `_` 避免被 sanitize）
- Partner Reports callback **永不從 query string 讀 partner_term_id**（防範圍隔離繞過）

**WooCommerce 端點（`/wp-json/wc/v3/`）：**

| Method | 端點 | 說明 |
|--------|------|------|
| GET/POST | `orders` | 訂單列表/建立 |
| PUT | `orders/{id}` | 更新訂單 |
| POST | `orders/batch` | 批量刪除 |
| POST | `orders/{id}/notes` | 新增備註 |
| GET/POST | `products` | 商品列表/建立 |
| PUT | `products/{id}` | 更新商品 |
| POST | `products/{id}/variations/batch` | 批量變體 |
| GET/POST | `products/categories` | 分類列表/建立 |
| GET | `customers` | 顧客列表 |
| GET/PUT | `customers/{id}` | 顧客詳情/更新 |

### ApiBase Pattern

所有 REST API class 繼承 `J7\WpUtils\Classes\ApiBase`：

```php
<?php
declare(strict_types=1);

namespace J7\PowerShop\Domains\<Domain>\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;

/**
 * <Domain> Api
 */
final class V2Api extends ApiBase {
    use \J7\WpUtils\Traits\SingletonTrait;

    /** @var string */
    protected $namespace = 'power-shop';

    /**
     * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
     */
    protected $apis = [
        [
            'endpoint'            => '<resource>',
            'method'              => 'get',
            'permission_callback' => null, // null = Powerhouse 預設認證
        ],
    ];

    /**
     * 取得資源列表
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     * @phpstan-ignore-next-line
     */
    public function get_<resource>_callback( $request ): \WP_REST_Response {
        $params = WP::sanitize_text_field_deep( $request->get_query_params(), false );
        // ... 業務邏輯
        return new \WP_REST_Response([ 'code' => 'success', 'data' => [] ]);
    }
}
```

### Callback 命名慣例

`ApiBase` 自動解析 callback 方法名：

- 模式：`{method}_{endpoint_segments_underscored}_callback`
- `/` → `_`
- Regex groups `(?P<id>\d+)` → `with_id`

**範例：** endpoint `products/(?P<id>\d+)` + method `get` → `get_products_with_id_callback`

### Loader 註冊

新的 API class 必須在 `inc/classes/Domains/Loader.php` 中註冊：

```php
public function __construct() {
    Report\Dashboard\Core\V2Api::instance();
    <Domain>\Core\V2Api::instance(); // ← 在此加入
}
```

---

## 4. DTO 模式

```php
final class MyDto {
    public string $name;
    public int $count;

    /** @param array<mixed> $data */
    public function __construct( array $data ) {
        $this->name  = (string) ( $data['name'] ?? '' );
        $this->count = (int) ( $data['count'] ?? 0 );
    }

    /** @return array{name: string, count: int} */
    public function to_array(): array {
        return [ 'name' => $this->name, 'count' => $this->count ];
    }
}
```

在 V2Api callback 中使用 DTO：

```php
$dto = new MyDto( $raw_data );
return new \WP_REST_Response([ 'code' => 'success', 'data' => $dto->to_array() ]);
```

---

## 5. Domain 組織結構

```
inc/classes/Domains/
├── Loader.php                 # 實例化所有 Domain API class
└── <Domain>/
    ├── Core/
    │   └── V2Api.php          # REST API endpoint 定義
    └── DTO/
        └── <Name>.php         # Data Transfer Object
```

---

## 6. Admin 頁面整合

### Entry 模式

`Admin\Entry::instance()` 負責：
- 註冊 admin 頁面（`add_action('admin_menu', ...)`）
- 輸出 `<div id="power_shop"></div>` 掛載點
- Admin bar 整合

### Admin Bar 整合

`Admin\Entry::admin_bar_item()` 依上下文顯示不同連結：
- **商品頁** → 連結至 `#/products/edit/{id}`（標籤：「編輯商品」）
- **其他頁** → 連結至 `#/dashboard`（標籤：「電商系統」）
- 僅對擁有 `manage_woocommerce` capability 的使用者顯示。

### Enqueue Guard

腳本僅在 URL 包含 `page=power-shop` 時載入：

```php
if ( ! General::in_url([ 'page=power-shop' ]) ) {
    return;
}
```

**禁止**移除此 guard — 在所有 admin 頁面載入 React bundle 會造成浪費。

---

## 7. Vite 整合

使用 `kucrut/vite-for-wp` 整合 Vite 打包：

```php
// Bootstrap::enqueue_script()
Vite\enqueue_asset( $plugin_dir . '/js/dist', 'js/src/main.tsx' );
$encrypt_env = PowerhouseUtils::simple_encrypt([
    'SITE_URL' => \untrailingslashit(\site_url()),
    'NONCE'    => \wp_create_nonce('wp_rest'),
    // ...
]);
\wp_localize_script( Plugin::$kebab, Plugin::$snake . '_data', [ 'env' => $encrypt_env ] );
```

---

## 8. 安全性

1. **Nonce 驗證** — 所有 REST 呼叫透過 `X-WP-Nonce` header 驗證。
2. **Capability 檢查** — `permission_callback` 使用 Powerhouse 預設認證（`manage_woocommerce`）。
3. **環境加密** — API key、nonce 等透過 `SimpleEncrypt` 加密傳遞到前端。
4. **輸入清理** — 所有 REST request params 使用 `WP::sanitize_text_field_deep()`。
5. **輸出跳脫** — `\esc_html()`、`\esc_attr()`、`\esc_url()` 用於所有 PHP 輸出。

**新增 REST API 完成清單：**

- [ ] `declare(strict_types=1)` 在檔案頂部
- [ ] `WP::sanitize_text_field_deep()` 處理所有輸入
- [ ] `\esc_html()` / `\esc_attr()` / `\esc_url()` 處理所有輸出
- [ ] `permission_callback` 已設定（`null` = Powerhouse 預設認證）
- [ ] 新 API class 已在 `Domains/Loader.php` 註冊
- [ ] `composer lint` 通過
- [ ] `vendor/bin/phpstan analyse inc` 通過

---

## 9. WooCommerce 相容性

- 使用 WooCommerce REST API v3（`wc/v3/`）存取商品、訂單、顧客資料。
- 支援 WooCommerce Subscriptions（`subscription`、`variable-subscription` 產品類型）。
- REST API namespace `power-shop` 用於自有端點（Dashboard、Analytics、顧客備註）。

---

## 10. 程式碼品質

- **PHPCS** (`phpcs.xml`) — WordPress-Core、WordPress-Docs、WordPress-Extra（排除短陣列、Yoda 條件等）。
- **PHPStan** (`phpstan.neon`) — level 9，包含 WordPress + WooCommerce stubs 及 `powerhouse` plugin stubs。

> Lint 指令見 `CLAUDE.md`。
