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
| POST | `profit-partners/{id}/regenerate-password` | **admin_only** | 重新產生密碼（12 字元 wp_generate_password；Phase 3-D T-4：加 admin nonce 驗證 + `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`，response token 永不被 cache） |
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

**AddToCart 整合（Phase 4-C2，非 REST endpoint，是 WC filter hook）：**

加購商品需帶 `?profit_shop_id={id}` 才會啟用分潤定價：

| 商品類型 | 帶法 |
|----------|------|
| simple product | template 內 `<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="post">` 放 `<input type="hidden" name="profit_shop_id" value="...">` 與 WC `add-to-cart` |
| variable / grouped | template 用 `permalink + add_query_arg('profit_shop_id', $shop_id)` 連到單品頁，由 WC 既有變體選購流程帶入 |

後端 `AddToCartHook`（priority 5）只讀 `$_GET + $_POST`（**不讀 `$_COOKIE`**），三道閘門驗證後注入 cart_item_data，再由 Phase 3-D `CartPriceOverrideHook`（priority 10 / 999）簽章與套價。詳細 hook 串聯流程見 `architecture.rule.md`「Add-to-Cart 端到端流程」。

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

### 11.4 CI 目前只跑 `integration` testsuite，`unit` testsuite 從未執行

```bash
grep -rn "testsuite" .github/workflows/*.yml
# pipe.yml:681 / :722 / :762 三處全是 --testsuite integration
```

任何只寫在 `tests/Unit/**` 的新測試，**CI 不會執行**，只能靠本機 / wp-env 容器手動跑（見 11.3）驗證。新增 Unit 測試時不要誤以為 CI 綠燈 = 該測試有跑。此項已登記為技術債，見 `specs/tech-debt.md`。

### 11.5 `@group` 陷阱：每個 `test_*` method 都要各自標 group，class-level 標籤不夠

`phpunit.xml.dist` 只 `<include>` 這 5 個 group：`smoke` / `happy` / `error` / `edge` / `security`。

如果只在 **class-level** docblock 標了領域 group（例如 `@group profit_shop`、`@group application`、`@group usecase`），這些 group **都不在 include 名單內**——PHPUnit 會**靜默跳過**沒有標到 5 個 include group 之一的 method，測試檔案「執行完畢、0 failure」，但實際上一個 method 都沒真的跑，會出現「測試通過」的假象。

**規則**：每個 `test_*` method 的 docblock 都要**各自**帶上 `smoke` / `happy` / `error` / `edge` / `security` 其中至少一個 group，不能只靠 class-level 標籤。

**驗證方式**：跑 `--filter XxxTest --testdox` 列出的筆數，必須等於該檔案內 `test_` 開頭的方法數——數字對不上就代表有 method 被漏標、靜默跳過了。
