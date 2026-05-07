# Power Shop — Profit Shop Domain 開發指引

> 適用於 `inc/classes/Domains/ProfitShop/**` 與相關測試。
> 設計文件：`specs/2026-05-06-profit-shop-design.md`
> 架構與 4-layer 結構圖見 `architecture.rule.md`。
> Endpoint 速查見 `wordpress.rule.md`。
> 一般 PHP / WordPress 規範（`declare(strict_types=1)`、SingletonTrait、WP::sanitize_text_field_deep 等）見 `wordpress.rule.md`。

---

## 1. 4-layer DDD 嚴格分層

```
Presentation (Rest/) → Application (UseCase + Service) → Domain (純 PHP) ← Infrastructure (WP/WC adapter)
```

**強制規則：**

| 層 | 可依賴 | 禁止 |
|----|--------|------|
| Domain | 純 PHP / 同層 Domain | ❌ WP 函式、WC 函式、global、`$wpdb`、`$_*` |
| Application | Domain + Port interface | ❌ 直接 `new` Infrastructure 類；❌ 呼叫 WP / WC |
| Infrastructure | Domain（實作 Repository）、Application（實作 Port）、WP / WC | ❌ 把 WP 物件外洩給 Domain |
| Presentation (Rest/) | Application UseCase + ExceptionMapper | ❌ 跳過 Application 直接呼叫 Repository / WP |

**檢查方式**：Domain 層所有檔案必須能在 `tests/Unit/Domain/**` 不啟 WP 的 PHPUnit 直接跑過。

---

## 2. 例外體系

### Domain Exception 統一規範

- 全部 `final class extends \DomainException`（PHP 內建 SPL 類別）
- 路徑固定 `inc/classes/Domains/ProfitShop/Domain/Exception/`
- 不要 `extends \Exception` / `\RuntimeException`，否則 Application 層的 `catch (\DomainException $e)` 會漏接
- 例外應該是不可變的事實載體：在 constructor 完成所有狀態，不額外提供 setter

目前 18 個 Domain Exception（涵蓋 Phase 1～3-E，3-D/3-E 未新增 Domain Exception）：
```
ValueObject 驗證類:    InvalidPartnerSlug / InvalidPriceOverride / InvalidProfitRate / InvalidShopMode
Entity invariant 類:   InvalidStatusTransition / InvalidVariation
查詢失敗類:           ProfitShopNotFound / PartnerNotFound / ProductNotFound
業務規則類:           ProductAlreadyInShop / ProductNotInShop / PartnerStillInUseException /
                     LegacyShopNotImportable / SlugConflictException
Auth / Rate-limit 類:  InvalidCredentials / TooManyAttempts / RateLimitExceeded / Forbidden
Infrastructure 失敗:   PersistenceFailure
```

### Presentation 邊界統一捕捉

`Infrastructure/Rest/ExceptionMapper.php`：
- 13 種已知 Domain Exception → 明確 HTTP status + error code
- 未列舉的 `\DomainException` → 400 `validation_failed`（fallback）
- 其餘 `\Throwable` → 500 `internal_error` 含 `error_id`，**生產環境遮蔽 message**
- 寫 log 時過濾 `\r \n \t`（log injection 防禦）

V2Api callback 的標準形態：
```php
public function post_partner_auth_login_callback( $request ): \WP_REST_Response {
    try {
        $output = $this->use_case->execute( $input );
        return new \WP_REST_Response( $output->to_array(), 200 );
    } catch ( \DomainException $e ) {
        return ExceptionMapper::to_response( $e );
    }
}
```

---

## 3. Repository Interface vs 實作

- Repository **interface** 在 `Domain/Repository/`（純 PHP，型別只用 Domain Entity / DTO）
- Repository **實作** 在 `Infrastructure/Persistence/`（叫 `CptProfitShopRepository`、`PartnerTermRepository`、`OrderItemSettlementRepository`）
- 反序列化用 Repository **private** method（保持 Domain Entity constructor 純度，不汙染成「會吃 array」的多態 constructor）
- JSON 序列化：呼叫 Domain 層 `to_array()` → `wp_json_encode( $data, JSON_UNESCAPED_UNICODE )`，**不要**在外面再包 `wp_slash`

---

## 4. Application Port 與 DI

Application Service 透過 **interface** 注入 Infrastructure adapter（DIP），**禁止** `object` 鴨子型別：

```php
// ✅ 正確（Phase 3-C 二輪修正後的標準）
public function __construct(
    private readonly TransientStoreInterface $store,
    private readonly ClockInterface $clock,
) {}

// ❌ 錯誤（鴨子型別會迫使 fakes 不能跨專案重用）
public function __construct(
    private readonly object $store,
    private readonly object $clock,
) {}
```

`tests/Support/**` 的 fakes 必須 **`implements`** 對應 production interface（`InMemoryTransientStore implements TransientStoreInterface` 等）。

目前 Port interface 清單：
- `ProductLookupInterface` / `SlugConflictLookupInterface` / `LegacyShopRepositoryInterface`
- `RewriteRulesFlusherInterface` / `SettingsRepositoryInterface`
- `ItemValidatorInterface` / `SlugConflictDetectorInterface`
- `TransientStoreInterface` / `ClockInterface` / `EmailNotifierInterface`
- `SettlementSummaryProviderInterface`
- `SaltProviderInterface`（Phase 3-D，封裝 `wp_salt('auth')`，避免 Application 直呼 WP；context 白名單 `auth` / `logged_in` / `nonce` / `secure_auth`，typo 拋 `InvalidArgumentException` 防 silent salt rotation）

---

## 5. DTO 約定

- 路徑 `Application/DTO/`
- 全部 `final class` + PHP 8.1 **`readonly`** properties
- 必須提供 `from_array()` static factory + `to_array()` instance method（round-trip 對稱）
- `DtoRoundTripTest` 鎖死 round-trip 行為——改 DTO 屬性型別前先看是否會破測試
- **`PartnerInput` 標 `@internal`**，禁止 `to_array()` 序列化到 REST response（會洩 password 明文）；對外用 `PartnerOutput`

---

## 6. Auth 規範

### Admin vs Partner 雙軌制

| 類型 | Permission callback | 認證來源 | Response 包裝 |
|------|---------------------|----------|---------------|
| Admin | `null`（`manage_woocommerce`）/ `admin_only`（`manage_options`） | WP nonce | `{code, data}` |
| Partner | `partner_token` | X-Partner-Token / Authorization Bearer / Cookie | **裸 payload**（不裹 `{code, data}`，spec §4.4） |
| Public | `__return_true` | — | 視 endpoint 而定 |

**禁止互通**：admin nonce 不可用於 partner endpoint，反之亦然（`PartnerVsAdminAuthIsolationTest` 鎖死）。

### Token 安全

- Token 永不存明文：transient key 為 `sha256(token)`，value 含 `partner_term_id` + `expires_at`
- `PartnerTokenStore::issue/verify/revoke` 是唯一管道
- TTL 抽常數 `PARTNER_TOKEN_TTL_SECONDS`（目前 3600）
- Cookie：`HttpOnly` + `Secure` + `SameSite=Lax` + `Path=/wp-json/power-shop/`（常數 `PARTNER_COOKIE_PATH`）

### Rate Limit

- `LoginRateLimiter`：5 次失敗鎖定 + 視窗 + per-slug
- 達到 max_attempts **無條件先寫 audit log**，再走 `notify_admin_safely`（warn-and-swallow）
- email 失敗 try/catch `\Throwable` + `error_log`，**不阻擋登入**
- 失敗回應對未知 slug **不** record_failure（避免 timing oracle 暴露 slug 存在性）

---

## 7. 核心 Invariant

### partner_term_id 鎖死於 token

Partner Reports（kpi / trend / settlements）的 `partner_term_id` **必須**由 `partner_token_permission` 寫入 `$request->_partner_term_id`，UseCase 從 caller 取——

**永遠不可從 query string 讀取**。否則：

```bash
# 攻擊者持 partner A 的 token，發出 ?partner_term_id=B 的請求
GET /power-shop/partner-reports/kpi?partner_term_id=B
Authorization: Bearer <partner_A_token>
```
若 query string 被讀就會撈出 partner B 的數據——`PartnerReportScopeIsolationTest` + `PartnerReportsScopeTest` 同時在 Unit / IT 雙鎖。

`$request->_partner_term_id` 內部欄位用 `_` 前綴是為了避開 `WP::sanitize_text_field_deep` 的 bucket sanitize（純 convention，**不是**安全機制）。

### ProfitShop status invariant

`ProfitShop::change_status()` 內部檢查 `VALID_STATUSES`，違反則拋 `InvalidStatusTransition`。直接設 `$status` 屬性是不允許的（property 是 `protected`）。

### Cart price signature normalize 兩端一致（Phase 3-D T-8）

`CartPriceOverrideHook` 的 sign / verify / set_price 三處呼叫點**必須**使用同一個 `normalize_price_for_signature(string): string` helper：

```php
// 用 wc_format_decimal 第二參數空字串，trim trailing zeros
wc_format_decimal( $price, '' )
// '888' → '888'、'888.50' → '888.5'、'888.55' → '888.55'
```

若任何路徑漏 normalize（例如 sign 端用 `'888'` / verify 端經過 `wc_format_decimal` 變 `'888.00'`），舊 cart signature 會全部驗證失敗→靜默 fallback 到原價→商家損失。整個 cart_item 生命週期都用 normalized 形式儲存。

### Cart override 僅限 `status='publish'` 賣場（Phase 3-D T-8 reviewer L-3）

`CartPriceOverrideHook::add_cart_item_data` 開頭即檢查 `'publish' !== $shop->status()`，非 publish 直接 `return $cart_item_data`（不注入 `_profit_*` meta）。防 partner promo 預覽價（draft shop）洩漏到客戶 cart。

### V2Api 列表 endpoint page 上限 cap 10000（Phase 3-D Batch 4 LOW-T5-1）

`partner-reports/settlements` / `profit-shops` list / `profit-partners` list 的 `page` 參數一律 `min($page, 10000)`，配合 `per_page` max 100，OFFSET 上限為 1M rows，遠超實際資料量並防 `page=999999999` 的 DoS。

---

## 8. Slug 衝突（spec §6.11）

`SlugConflictDetector` 檢查 5 類衝突：
1. WordPress 保留字（admin、wp-admin 等）
2. 站台核心 page slug
3. 既有 powershop CPT slug
4. 既有 profit_partner term slug
5. 商品 / 分類 slug

衝突結果以 `Domain/ValueObject/SlugConflict`（不是 Exception）回傳給 Application 層判斷——避免 Exception 跨層反向依賴 Application（DDD 純度修正）。

---

## 9. 測試組織

| 類型 | 路徑 | 啟 WP | 用途 |
|------|------|-------|------|
| Unit (Domain) | `tests/Unit/Domain/` | ❌ | ValueObject / Entity / Service 純邏輯 |
| Unit (Application) | `tests/Unit/Application/` | ❌ | UseCase + Service（用 `tests/Support/` fakes） |
| Integration (Infrastructure) | `tests/Integration/Infrastructure/` | ✅ | Repository 實作、CPT/Taxonomy 註冊、REST routing |
| Support / Fakes | `tests/Support/` | — | 同步 implements production interface |

關鍵測試：
- `DtoRoundTripTest`：DTO from_array → to_array 對稱性
- `PartnerReportScopeIsolationTest` + `PartnerReportsScopeTest`：partner 資料範圍隔離
- `PartnerTokenSecurityTest`：transient 不存明文、過期/revoke 401
- `PartnerBruteForceTest`：5 次失敗 429 + Retry-After
- `PartnerVsAdminAuthIsolationTest`：admin nonce 不能用 partner endpoint
- `ExceptionMapperDepthTest`：13 種 Domain Exception → HTTP 對映完整性（Phase 3-E：透過 `power_shop_exception_mapper_mask` filter test seam 解封 production message 遮蔽測試）

Phase 3-D + 3-E 新增測試：
- `tests/Unit/Application/Service/CartPriceSignatureServiceTest`（14 method）：HMAC-SHA256 sign/verify、`cart-signature:v1` domain prefix、`v1.` version prefix、hash_equals timing oracle 防禦、reflection 鎖死
- `tests/Unit/Application/Service/PartnerTokenStorePasswordRotationTest`（9 method）：`password_changed_at` 撤銷 4 case + revoke 共存 + ctor 鎖死 4-interface DI + hash_hmac reflection + legacy sha256 失效
- `tests/Unit/Application/Service/LoginRateLimiterIpDimensionTest`（14 method）：per-slug + per-IP 雙維度、unknown slug 仍 IP record、reset 雙維度行為、IP hash_hmac
- `tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest`（9 method）：格式守門 + 五類衝突委派（`FakeSlugConflictDetector` kind 命名與 production 解耦）
- `tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest`（21 method / 248 assertion）：基本聚合 / partner IDOR 隔離 / status filter / date range / SQL injection 防禦 / trend / pagination / partner_term_id=0 防禦 / HPOS 雙路徑 markTestSkipped 守門
- `tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest`（14 method）：sign→verify round-trip、partial-meta tampering fallback、normalize 一致性、`empty_cart` 真實 re-instantiation、draft shop fallback

---

## 10. 註冊流程

新增 Repository / Service / UseCase / V2Api endpoint 時：

1. **Domain / Application 純邏輯**：`composer dump-autoload` 後即可被 PSR-4 載入
2. **Infrastructure WordPress 註冊類**（CPT / Taxonomy / RewriteRules）：在 `Infrastructure/WordPress/` 加 class，於 `ProfitShop\Loader::__construct()` 加 `::instance()`
3. **V2Api 新 endpoint**：在 `$apis` 陣列新增條目（`endpoint` / `method` / `permission_callback`），實作對應 `{method}_{endpoint_underscored}_callback` method
4. **`Domains\Loader`** 已註冊 `ProfitShop\Loader::instance()`（無需重註冊）

---

## 11. 參考 commit 與規格

| 階段 | Commit |
|------|--------|
| Phase 1 Domain | `cbd0522` / `8359918` / `9ecdb77` |
| Phase 2 Infrastructure + CPT | `fb216c7` / `2004b00` |
| Phase 3-A Domain 缺口 + Application 骨架 | `c1f50ea` |
| Phase 3-B Admin CRUD + V2Api | `b549796` |
| Phase 3-C Partner Auth + Reports | `e98edbf` / `cb62bdd` / `f47a531` |
| Phase 3-D Batch 1（Token getter + Cart signature）| `78a050f` |
| Phase 3-D Batch 2（Token 撤銷 + Settlement 真實 SQL）| `64e4c04` |
| Phase 3-D Batch 3（RateLimiter +per-IP + Cart Hook）| `4e1c780` |
| Phase 3-D Batch 4（V2Api 整合 + 11 條安全強化）| `3b36a2a` |
| Phase 3-E（validate-slug + ExceptionMapper 解封 + OpenAPI 同步）| `0660fc7` |
| Phase 4-A1（ProfitShop CRUD UI + SlugInput + ExceptionMapper）| `1c1e3b9` |
| Phase 4-A2（Partner CRUD + RegeneratePasswordButton + ItemsEditor + ShopActionsButtons）| `883edc9` |
| Phase 4-A3（Migration + Settings + OneShop 改造 + 9 條順手清單收尾）| `6610548` |
| Phase 4-B1（Partner portal Skeleton：PartnerPortalRenderer + Vite multi-entry + Auth + Login）| `bc3ea5c` |
| Phase 4-B2（Partner portal Dashboard 殼：DateRangeFilter + KPI 4 卡 + LoadingScreen + ErrorBoundary）| `7e40969` |
| Phase 4-B3（Partner portal Trend + Settlements + 收尾：echarts callback ref + apiClient baseURL + manifest fallback）| `23e76e9` |

設計與決策來源：
- `specs/2026-05-06-profit-shop-design.md`（含 Q1-Q6 plan 階段決策、§4 endpoint、§6.11 slug 衝突、§7 例外對映）
- 各 commit message 內附 reviewer / acceptance-evaluator 結論與順手清單

---

## 12. 前端 SPA 架構（Phase 4-A + 4-B）

兩個獨立的 React SPA bundle：商家後台 admin SPA（4-A）+ Partner self-service portal（4-B）。前者掛在 `admin.php?page=power-shop`，後者掛在 `/profit-report/{slug}/`。詳細元件結構與目錄樹見 `react.rule.md` §6.5（admin Profit Shop 系列）與 §6.6（Partner Portal）。

### 12.1 Resource 路由

| Resource | 路由 | Page Module |
|----------|------|-------------|
| `profit-shop` | `/profit-shop` / `/create` / `/edit/:id` | `pages/admin/ProfitShop/` |
| `profit-partner` | `/profit-partner` / `/create` / `/edit/:id` | `pages/admin/ProfitPartner/` |
| `profit-migration` | `/profit-migration` | `pages/admin/ProfitMigration/` |
| `profit-settings` | `/profit-settings` | `pages/admin/ProfitSettings/` |

### 12.2 useCustom 取代 useTable / useForm

後端 V2Api 統一回 `{code, message, data}`，與 antd-toolkit 預設 dataProvider 預期的 WordPress REST API shape（`x-wp-total` header、payload 直接是 array / object）**不相容**。

**規範：**
- 4 個 profit-* resource **不**使用 `useTable` / `useForm`
- 改用 `useCustom` / `useCustomMutation`，手動讀 `result.data.data`
- 仍須明確指定 `dataProviderName: 'power-shop'`（含元件層 useCustom 如 `SlugInput`）
- Refine 仍提供 `<List>` / `<Edit>` / `<Create>` UI shell + `useInvalidate` 做快取失效

未來若改用 `useTable` / `useForm`，需先為 `'power-shop'` dataProvider 寫 thin wrapper 拆解 `{code, data}`。屬於 Phase 4-B 自選改造項。

### 12.3 dataProviderName: 'power-shop' 統一規範

所有打 profit-* endpoint 的 `useCustom` / `useCustomMutation`（包含元件層的 `SlugInput`、`PartnerSelector`、`ItemsEditor` 內部查詢）**必須**明示 `dataProviderName: 'power-shop'`。Phase 4-A3 的 LOW-1 已對 30+ 處統一補齊，並寫入 CLAUDE.md Common Pitfall #10。

### 12.4 不可逆操作的 4 道閘門 / 二次確認模式

ProfitMigration `ImportModal` 與 ProfitSettings `ResetButton` 屬不可逆操作，採以下模式：

**ImportModal（IMPORT）4 道閘門**：
1. List 列每 row 的 `Import` 按鈕：`importable=false` 時 `disabled` + tooltip 顯示原因
2. Modal 開啟後 `canSubmit` 仍雙檢 `importable`（防 cache stale）
3. PartnerSelector 必選（強制 partner_term_id）
4. IMPORT 二次確認（typed confirmation 或 confirm dialog）

**ResetButton（RESET）紀律**：
- 自包 mutation（不外洩通用 `useReset` hook，避免誤用於其他頁面）
- RESET 二次確認 + Modal `maskClosable=false` + `keyboard=false`（強制走確認按鈕）
- 失敗保留 state；成功 `useInvalidate` 觸發父頁 content-diff 自動重填

兩個元件都用 `Modal closable=false` / `maskClosable=false` / `keyboard=false` 強制使用者走確認按鈕，避免誤觸 ESC / mask click 自動關閉視為「同意」。

### 12.5 HIGH-RISK 元件的安全紀律

#### RegeneratePasswordButton（明文密碼僅展示一次）

8 條紀律（4-A2 master self-check + reviewer / security 雙審 PASS）：

1. 明文新密碼僅在元件 `useState` —— **不**進 cache / storage / log / Jotai / Context / Redux
2. 5 分鐘 `setTimeout` auto-clear + unmount cleanup（4-A3 補 `setPwdVisible(false)`，否則下次開 modal 殘留 visible=true）
3. Modal `closable=false` + `maskClosable=false` + `keyboard=false`（強制走確認按鈕）
4. `navigator.clipboard.writeText` —— **不**fallback `document.execCommand('copy')`
5. response 型別守衛：`'password' in payload && typeof payload.password === 'string'`
6. error case **不**顯示舊密碼（清空 state）
7. Edit 主表單 submit payload **不**含 password（要改密碼必須走 RegeneratePasswordButton）
8. Input.Password 預設 mask（`visibilityToggle.visible` 由 state 控制，初始 `false`）

#### ImportModal / ResetButton

見 §12.4。

### 12.6 防 refetch 覆寫使用者編輯 Pattern

React Query `refetchOnWindowFocus`（預設 true）會讓 `record` reference 變化 → `useEffect(() => form.setFieldsValue(record), [record])` 觸發 → 使用者打字到一半被覆蓋。

**對策（4-A1 起 Edit 頁面共用）：**

```tsx
const filledIdRef = useRef<number | null>(null)

useEffect(() => {
  if (record && record.id !== filledIdRef.current) {
    form.setFieldsValue(record)
    filledIdRef.current = record.id
  }
}, [record, form])

// 雙保險：對應的 useCustom 加 queryOptions: { refetchOnWindowFocus: false }
```

**ProfitSettings 加強版（4-A3）**：因為 reset 會讓 server 內容變動但 page 不重新 mount，不能光靠 `filledIdRef`（settings 沒有 id）。改用 `lastFilledSettingsRef` 做 content-diff：reset 後內容變動時重填，單純 refetch 同內容時不覆寫使用者編輯。

### 12.7 Partner Portal Bundle（Phase 4-B，獨立 SPA）

Partner self-service portal 是與 admin SPA 完全隔離的另一支 React bundle，由 PHP `PartnerPortalRenderer` 在前台渲染。詳細目錄與安全紀律見 `react.rule.md` §6.6；架構流程圖與初始化路徑見 `architecture.rule.md` 的 partner portal 章節。

#### URL 與渲染

| 項 | 值 |
|----|----|
| URL | `/profit-report/{slug}/`（Phase 2 RewriteRules 註冊） |
| Query var | `profit_partner_report = $slug`（雙保險 `(string) cast` + `sanitize_title`） |
| PHP renderer | `Infrastructure/WordPress/PartnerPortalRenderer`（`template_redirect` priority 9，早於 theme 的 `template_include`） |
| Mount 點 | `<div id="profit_partner_portal"></div>` |
| Entry | `js/src/partner-portal/main.tsx` |
| Vite 配置 | multi-entry `input: ['js/src/main.tsx', 'js/src/partner-portal/main.tsx']` |
| 環境變數 | `window.power_shop_partner_data.env`（SimpleEncrypt 加密：`SITE_URL` / `API_URL` / `KEBAB` / `SLUG`，**無 nonce**） |
| Theme | **完全脫離**，不走 `get_header` / `wp_head` / `get_footer` / `wp_footer`；自己印 HTML 骨架 |

不存在 → `render_404()` + `status_header(404)` + `exit`。Vite manifest 缺 entry → `render_maintenance()` 503 + `nocache_headers` + `exit`（Phase 4-B3 reviewer L-3 fallback）。

#### 隔離邊界

partner bundle **不**打包 admin 的任何模組。隔離驗收（4-B 必過）：

```bash
# build 後對 partner main entry chunk grep
grep -E 'App1|Refine|@refinedev|dataProviderName|wc-rest|wp-rest|X-WP-Nonce' partner-main.js
# 應全部 0 命中
```

若有命中代表誤 import 了 admin 模組，須拆 import path（不從 `@/` 跨入 admin tree）。

#### Auth：cookie + sessionStorage metadata（不存 token）

| 機制 | 紀律 |
|------|------|
| 認證載體 | HTTP-only cookie（後端 `partner-auth/login` 用 `Set-Cookie` 簽發；HttpOnly + Secure + SameSite=Lax + Path=/wp-json/power-shop/） |
| 前端 axios | `withCredentials: true`（讓 cookie 自動帶上） |
| Token 儲存 | **永不**進 localStorage / sessionStorage / cookie 主動讀寫；前端**讀不到** token（XSS 防護） |
| sessionStorage | 只存 metadata：`partner_id` / `partner_name` / `partner_slug` / `expires_at`，`session.read()` shape 防呆 |
| 401 interceptor | 在 React tree 之外，`window.location.hash = '#/login'` 跳轉，`isLoginPage` 防迴圈 |
| Logout | **永遠 local cleanup**（清 sessionStorage + react-query cache + 跳 login）即使 API 失敗 |

#### 跨 partner 雙保險（reviewer M-1 4-B1 必修）

URL slug 與 sessionStorage 中的 `partner_slug` 不一致時（攻擊者直接打開另一個 partner 的 URL），AuthContext 必須**雙保險**遮蔽：

```ts
// AuthContext.tsx
const isMismatch = urlSlug !== session?.partner_slug

const status = isMismatch ? 'loading' : (session ? 'authenticated' : 'guest')
                  // ↑ 不是 'authenticated'，避免短暫渲染舊 partner 資料
const partner = isMismatch ? null : session
                  // ↑ 雙保險：即使下游元件誤 render，partner 也是 null

useEffect(() => {
  if (isMismatch) {
    handleLogout()           // 清 session + 跳 login
    notification.warning(...) // 通知
  }
}, [isMismatch])
```

兩道防線缺一不可：只靠 `status` 防短暫渲染；只靠 `partner=null` 在 `useEffect` 觸發前 status 仍可能是 `'authenticated'`。

#### rate-limit 倒數（Retry-After header parse）

Login 失敗 429 時，後端回 `Retry-After` header（秒數）。前端 `utils/retryAfter.ts`：

- 防呆 `NaN` / negative / 非數字 → 預設 cooldown=0
- cooldown timer 倒數秒數，期間 button `disabled`
- 倒數歸零自動釋放
- error 訊息走 `mapPartnerException`（`too_many_attempts` / `unauthorized` 額外訊息 + fallback admin `profitShopExceptionMapper` 12 種 code）

#### echarts callback ref pattern（reviewer C-1 教訓，4-B3）

❌ `useRef` + `useEffect` init echarts → mount 時 ref 還沒 attach DOM，`echarts.init` 失敗或 dispose race。

✅ callback ref：在 DOM mount/unmount 那一瞬被觸發，沒有時序間隙。

```tsx
const chartRef = useCallback((node: HTMLDivElement | null) => {
  if (!node) {
    chartInstanceRef.current?.dispose()
    chartInstanceRef.current = null
    return
  }
  chartInstanceRef.current = echarts.init(node)
  chartInstanceRef.current.setOption(option)
}, [option])
```

#### 不引入 Refine / dataProvider

partner bundle **不**用 Refine v4 / 6 個 admin dataProvider。直接 axios `apiClient`（`baseURL` 內建）+ react-query 原生 `useQuery` / `useMutation`。

`api/reports.ts` 的 `fetchKpi` / `fetchTrend` / `fetchSettlements` 是 thin wrapper，**partner_term_id 永不出現在 query**（後端 token 鎖死，spec §7 invariant + 本檔 §7「partner_term_id 鎖死於 token」）。

#### 資料流

```
partner 在瀏覽器訪問 /profit-report/{slug}/
  → PHP PartnerPortalRenderer 渲染獨立 HTML（mount 點 + 加密 env）
  → partner-portal/main.tsx mount React SPA
  → AuthProvider 從 sessionStorage 讀 metadata + 比對 URL slug
    ├─ guest / mismatch → /login（rate-limit 倒數 + cooldown）
    │   └─ login 成功 → 後端 Set-Cookie HttpOnly + 回 metadata
    │       → sessionStorage 寫入 metadata（不存 token）
    │       → router 跳 /dashboard
    └─ authenticated → /dashboard
        → useKpi / useTrend / useSettlements
        → axios apiClient（withCredentials + cookie 自動帶）→
        → /wp-json/power-shop/partner-reports/{kpi,trend,settlements}
        → V2Api partner_token_permission 從 cookie 取出 token
          → PartnerTokenStore::verify 還原 partner_term_id
          → 寫入 $request->_partner_term_id
          → UseCase 從 caller 取（永不從 query string）
        → 裸 payload 回前端（不裹 {code, data}）
```
