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

目前 19 個 Domain Exception（涵蓋 Phase 1～6-A1，3-D/3-E 未新增 Domain Exception）：
```
ValueObject 驗證類:    InvalidPartnerSlug / InvalidPriceOverride / InvalidProfitRate / InvalidShopMode /
                     WeakPassword（Phase 6-A1，Partner 自助修密碼，attr reasons: string[]）
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

### Partner 自助修密碼（Phase 6-A1）

`POST /partner-auth/change-password`（permission `partner_token`）對應 `ChangePasswordUseCase`，與 admin `regenerate-password` 互不相通：

| 維度 | Admin regenerate-password | Partner 自助修密碼 |
|------|---------------------------|---------------------|
| Permission | `manage_options` + `X-WP-Nonce` | `partner_token`（cookie / Bearer / X-Partner-Token） |
| 觸發者 | 站長 | partner 本人 |
| 密碼來源 | `wp_generate_password(12, false)` 系統產生 | partner 提供 new_password 明文 |
| current_password 驗證 | 不需要（admin 直接重設） | **必驗**（防 cookie 偷取後改密鎖人） |
| 強度檢查 | 不需要（系統產生保證） | `PartnerPassword` ValueObject（≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字；mb_strlen UTF-8） |
| Response | 含明文（前端僅展示一次） | 不含密碼，只回 `{success, password_changed_at}` |
| Set-Cookie Max-Age=0 | 不需要 | **必須**（強制 partner 重新登入） |
| Cache-Control | `no-store, no-cache, must-revalidate` | 同左（success path 與 regenerate 一致） |

**核心紀律（8 條，違反 = 退回重做）：**

1. **current_password 必驗**：UseCase 流程「先 verify_password 才允許 save」，verify 失敗 → record_failure + InvalidCredentials
2. **rate-limit pseudo-slug 維度隔離**：`pwchange:{partner_term_id}` 與 partner login slug 維度完全分開；登入失敗鎖不影響改密、改密失敗鎖不影響登入
3. **改密成功端到端撤銷**：save → password_changed_at termmeta 寫入 → Set-Cookie Max-Age=0 → 舊 token 在 `PartnerTokenStore::verify` 比對 `issued_at <= password_changed_at` 立即失效（M-1 同秒 race window 也覆蓋，見 §7）
4. **弱密碼不污染 rate-limit**：`new PartnerPassword(...)` 在 verify 之後、save 之前；弱密碼拋 WeakPassword 時**不**呼叫 record_failure，避免使用者按錯 5 次格式就被鎖
5. **response 不洩漏**：永不回密碼明文 / hash / token；只回 `{success: true, password_changed_at: int}`
6. **audit log 不含明文**：`error_log` 過濾換行 / tab，只寫 partner_term_id 與 timestamp，**不**含明文密碼 / new_password
7. **admin nonce 不能走此 endpoint**：permission `partner_token` 不接受 `X-WP-Nonce`（與 PartnerVsAdminAuthIsolationTest 鎖死的雙軌制一致）
8. **success path 強制 no-cache**：`Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`（防 proxy / browser 快取響應內容；與 regenerate-password 對齊）

**L-4 fail-fast 縱深防禦**：V2Api callback 對 `_partner_term_id <= 0` 立即拋 `InvalidArgumentException`（→ ExceptionMapper fallback 400 validation_failed）。permission_callback 應已透過 `set_param` 寫入合法 partner_term_id；若未寫入而落到此處（=0 / 負數），代表 permission 流程缺陷或被繞過，直接拋以避免污染 `pwchange:0` rate-limit 維度。

**IP 取值流程與 partner login DRY**：`filter_var` `FILTER_VALIDATE_IP` 防呆 + `power_shop_partner_login_client_ip` filter hook 共用（reverse proxy 場景插入 trust proxy header 解析）。

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

### PartnerTokenStore 同秒撤銷契約（Phase 6-A1 reviewer M-1）

`PartnerTokenStore::verify` 比對 `issued_at` 與 `password_changed_at` 時，使用**嚴格大於**判斷視為通過：

```php
// ✅ 正確（Phase 6-A1 reviewer M-1 後）
if ( null !== $password_changed_at && $issued_at <= $password_changed_at ) {
    return null;  // 視為已撤銷
}

// ❌ 錯誤（Phase 3-D 原版，被 M-1 反轉）
if ( null !== $password_changed_at && $issued_at < $password_changed_at ) {
    return null;
}
```

**為何要從 `<` 反轉為 `<=`**：閉合「同秒簽發舊 token + 同秒改密」race window：

```
[時間 T 秒]
  - 攻擊者持有 partner A 的 cookie / token（issued_at=T）
  - partner A 在 T 秒（同一秒內）改密 → password_changed_at=T
  - 若用 issued_at < password_changed_at（T < T = false），舊 token 仍被視為有效
  - 修補後 issued_at <= password_changed_at（T <= T = true），舊 token 立即失效
```

換言之，password_changed_at = issued_at 同秒視為「已撤銷」，**必須嚴格大於** password_changed_at 才通過。`PartnerTokenStorePasswordRotationTest` 的同秒 case 在 Phase 6-A1 已從「視為有效」反轉為「視為已撤銷」契約（檔頭 docblock 同步更新）。

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
- `tests/Unit/Application/Service/PartnerTokenStorePasswordRotationTest`（9 method，Phase 6-A1 反轉同秒 case 為「已撤銷」契約 + 檔頭 docblock 同步）：`password_changed_at` 撤銷 4 case + revoke 共存 + ctor 鎖死 4-interface DI + hash_hmac reflection + legacy sha256 失效
- `tests/Unit/Application/Service/LoginRateLimiterIpDimensionTest`（14 method）：per-slug + per-IP 雙維度、unknown slug 仍 IP record、reset 雙維度行為、IP hash_hmac
- `tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest`（9 method）：格式守門 + 五類衝突委派（`FakeSlugConflictDetector` kind 命名與 production 解耦）
- `tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest`（21 method / 248 assertion）：基本聚合 / partner IDOR 隔離 / status filter / date range / SQL injection 防禦 / trend / pagination / partner_term_id=0 防禦 / HPOS 雙路徑 markTestSkipped 守門
- `tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest`（14 method）：sign→verify round-trip、partial-meta tampering fallback、normalize 一致性、`empty_cart` 真實 re-instantiation、draft shop fallback

Phase 6-A1 新增測試（Partner 自助修密碼）：
- `tests/Unit/Domain/ValueObject/PartnerPasswordTest`（8 method）：≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字累積規則、reasons 多違反一次回 / 邊界長度 / value() 原樣回傳 / mb_strlen 以 codepoint 計（reviewer L-2）
- `tests/Unit/Domain/Exception/WeakPasswordTest`（3 method）：`extends \DomainException` 鎖死（避免 ExceptionMapper fallback 漏接）+ `getReasons()` round-trip + 預設 message 自動由 reasons 組成
- `tests/Unit/Application/UseCase/Partner/Auth/ChangePasswordUseCaseTest`（12 method）：流程順序鎖死（assert_not_blocked → find_by_id → verify_password → new VO → save → reset → audit log）+ pseudo-slug `pwchange:{id}` 與 login slug 維度隔離 + 弱密碼不污染 rate-limit + IP null 退化只走 pseudo-slug
- `tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest`（11 method）：HTTP 端到端（200 / 401 / 422 / 429）+ Set-Cookie Max-Age=0 + Cache-Control no-store / Pragma no-cache + 改密成功後舊 token 立即失效（PartnerTokenStore::verify 同秒 race window）+ admin nonce 不能走此 endpoint
- `tests/Integration/Infrastructure/Rest/ExceptionMapperWeakPasswordTest`（1 method）：`WeakPassword → 422 weak_password` + `data.reasons[]` 對映完整性，且優先於 `\DomainException` fallback

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
| Phase 4-C1（賣場前台 PHP renderer + Theme 整合 template：URL `/profit-shop/{slug}/` + `find_by_slug` + SEO meta）| `2d6a76a` |
| Phase 4-C2（AddToCart Hook + Cart UI 分潤標記：priority 5 三閘門 + CartItemMetaDisplay + DRY `SHOP_REWRITE_PREFIX`）| `f813e07` |
| Phase 5-A（IP rate-limit + logout 401 跳過 + env fail-fast）| `4e7422a` |
| Phase 5-B（DDD 純度：has_item method + publish nocache 條件化）| `7fd2b37` |
| Phase 5-C（累積 NIT 清理：PHP + JS 共 10 條）| `32b9d5a` |
| Phase 6-A1（Partner 自助修密碼：PartnerPassword VO + WeakPassword Exception + ChangePasswordUseCase + V2Api `/partner-auth/change-password` + PartnerTokenStore M-1 同秒撤銷）| `e974ae4` |
| Phase 6-A2（Partner 自助修密碼前端 UI：ChangePassword.tsx HIGH-RISK + useChangePassword + forceLogoutAndRedirect + Login `?reason=password_changed` + partnerExceptionMapper context-aware）| `c80b4cc` |

設計與決策來源：
- `specs/2026-05-06-profit-shop-design.md`（含 Q1-Q6 plan 階段決策、§4 endpoint、§6.11 slug 衝突、§7 例外對映）
- 各 commit message 內附 reviewer / acceptance-evaluator 結論與順手清單

---

## 12. 前端 SPA 架構（Phase 4-A + 4-B + 6-A2）

兩個獨立的 React SPA bundle：商家後台 admin SPA（4-A）+ Partner self-service portal（4-B / 6-A2）。前者掛在 `admin.php?page=power-shop`，後者掛在 `/profit-report/{slug}/`。詳細元件結構與目錄樹見 `react.rule.md` §6.5（admin Profit Shop 系列）與 §6.6（Partner Portal）。

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

#### RegeneratePasswordButton（admin SPA，明文密碼僅展示一次）

8 條紀律（4-A2 master self-check + reviewer / security 雙審 PASS）：

1. 明文新密碼僅在元件 `useState` —— **不**進 cache / storage / log / Jotai / Context / Redux
2. 5 分鐘 `setTimeout` auto-clear + unmount cleanup（4-A3 補 `setPwdVisible(false)`，否則下次開 modal 殘留 visible=true）
3. Modal `closable=false` + `maskClosable=false` + `keyboard=false`（強制走確認按鈕）
4. `navigator.clipboard.writeText` —— **不**fallback `document.execCommand('copy')`
5. response 型別守衛：`'password' in payload && typeof payload.password === 'string'`
6. error case **不**顯示舊密碼（清空 state）
7. Edit 主表單 submit payload **不**含 password（要改密碼必須走 RegeneratePasswordButton）
8. Input.Password 預設 mask（`visibilityToggle.visible` 由 state 控制，初始 `false`）

#### ChangePassword.tsx（partner portal，6-A2 HIGH-RISK 強化版 8 條紀律）

對照 admin RegeneratePasswordButton（**admin 由 admin 產生密碼給 partner**）的不同點：partner 自助改密本人輸入明文 current + new，端點 `POST /partner-auth/change-password`（partner_token），成功後本機**強制重新登入**（後端 password_changed_at 同秒撤銷舊 token，6-A1 PartnerTokenStore::verify L138 `<=` 同秒契約）。

8 條紀律（二輪後強化）：

1. 明文密碼**只**在 antd Form state，不進 cache / storage / log / Jotai / Context
2. 不 console.log 印密碼（即使 mutation.onError 也不 log payload）
3. submit 後 form.resetFields **分支策略**（MAJOR-3）：weak_password UX 友善只清 current；其他敏感全清
4. submit button **三重守門** `disabled = mutation.isLoading || isCoolingDown || success`
5. 成功後 `forceLogoutAndRedirect('password_changed')`（與 `handleLogout` 並存，前者強制 redirect + reason）
6. `mutation.reset()` 清 react-query variables（防 React DevTools 窺）
7. Form rule 阻擋 `new === current`（無變化）/ `new !== confirm`（confirm 不一致）
8. `setTimeout` cleanup ref + useEffect unmount cleanup（防使用者中途離開後仍觸發 logout）

**關鍵技術決策（master 自決）**：
- `X-Skip-Auth-Redirect` header 防 401 interceptor 干擾 success notification（讓元件自己接 onError）
- success state lock（MAJOR-4，A+ 級）：連返回按鈕都 disabled，蓋 1.5s race
- `partnerExceptionMapper` context-aware 第二參數（`'default' | 'change-password'`）— Login 不傳預設 `'default'` 向後相容
- LOW-7 跳過 DRY 重構（兩 context branch 80% 相同但語意差異點需保留 caller）

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

#### ChangePassword 路由與入口（Phase 6-A2）

| 項 | 值 |
|----|----|
| 路由 | `/change-password`（authenticated only，未登入跳 `/login`） |
| 入口 | Dashboard 右上「修改密碼」link button |
| Hook | `hooks/useChangePassword.ts`（typed `useMutation<TChangePasswordOutput, AxiosError, TChangePasswordInput>` + `mutationKey: ['partner-change-password']` + `retry: 0`） |
| API wrapper | `auth/api.ts` 的 `changePassword(payload)`（POST `/partner-auth/change-password`，加 `X-Skip-Auth-Redirect` header） |
| Endpoint | `POST /wp-json/power-shop/partner-auth/change-password`（Phase 6-A1 後端，permission `partner_token`，body 不過 sanitize_text_field） |
| Success path | `forceLogoutAndRedirect('password_changed')` → 跳 `/login?reason=password_changed` → Login 顯示一次性 success notification（KNOWN_REASONS 白名單） |
| Failure path | mutation.onError 接 → `mapPartnerException(error, 'change-password')` 翻譯訊息 → notification.error；rate-limit 走 `Retry-After` header cooldown 倒數（與 Login 共用 `retryAfter.ts`）|

詳細 8 條 HIGH-RISK 紀律見上 §12.5 ChangePassword.tsx 章節。

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

### 12.8 賣場前台 + AddToCart 整合（Phase 4-C）

賣場前台是給**終端買家**逛商品下單的 customer-facing 頁面，與 partner portal SPA（4-B）走完全不同的渲染路線。架構流程圖見 `architecture.rule.md`「賣場前台」與「Add-to-Cart 端到端流程」章節。

#### URL 與渲染

| 項 | 值 |
|----|----|
| URL | `/profit-shop/{slug}/`（Phase 4-C1 RewriteRules 註冊，priority `'top'`） |
| 常數 | `RewriteRules::SHOP_QUERY_VAR = 'profit_shop_slug'` / `SHOP_REWRITE_PREFIX = 'profit-shop'` |
| Query var | `profit_shop_slug = $slug`（雙保險 `(string) cast` + `sanitize_title`） |
| PHP renderer | `Infrastructure/WordPress/ProfitShopRenderer`（`template_redirect` priority 9，與 PartnerPortalRenderer 同層；query var 互斥不衝突） |
| Repository | DI 注入 `ProfitShopRepositoryInterface::find_by_slug(string): ?ProfitShop`（4-C1 新增方法，雙保險 trash 過濾） |
| Theme | **走 theme**（`get_header` / `get_footer`），與 partner portal 完全脫離 theme 不同 |
| Template | `Infrastructure/WordPress/templates/profit-shop-front.php`（純 PHP，無 React，無 Tailwind） |
| 框架 | 純 PHP page template；customer-facing + SEO 重點 + 不破壞 WC cart / checkout 既有流程 |

#### Status 三態 + Draft 預覽 capability

| Status | 行為 |
|--------|------|
| `publish` | 對外可見 |
| `draft` | `current_user_can('edit_post', $shop->id)` 才允許預覽（**對特定 post id 的 ownership 檢查**，不是 `edit_posts`） |
| trashed / 不存在 | 404 + `status_header(404)` + exit |

⚠ 不要把 capability 從 `edit_post($id)` 改回 `edit_posts`：powershop CPT `capability_type='post'`，`edit_posts` 對 author 全通過會違反 least-privilege 與 V2Api admin endpoint `manage_woocommerce` 的一致性（4-C1 security HIGH-1 二輪修正紀錄）。

#### SEO meta 注入（防雙 canonical / 雙 title）

```php
remove_action('wp_head', 'rel_canonical');             // 防雙 canonical
remove_action('wp_head', '_wp_render_title_tag', 1);   // 防 theme title-tag 雙 title
add_action('wp_head', /* 注入自訂 <title> + <link rel='canonical'> */);
```

`<title>` 格式：`{shop_name}｜{partner_name} 的分潤賣場 - {site_name}`。與 SEO plugin 整合（`pre_get_document_title` filter）目前在 Phase 5+ 順手清單（4-C1 m-3）。

#### 商品列表（雙路徑加購）

| 商品類型 | template 渲染方式 | 加購機制 |
|----------|-------------------|----------|
| simple product | `<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="post">` + WC `add-to-cart` hidden + `<input type="hidden" name="profit_shop_id" value="...">` | form post 帶 `profit_shop_id` |
| variable / variable-subscription | permalink + `add_query_arg('profit_shop_id', $shop_id)` 連到單品頁 | URL query string 帶 `profit_shop_id`，由 WC 變體選購流程繼承 |
| grouped | 同上（permalink + add_query_arg） | 同上 |

原價 / 特價用 WC 慣例 `<del>` / `<ins>`；全部輸出走 `esc_html` / `esc_attr` / `esc_url`（`wc_price` / `wc_get_image` 由 WC 自身 escape）。

#### 三道閘門縱深防偽造（Phase 4-C2 AddToCartHook 核心）

`Infrastructure/WooCommerce/AddToCartHook`（**priority 5**，**早於** Phase 3-D `CartPriceOverrideHook` 的 priority 10）從 query string 注入 `cart_item_data['profit_shop_id']`：

```
① $shop = ProfitShopRepositoryInterface::find($shop_id)
   未找到 → return $cart_item_data unchanged

② $shop->status() === 'publish'
   非 publish → return unchanged（draft 預覽不應洩漏到客戶 cart）

③ $product_id ∈ array_map(fn($item) => $item->product_id(), $shop->items())
   不在 shop 商品列表 → return unchanged
```

| 攻擊情境 | 防禦 |
|----------|------|
| 偽造任意 shop_id | ① find null → return unchanged |
| 偽造 product_id 不在 shop | ③ product_in_shop 拒 |
| 拿正版 publish shop 套未授權商品 | ③ 同上 |
| cart session 後手動改 meta | priority 999 驗章失敗 fallback 原價 |

#### Priority 串聯（5 / 10 / 999）

WP filter 依 priority 排序執行；同一個 request 的串接順序為：

```
woocommerce_add_cart_item_data
   ┌─ priority 5  → AddToCartHook（4-C2）            ：注入 profit_shop_id
   └─ priority 10 → CartPriceOverrideHook (3-D)       ：寫 _profit_* meta + HMAC sign

woocommerce_get_item_data
   └─ default     → CartItemMetaDisplay（4-C2）       ：UI 顯示「來自賣場 XXX」

woocommerce_before_calculate_totals
   └─ priority 999 → CartPriceOverrideHook (3-D)       ：hash_equals 驗章 + set_price
```

新增 cart-related hook 時**必須**確認 priority 不會破壞既有串接：例如新 hook 想在「驗證後讀取分潤 meta」必須掛在 999 之後；想在「寫入前再加自訂欄位」必須掛在 5 與 10 之間。

#### `$_GET` + `$_POST` 取值（排除 `$_COOKIE` 攻擊面）

`AddToCartHook` 從 query string 取 `profit_shop_id` 時**只讀 `$_GET + $_POST`**（4-C2 security M-1 二輪修正：原為 `$_REQUEST` 包含 `$_COOKIE`，導致攻擊者可種 cookie 偽造分潤）：

```php
$raw = $_GET['profit_shop_id'] ?? $_POST['profit_shop_id'] ?? null;
// ❌ 不要：$_REQUEST['profit_shop_id'] ?? null
```

加上 `is_array` / `wp_unslash` / `sanitize_text_field` / `ctype_digit` 嚴格純數字檢查（拒 `'01'` / `'+1'` / 浮點 / 負數）+ `(int)` cast。

#### Short-circuit「上游已帶則 return」

`AddToCartHook` 開頭即檢查 `isset($cart_item_data['profit_shop_id'])`，若上游（例如 IT 直接呼 hook、或第三方 plugin 預先帶入）已塞了就 `return $cart_item_data`，不重複驗證/覆寫——防 Phase 3-D `CartPriceOverrideHookTest`（直接測試 hook 不經 query string）regression。

#### Cart UI 顯示一個 filter 涵蓋三處

`Infrastructure/WooCommerce/CartItemMetaDisplay::filter_get_item_data` 掛 `woocommerce_get_item_data`：WC 設計上**這個 filter 同時**被 mini-cart widget / cart page / checkout page 觸發，所以**只要掛一個 filter 就涵蓋全部**，不需重複 hook。

- shop 已刪 → 靜默不顯示（cart 仍可結帳，3-D 寫入的 4 筆 `_profit_*` meta + signature 已固化）
- `target='_blank' rel='noopener'` 防 reverse tabnabbing
- `esc_url(home_url('/' . RewriteRules::SHOP_REWRITE_PREFIX . '/' . $slug . '/'))` 確保 URL prefix DRY，**不**自己定義新常數（4-C2 wordpress M-1 修正）

#### DRY：URL prefix 統一用 `RewriteRules::SHOP_REWRITE_PREFIX`

凡是要組 `/profit-shop/{slug}/` URL 的地方（CartItemMetaDisplay、未來的 ProfitShopRenderer 內部組 canonical 等），**必須**從 `RewriteRules::SHOP_REWRITE_PREFIX` 取，不要 hardcode 字串 `'profit-shop'`，避免日後改前綴時遺漏。
