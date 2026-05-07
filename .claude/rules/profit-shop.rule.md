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

目前 18 個 Domain Exception（涵蓋 Phase 1～3-C）：
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
- `ExceptionMapperDepthTest`：13 種 Domain Exception → HTTP 對映完整性

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

設計與決策來源：
- `specs/2026-05-06-profit-shop-design.md`（含 Q1-Q6 plan 階段決策、§4 endpoint、§6.11 slug 衝突、§7 例外對映）
- 各 commit message 內附 reviewer / acceptance-evaluator 結論與順手清單
