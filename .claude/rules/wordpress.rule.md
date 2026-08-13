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

> ⚠️ `profit-settings` 三個 endpoint（GET / PUT / POST reset）**已整組移除**。該頁最後只剩
> `rewrite_slug` / `report_slug` 兩個欄位，兩者都是「有消費點，但站長改了只會弄壞東西」——
> 現為 `CptRegistrar::DEFAULT_REWRITE_SLUG` / `RewriteRules::DEFAULT_REPORT_SLUG` 固定常數。
> rewrite rules 的 flush 改為「規則版本比對、`init` 自動觸發」（`RewriteRules::REWRITE_VERSION`），
> 順便修掉「全專案沒有 `register_activation_hook`、新站不 flush 就 404」的既有缺陷。
> 詳見 `profit-shop.rule.md` §7。

Phase 3-C（Partner Auth + Reports，7 個）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| POST | `profit-partners/{id}/regenerate-password` | **admin_only** | 重新產生密碼（12 字元 wp_generate_password；Phase 3-D T-4：加 admin nonce 驗證 + `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`，response token 永不被 cache） |
| POST | `profit-partners/{id}/set-password` | **admin_only** | **站長指定**密碼（與上一條並存，用途不同：regenerate 是「夥伴忘記密碼、一鍵重設強密碼」，本端點是「站長要主動交一組自己知道的密碼給夥伴」）。body `{password}` 走 `read_json_body_for_partner()`（`PARTNER_RAW_FIELDS`，**不過 sanitize**）；強度由 `PartnerPassword` VO 驗（→ 422 weak_password + `data.reasons[]`）；⚠️ response **不回傳密碼**（站長自己輸入的，回傳只是多一個洩漏面）；同樣要 admin nonce + no-store |
| POST | `partner-auth/login` | **public** | Partner 登入；Set-Cookie HttpOnly/Secure/SameSite=Lax/Path=/wp-json/power-shop/ |
| POST | `partner-auth/logout` | **public** | 登出（idempotent，Cookie Max-Age=0） |
| GET | `partner-auth/me` | **partner_token** | 取得當前 partner 資訊 |
| GET | `partner-reports/kpi` | **partner_token** | KPI 報表（partner_term_id 鎖死於 token） |
| GET | `partner-reports/trend` | **partner_token** | 趨勢報表（partner_term_id 鎖死於 token） |
| GET | `partner-reports/settlements` | **partner_token** | 結算列表（partner_term_id 鎖死於 token） |

Phase 3-E（slug 即時驗證，1 個）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| GET | `profit-shops/validate-slug?slug=xxx` | default | slug 即時驗證（前端建立時呼叫，回 `{available, conflicts[]}`；委派 `SlugConflictDetector` spec §6.11 五類衝突） |

Phase 6-A1（Partner 自助修密碼後端，1 個；Phase 6-A2 已完工 partner portal 前端 UI）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| POST | `partner-auth/change-password` | **partner_token** | Partner 自助修密碼。Body：`{current_password, new_password}`（**不過 sanitize_text_field**，保留密碼字面值）；partner_term_id **永不**從 body / query 取，鎖死於 `_partner_term_id`（permission_callback 從 token 解出 + V2Api L-4 fail-fast `<= 0` 拒）。流程：assert_not_blocked → find_by_id → verify current → new PartnerPassword VO → save → reset → audit log（不含明文）。pseudo-slug `pwchange:{id}` 與 login slug 維度**隔離**；弱密碼不污染 rate-limit。Success path：`Set-Cookie profit_partner_token=; Max-Age=0` 強制重新登入 + `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`。失敗對映：401 unauthorized（current 錯）/ 422 weak_password（含 `data.reasons[]`）/ 429 rate_limited（含 Retry-After）。**Phase 6-A2 前端 UI 已實裝**：partner portal 路由 `/change-password`（authenticated only，Dashboard 入口），UI 紀律見 `react.rule.md` §6.6 ChangePassword.tsx HIGH-RISK 8 條。 |

Phase 17（分潤結算，7 個，2026-08-06；後台端點，**七個一律 `manage_options`**——比設計文件 §6.1 原記載的 `manage_woocommerce` 更嚴，理由與依據見 `profit-shop.rule.md` §13.10）：

| Method | 端點 | Permission | 說明 |
|--------|------|------------|------|
| GET | `profit-settlements` | **admin_only** | 結算列表（filter：partner_id / shop_id / status / date range；`per_page` cap 100、`page` cap 10000） |
| GET | `profit-settlements/summary` | **admin_only** | 摘要（10 個欄位：pending/paid/refunded/cancelled 各金額+筆數 + total_sales + total_count），沿用與列表相同的篩選條件 |
| GET | `profit-settlements/order-ids` | **admin_only** | D-6：依 `shop_id` 回傳 order_id 陣列，供訂單列表當 `wc-rest` 的 `include` filter 用（cap 200 筆，`truncated` 誠實回報） |
| POST | `profit-settlements/export` | **admin_only** | CSV 匯出，回 `{filename, content_base64}`（9 欄，公式注入跳脫，內容含 UTF-8 BOM） |
| POST | `profit-settlements/mark-paid` | **admin_only** | 標記已撥款（CAS 併發防護，逐筆回 `{updated_ids, skipped, audit_log_failed_partner_ids, ...}`，不 all-or-nothing）+ `X-WP-Nonce` |
| POST | `profit-settlements/mark-cancelled` | **admin_only** | 標記取消（只允許 `pending` → `cancelled`）+ `X-WP-Nonce` |
| POST | `profit-settlements/reverse-paid` | **admin_only** | 人工沖回（只允許 `paid` → `refunded`）+ `X-WP-Nonce` |

⚠️ 三個 `POST` mutation 端點的權限判斷是 `manage_options` **加上** `X-WP-Nonce`——capability 擋「你是誰」，nonce 擋「這個請求是不是你自己發的」，兩者防的不是同一件事，缺一不可。回應一律 `{code, message, data}`（admin endpoint 慣例，非 partner 裸 payload）。完整資料流、meta 命名對映、三個地基缺陷（F-1/F-2/F-3）、CAS 實作、`wcs_renewal_order_created` 陷阱見 `profit-shop.rule.md` §13「分潤結算全鏈路」。

賣場前台購物車 REST（Phase 11 的 `FrontCartApi`，6 個 endpoint）**已於 Phase 12 整組移除**。
賣場前台不再有任何自訂購物車 API：加購走 WooCommerce 原生表單送出
（`WC_Form_Handler::add_to_cart_action()`），購物車與結帳一律由 WooCommerce 自己的頁面負責。
`AddToCartHook`(5) → `CartPriceOverrideHook`(10/999) 的分潤定價鏈路完全不變——原生表單是
`method="post"`，`profit_shop_id` 正常進 `$_POST`，三道閘門照常驗證，**不需要**任何
`$_POST` 橋接（那是 Phase 11 走 REST JSON body 時才需要的補償手段）。

⚠ 若未來又要為前台補上非同步購物車 API，先讀 `profit-shop.rule.md` §12.8 的
「Phase 11 / Phase 12」兩節：`AddToCartHook` 只從 `$_GET` / `$_POST` 讀 `profit_shop_id`，
JSON body **不會**填 `$_POST`，漏了橋接就是靜默走原價、分潤失效、沒有任何錯誤訊息；
而改用 `add_to_cart()` 第 5 個參數 `$cart_item_data` 傳值會直接跳過全部三道閘門。

（Phase 10 的 admin-ajax `FrontAjax`、Phase 11 的自訂 REST `FrontCartApi`、以及 WC Store API
的 `cart` / `cart/select-shipping-rate` 目前皆無任何消費者，前兩者已刪檔。）

**Profit Shop API 慣例差異**：
- Partner endpoint **不裹** `{code, data}`，body 直接是 payload（spec §4.4）
- partner_token 從 `X-Partner-Token` / `Authorization: Bearer` / `Cookie` 取，驗證後寫入 `$request->_partner_term_id`（內部欄位前綴 `_` 避免被 sanitize）
- Partner Reports callback **永不從 query string 讀 partner_term_id**（防範圍隔離繞過）

**Profit Shop 前台 URL（非 REST，Phase 4-B + 4-C 對比）：**

| URL pattern | Phase | 對象 | 渲染方式 | 說明 |
|-------------|-------|------|----------|------|
| `/profit-report/{slug}/` | 4-B | **partner-facing**（cookie auth） | 獨立 SPA bundle，PHP `PartnerPortalRenderer` 印獨立 HTML 骨架（**不走 theme**） | partner self-service portal（KPI / Trend / Settlements） |
| `/profit-shop/{slug}/` | 4-C1 | **customer-facing**（unauthenticated） | PHP page template + `get_header` / `get_footer`（**走 theme**） | 賣場前台商品列表，給終端買家瀏覽下單 |

兩條 URL 路線**完全不同**，不要混用渲染策略。query var：`profit_partner_report` vs `profit_shop_slug`，由 `RewriteRules` 同檔註冊但互斥。

**AddToCart 整合（Phase 4-C2 起是 WC filter hook；Phase 12 起三條路徑全部走 WooCommerce 原生表單 / query string；Phase 14 起連互動層也改回 PHP 直接印出）：**

加購商品需要讓 `AddToCartHook` 讀到 `profit_shop_id` 才會啟用分潤定價，目前有三條可達路徑，
**三條都是 PHP 直接印出的 HTML（漸進增強），不是「JS 啟用才有、JS 停用要降級」的兩套並存版本**：

| 通道 | 帶法 |
|------|------|
| simple product 卡片 | template 內 `<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">` 放 `<input type="hidden" name="profit_shop_id" value="...">` 與 WC `add-to-cart`。這是卡片**唯一**的動作元素，本身無 JS 即可加購；JS 載入後在卡片層攔截 click 改開 Modal（見下） |
| variable / grouped 卡片 | template 用 `permalink + add_query_arg('profit_shop_id', $shop_id)` 連到單品頁，由 WC 既有變體選購流程帶入 |
| **商品 Modal（`<dialog>`，PHP 直接印出）** | `templates/partials/profit-shop-modal.php` 直接印出 `<form method="post">`（不是動態建立），欄位 `add-to-cart` / `quantity` / `variation_id` / `attribute_*` / `profit_shop_id` 皆由 PHP 印死；`inc/assets/js/profit-shop-front.js`（手寫 vanilla JS，Phase 14）只在使用者選定變體後**重寫** `variation_id` / `attribute_*` 兩個 hidden input 的值。送出交給 `WC_Form_Handler::add_to_cart_action()` 處理，`method="post"` → PHP 正常填 `$_POST`，**不需要**任何橋接手段（Phase 11 走 REST JSON body 時才需要，該通道已刪除） |

後端 `AddToCartHook`（priority 5）只讀 `$_GET + $_POST`（**不讀 `$_COOKIE`**），三道閘門驗證後注入 cart_item_data，再由 Phase 3-D `CartPriceOverrideHook`（priority 10 / 999）簽章與套價。詳細 hook 串聯流程見 `architecture.rule.md`「Add-to-Cart 端到端流程」與「加購通道相容性」表。

---

**Partner Login IP 偵測信任邊界（Phase 3-D T-3 + reviewer M-1）**：
- `LoginRateLimiter` per-IP 維度的 IP 來源為 `$_SERVER['REMOTE_ADDR']`，**不解析 `X-Forwarded-For`**（避免 header 偽造繞鎖）
- 若部署在 reverse proxy 後方（Cloudflare / nginx LB / AWS ALB），`REMOTE_ADDR` 會是 proxy IP 而非真實客戶端 IP，造成同一 proxy 後方所有用戶共享同一 IP 計數
- 解法：使用 `power_shop_partner_login_client_ip` filter hook 注入真實 IP，例如：
  ```php
  add_filter( 'power_shop_partner_login_client_ip', function( $ip ) {
      // 從 CF-Connecting-IP / X-Real-IP 等可信 header 取（需先驗證來源 IP 為 trusted proxy）
      return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
  } );
  ```
- IP 永不明文落地：transient key 與 audit log 都用 `hash_hmac('sha256', $ip, wp_salt('auth'))`

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

---

## 11. 測試環境（wp-env + PHPUnit）

> 測試組織與 Profit Shop 相關測試清單見 `.claude/rules/profit-shop.rule.md` §9。本節只記錄環境設定與執行方式的踩坑（Phase 7 實測整理）。

### 11.1 `.wp-env.json` core 版本必須釘住

最新版 WooCommerce 要求 WordPress ≥ 6.9。若 `.wp-env.json` 的 `core` 欄位沒有釘版本（或釘到不相容的舊版），`wp-env start` 會在啟動時就 `Error: No plugins activated.`——測試環境沒有 WooCommerce，任何 IT（Integration Test）都跑不了。

目前設定：`core: "WordPress/WordPress#6.9.5"`。

⚠ **`wp-env start` 的 exit code 是 0（假成功）**，即使 core / plugin 沒有正確啟用，指令本身仍回報成功。錯誤訊息只會出現在 stdout 中段，**不能只信 exit code**，要看 output 內容確認 plugin 真的 activated。

### 11.2 `tests/bootstrap.php` 的 polyfill 必須掛在 `muplugins_loaded`，不能寫在檔案頂部

WordPress 6.9 的 `wp-includes/abilities-api.php` 已內建 `wp_register_ability()` 等函式。`tests/bootstrap.php` 若在**檔案頂部**（core 尚未載入的時間點）用 `function_exists()` 判斷要不要 polyfill 這些函式，`function_exists()` 此時必為 `false`（因為 core 還沒載入），polyfill 一定會執行——等到檔尾 `require` 把 core 載入後，就會撞上 `Fatal error: Cannot redeclare wp_register_ability()`。

**正確做法**：polyfill 移進掛在 `muplugins_loaded` hook 的函式（例如 `_power_shop_manually_load_plugin()`）內，此時 core 已載入，`function_exists()` 才能正確反映實際狀態。舊版 WordPress（尚未內建 Abilities API）仍會正常補上 polyfill，不影響向後相容。

### 11.3 `composer test` 在 Windows host 上無法執行純 Unit 測試

`phpunit.xml.dist` 的 `bootstrap` 屬性是**全域設定**，`unit` 與 `integration` 兩個 testsuite 共用同一份 bootstrap——這代表**連指定 `--testsuite unit` 也會嘗試啟動 WordPress**，在 Windows host（沒有跑 wp-env 容器）上會直接 Fatal error。

**正確指令**（透過 wp-env 容器跑）：

```bash
./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --colors=never
```

⚠ 用 `./tests/e2e/node_modules/.bin/wp-env`，**不要**用 `npx wp-env`（會抓到已棄用的 shim）。`wp-env` 自身參數（`run` / `--env-cwd`）與容器內要執行的指令之間要用 `--` 分隔。

### 11.4 CI 跑 PHPUnit 全部 suite + vitest；**e2e 仍未納入**

> ⚠️ 本節在 2026-08-12 前寫的是「CI 只跑 integration、`unit` testsuite 從未執行」——那段**已經不成立**（TD-4 已修）。這裡留下這句話是因為專案有多處引用過舊版敘述，看到那個說法時請以本節為準。

```bash
grep -rn "testsuite" .github/workflows/*.yml
# 只剩 3 行說明用註解，實際指令已無任何 --testsuite 指定
```

CI 的三個測試 cycle 現在各跑兩件事：

| 跑什麼 | 指令 | 涵蓋 |
|---|---|---|
| PHPUnit | **不指定 `--testsuite`** ⇒ `phpunit.xml.dist` 宣告的全部 suite | `tests/Unit/**` + `tests/Integration/**`（實測 1017 筆） |
| vitest | `pnpm test:js` | `tests/js/**`（實測 **326 筆 / 19 檔**，2026-08-13 現查） |

兩者的 exit code 以 OR 合併，**vitest 失敗一樣會觸發既有的 Claude 自動修復迴圈**，不是二等公民。

🔴 **不要把 PHPUnit 改回 `--testsuite integration`，也不要寫成 `--testsuite unit,integration`**。列舉 suite 名稱會讓日後新增的第三個 suite 再次被**靜默漏跑**——而「靜默漏跑」正是 TD-4 這個缺口本身（從 Phase 7 潛伏到 2026-08-12）。不指定才會自動納入。

⚠️ **e2e 仍然完全不在 CI**：`tests/e2e/` 有 25 個 spec / 170 tests（實測 `npx playwright test --list`），但全 workflow **零** `playwright test` 呼叫（`pipe.yml` 只有 `npx playwright install`）。其中包含 BUG-A 三層鎖的最外層（`profit-shop-items.spec.ts` 的扁平契約回歸鎖），該層失效不會有任何訊號。見 `specs/tech-debt.md` TD-89。

### 11.5 `@group` 陷阱：每個 `test_*` method 都要各自標 group，class-level 標籤不夠

`phpunit.xml.dist` 只 `<include>` 這 5 個 group：`smoke` / `happy` / `error` / `edge` / `security`。

如果只在 **class-level** docblock 標了領域 group（例如 `@group profit_shop`、`@group application`、`@group usecase`），這些 group **都不在 include 名單內**——PHPUnit 會**靜默跳過**沒有標到 5 個 include group 之一的 method，測試檔案「執行完畢、0 failure」，但實際上一個 method 都沒真的跑，會出現「測試通過」的假象。

**規則**：每個 `test_*` method 的 docblock 都要**各自**帶上 `smoke` / `happy` / `error` / `edge` / `security` 其中至少一個 group，不能只靠 class-level 標籤。

**驗證方式**：跑 `--filter XxxTest --testdox` 列出的筆數，必須等於該檔案內 `test_` 開頭的方法數——數字對不上就代表有 method 被漏標、靜默跳過了。

### 11.6 🔴 同一時間只能有一個 PHPUnit process：多 agent 協作的排程紀律

`specs/tech-debt.md` **TD-71** 記載了「兩個 process 同時跑會污染 DB」這個現象與判讀紀律。本節補的是**排程層面**——TD-71 沒有涵蓋的那個角度：**這種衝突最常見的來源不是巧合，是 orchestrator 自己安排出來的**。

2026-08-12 實測，同一份程式碼、同一組測試：

| 情境 | 結果 |
|---|---|
| 與另一個 agent 併行跑 | `Tests: 1036, Errors: 3, Failures: 11, Skipped: 1` |
| 獨占跑 | `Tests: 1036, Assertions: 3757, Skipped: 1`（**0F 0E**） |

唯一差異是有沒有第二個 process。當時 `inc/` 的 `git diff` 完全空白（零 production 改動）。

**成因**：orchestrator 一邊要跑全套驗收，一邊派 sub-agent 去「跑完整 integration suite 驗證新測試」——兩件事都對，湊在一起就撞。wp-env 只有一個 MySQL 容器，PHPUnit 沒有跨 process 隔離機制。

🔴 **這個症狀特別惡毒**：它**完全長得像「某人剛改壞了 11 個東西」**。若不先做鑑別實驗就開始「修」，每一次修都會在正確的程式碼上留下傷口。

**紀律（三條，缺一不可）**：

1. **派出「會跑 PHPUnit」的 sub-agent 時，orchestrator 自己不要同時跑**——要驗收就先等它回報完成。反過來也一樣：自己在跑全套時，不要派會跑測試的任務出去。
2. **拿到異常紅燈時，第一個動作是鑑別、不是修**。最快的鑑別實驗是**把失敗的測試檔單獨跑**：單獨綠 ⇒ 是污染或 order-dependent，不是回歸（另見 TD-36 / TD-70：那兩條在**單一 process** 內就會發生，判讀時要與 TD-71 分開）。
3. **看到 `Record has changed since last read in table 'wp_options'` 就直接當成併發訊號**。它出現在 `clean_transients` 的 `DELETE FROM wp_options WHERE option_name LIKE '_transient_%'` 上，是 MySQL 層的併發衝突，與被測程式碼無關。

⚠️ 連帶：多 agent 並行時連「總共有幾個測試」都不是穩定值（另一個 agent 可能正在新增測試檔），`grep -c` 與實跑對不上不必然是 `@group` 漏標——先確認沒有人在寫檔。見 TD-93。
