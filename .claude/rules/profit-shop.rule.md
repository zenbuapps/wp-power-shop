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
- ~~`RewriteRulesFlusherInterface` / `SettingsRepositoryInterface`~~（已隨全域設定頁移除，見 §7）
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

### 🔴 明文密碼並存（2026-08，站長要求的安全降級）

站長需要在後台隨時查看 / 複製夥伴的報表登入密碼，再自行透過 LINE / Email 轉交。
這件事在「只存不可逆雜湊」的前提下**技術上不可能**，因此改為**雜湊與明文並存**：

| termmeta | 用途 | 誰可以讀 |
|---|---|---|
| `_partner_password` | `wp_hash_password` 雜湊，**`verify_password()` 的唯一依據** | 只有驗證路徑 |
| `_partner_password_plain` | 明文，**只供顯示**（後台查看 / 複製） | 只有 admin-only endpoint |

**四條紀律：**

1. **驗證路徑永遠不得讀明文**——`verify_password()` 只認 `wp_check_password()` 對雜湊的比對。
   拿明文做字串比對，會讓「雜湊與明文不同步」的資料靜默通過驗證。
2. **兩者必須在 `save()` 內成套寫入**。只寫一邊的症狀是「站長把後台看到的密碼給了夥伴、
   夥伴卻登不進去」，兩邊都沒有任何錯誤訊息。`save()` 是**密碼寫入的唯一出口**
   （五條路徑全部經過它），新增密碼寫入路徑時不可自行 `update_term_meta`。
3. **明文寫入前必須 `wp_slash()`**。`update_metadata()` 內部會對值跑一次 `wp_unslash()`，
   不先 slash 的話密碼裡的 `\` / `'` / `"` 會被靜默吃掉，而雜湊是用原始明文算的——
   後台顯示的密碼會比實際密碼少幾個字元。雜湊那一行不需要（其輸出字元集不含這些字元）。
4. **`PartnerOutput` 因此含明文，只能用於 admin-only endpoint**。partner-facing 的
   `GET /partner-auth/me` 刻意在 callback 內明確列舉四個欄位輸出，**不要**「順手」改成
   回傳 `PartnerOutput::to_array()`，那會把明文送進夥伴的瀏覽器。
   `PartnerCrudTest::test_partner_me_endpoint_never_exposes_plain_password` 是這道護欄。

**保留雜湊而非「只留明文」，是為了讓這個決定可逆**：日後若要收回明文功能，
只需停止寫入並刪除該 meta，登入流程一行都不用改。

**已知風險（站長已知悉並接受）**：DB 或備份外洩時所有夥伴的報表密碼直接可讀；
若夥伴重複使用了他在其他服務的密碼，該密碼也會一併曝光。緩解僅限於
「這組密碼只能登入唯讀的分潤報表頁，不能改任何資料、不涉金流」。

**`null` 是合法狀態**：功能上線前建立的夥伴只有雜湊、無明文可還原。UI 必須顯示
「無法顯示，請重設密碼」而不是空白——否則站長會誤以為該夥伴的密碼是空的。

### 五條密碼路徑的分工（改動任一條前先對照這張表）

Partner 密碼可以被五個地方寫入，**規則刻意不同**，改動前先確認自己在哪一條。
全部都經過 `PartnerTermRepository::save()`，所以雜湊與明文一定同步。

| 路徑 | 端點 / UseCase | 誰操作 | 密碼來源 | 過 `PartnerPassword` VO？ | 前端是否使用 |
|------|---------------|--------|----------|--------------------------|-------------|
| 建立夥伴 | `POST /profit-partners` → `CreatePartner` | 站長 | 站長輸入（Create 頁「初始密碼」必填欄位） | ❌ **沒有**（見 `specs/tech-debt.md` TD-33） | ✅ |
| **更新夥伴** | `PUT /profit-partners/{id}` → `UpdatePartner` | 站長 | 站長輸入（Edit 頁「報表登入密碼」一般欄位，預填目前明文） | ❌ 沒有 | ✅ **現行主要路徑** |
| 站長指定 | `POST /profit-partners/{id}/set-password` → `SetPartnerPasswordUseCase` | 站長 | 站長輸入 | ✅ | ❌ 前端已不使用 |
| 重新產生 | `POST /profit-partners/{id}/regenerate-password` → `RegeneratePartnerPasswordUseCase` | 站長 | `wp_generate_password(12)` | 不需要（系統保證強度） | ❌ 前端已不使用 |
| 夥伴自助 | `POST /partner-auth/change-password` → `ChangePasswordUseCase` | partner 本人 | partner 輸入 | ✅ | ✅（partner portal） |

⚠️ 第 3、4 條的端點**保留但前端不再呼叫**（原本的 `PasswordSection` / `RegeneratePasswordButton`
兩個元件已刪除）。它們**不是死碼**——是給日後「站長不該看到密碼」的部署情境留的退路，
且各自帶有前兩條路徑沒有的能力（VO 強度驗證、一次性明文顯示）。刪掉它們就等於把那條退路砍斷。

⚠️ **`PartnerInput::from_array()` 必須把空字串正規化為 null**。密碼在 Edit 頁是隨主表單送出的
一般欄位，站長可能清空後按儲存；未正規化就會把空字串當新密碼寫進去——夥伴的密碼變成空值、
從此再也登不進報表頁，過程中沒有任何錯誤訊息。
`DtoRoundTripTest::test_partner_input_normalizes_empty_password_to_null` 鎖住這條。

⚠️ **前端只在密碼「真的被改動」時才送**（`Edit/index.tsx` 的 `initialPasswordRef`）。
`save()` 只要收到 password 就會 bump `password_changed_at` = 該夥伴所有登入 session 立即失效；
若每次存檔都原封不動送回同一組密碼，站長改個名稱都會把夥伴踢出報表頁。

**三條容易搞錯的紀律：**

1. **`set-password` 與 `regenerate-password` 是互補而非重複**，不要「順手合併」：
   前者是「站長要主動交一組自己知道的密碼給夥伴」，後者是「夥伴忘記密碼 / 疑似外洩，
   一鍵重設成系統強密碼」。合併掉任一個都會讓其中一種情境變得很難操作
   （regenerate 的密碼只顯示一次，站長沒抄下來就得再產生一次）。

2. **`set-password` 刻意不回傳密碼明文**，這是它與 `regenerate-password` 的關鍵差異：
   站長自己輸入的密碼他本來就知道，回傳只會多一個洩漏面（response 進 log / proxy cache）。
   regenerate 必須回傳是因為系統產生的，不回傳就沒人知道。

3. **body 讀取必須用 `read_json_body_for_partner()`，不是 `read_json_body()`**（這條**沒有**
   因為密碼改成一般欄位而失效——`PUT /profit-partners/{id}` 也走這個 reader）：
   後者會對所有欄位跑 `WP::sanitize_text_field_deep()`，把密碼裡的 `<` / `>` / 連續空白
   靜默改寫。症狀是「站長設的密碼夥伴登不進去」，而且兩邊看到的字串**完全一樣**，
   極難排查。`PARTNER_RAW_FIELDS = ['password']` 這個常數就是為此存在的。
   實作 `set-password` 時第一版就寫錯了（docblock 寫「不過 sanitize」但實際呼叫了會
   sanitize 的函式），靠 `SetPartnerPasswordEndpointTest::test_password_literal_is_preserved`
   （密碼用 `'a1 <b>  c&d'`）當場抓到——**這條測試不要刪**。

⚠️ 表格第一列的 `CreatePartner` 沒過 VO 是**已知的不一致**（TD-33）：站長可在建立頁設出
`12345678`，但那位夥伴之後想自己改密碼時會被 VO 以「需包含英文字母」擋下——他現在用的
密碼反而是系統不允許他設定的。要補需連同一批既有測試 fixture 一起改。

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

### Cart price signature normalize 兩端一致（Phase 3-D T-8；Phase 13 更正實測行為）

`CartPriceOverrideHook` 的 sign / verify / set_price 三處呼叫點**必須**使用同一個 `normalize_price_for_signature(string): string` helper：

```php
wc_format_decimal( $price, '' )
```

⚠️ **真實行為**（已於 Phase 13 實測確認，2026-08-03）：

```
'888'     → '888.00'
'888.0'   → '888.00'
'888.00'  → '888.00'
'888.5'   → '888.50'
'888.55'  → '888.55'
```

也就是**補足到 `wc_get_price_decimals()` 位小數**，**不是** trim trailing zeros——第二參數 `$dp = ''` 讓函式走 `number_format()` 分支，第三參數 `$trim_zeros` 預設 `false` 且本呼叫沒有傳 `true`。**本文件先前的描述是錯的**（曾寫「trim trailing zeros，`'888'` → `'888'`」）。這極可能就是 `specs/tech-debt.md` TD-1 記載的「cart hook 6 個 pre-existing 失敗」的成因——`CartPriceOverrideHookTest` 內斷言 `'888'` / `'666'` 字面值的 6 個方法原本會失敗（`Expected '888' / Actual '888.00'`），Phase 13 已修正這 6 條測試斷言（純測試變更，非產品行為變更）。

**不要為了讓行為符合「trim trailing zeros」的直覺去改 `normalize_price_for_signature()`。** 改了會讓所有既有 v1 簽章的 payload 價格字串從 `'888.00'` 變成 `'888'` → **既有購物車驗章全數失敗** → `strip_profit_meta()` + `reset_to_original_price()` → **分潤價靜默消失**，且沒有任何錯誤訊息（站長通常要等到對帳才會發現）。產品端零影響：`_profit_price_override` 不對外顯示，`set_price('888.00')` 之後所有 WC 計算與 `wc_price()` 顯示都走數值比較，`'888.00'` 與 `'888'` 完全等價。

若任何路徑漏 normalize（例如 sign 端用 `'888'` / verify 端經過 `wc_format_decimal` 變 `'888.00'`），舊 cart signature 會全部驗證失敗→靜默 fallback 到原價→商家損失。整個 cart_item 生命週期都用 normalized 形式儲存。

### Cart 也要套 regular / sale，不是只有 price（Phase 13）

`CartPriceOverrideHook` 曾經在 `before_calculate_totals` 只呼叫 `set_price()`，從未呼叫 `set_regular_price()` / `set_sale_price()`。結果是購物車（WooCommerce Cart Block / Store API）顯示的**劃線原價**讀到的是商品的**原始** `regular_price`，而非賣場覆寫後的原價——實收價正確、劃線價錯誤，且沒有任何錯誤訊息。成因是舊版 `resolve_effective_price(): ?string` 的回傳型別在結構上就把「原價覆寫」這個資訊丟掉了；`SingleProductPriceHook`（單品頁，Phase 9）當時已經是回傳 `{price, regular, sale}` 三元組，兩條路徑因此分歧（見下方「`OverridePriceResolver`」如何根除這個分歧）。

修法是 cart 端改為向 `OverridePriceResolver` 取同一份三元組，並在驗章通過後套用完整三個值。cart_item meta 因此從 4 筆擴為 6 筆：

| meta key | 用途 | `''` 的語意 |
|----------|------|-------------|
| `_profit_shop_id` | 賣場 ID | — |
| `_profit_partner_term_id` | 分潤夥伴 term ID | — |
| `_profit_price_override` | 實際生效價格（`set_price()` 用） | 有覆寫才會寫入 meta，不會是空字串 |
| `_profit_regular_price_override` | 覆寫後原價（`set_regular_price()` 用） | **「不覆寫原價」，沿用商品原本的原價** |
| `_profit_sale_price_override` | 覆寫後特價（`set_sale_price()` 用） | **「沒有特價」，呼叫端必須主動清掉商品原本的特價** |
| `_profit_signature` | HMAC 簽章 | — |

⚠️ **`regular === ''` 與 `sale === ''` 語意不同，這是本檔最容易寫錯的一行**：
- `regular === ''` ⇒「不覆寫原價」⇒ **不呼叫** `set_regular_price()`，沿用商品原本的原價（對齊 `SingleProductPriceHook::filter_regular_price()`）
- `sale === ''` ⇒「沒有特價」⇒ **必須呼叫** `set_sale_price('')` 清掉商品原本的特價（對齊 `SingleProductPriceHook::filter_sale_price()`）——不清會出現「原價劃掉 → 特價」但實收卻是覆寫原價的矛盾

`reset_to_original_price()`（驗章失敗時的 fallback）同樣要一併還原 regular / sale，不能只還原 price：同一個 request 內 `calculate_totals()` 會被呼叫多次，若第一次驗章通過（已寫入覆寫 regular/sale）、第二次因竄改而失敗，只還原 price 會讓錯誤的劃線價殘留在畫面上。

**行為變更（刻意接受）**：設了 regular/sale 之後 `WC_Product::is_on_sale()` 會變 `true`，而 `WC_Discounts` 用它判斷優惠券的 `exclude_sale_items`。所以站台若有「排除特價商品」的優惠券，分潤賣場的商品會從可折扣變成不可折扣。這在語意上是**正確的**（該品項確實在特價中），且與 `SingleProductPriceHook` 在單品頁的既有行為一致——這不是 bug，已寫 IT 測試鎖住（`test_override_makes_cart_item_on_sale_for_exclude_sale_items_coupon`）。

### 簽章版本遷移：v1 分支永久保留，不可移除（Phase 13）

把 regular / sale 納入簽章 payload 是必要的（未簽章的話，攻擊者改自己 cart session 裡的 `_profit_sale_price_override` 就能翻轉 `is_on_sale()`，讓「排除特價商品」的優惠券變成可用或反之——這是有金額影響的，不只是顯示層風險）。但若不做版本相容，**所有既有購物車**驗章會全部失敗。`CartPriceSignatureService` 因此同時支援兩個 payload 版本：

```
v1（既有，永久保留驗證能力）：'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'
v2（現行，sign() 一律產出）：  'cart-signature:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}'
```

`{regular}` / `{sale}` 為 normalize 後的字串，未覆寫一律是空字串 `''`（絕不可是 `null`，見 `CartPriceOverrideHook::normalize_nullable_price()`）。domain prefix 本身含版本字串（`cart-signature:v1` vs `cart-signature:v2`），這是「兩個版本的 hash 天然不可互通」的來源——v1 簽章硬改成 `v2.` 前綴必須驗證失敗，已有測試鎖死。

**🔴 v1 分支不可移除、不設下架期限。** 理由：登入使用者的**持久化購物車**寫在 usermeta（`_woocommerce_persistent_cart_{blog_id}`），**沒有任何過期機制**，只有結帳完成或清空購物車才會被刪除（相對地訪客 session 只活 2 天、登入使用者一般 session 只活 1 週）。移除 v1 分支 = 那些購物車的簽章驗證失敗 = `strip_profit_meta()` + `reset_to_original_price()` = **分潤價靜默消失，顧客與站長都不會收到任何訊息**，通常要等到對帳才會發現。這不是忘了清的死碼，已登記 `specs/tech-debt.md` TD-35。

**過渡期行為矩陣**（`apply_override()` / `try_apply_for_version()` 的判斷依據）：

| cart item 情境 | 簽章 | regular/sale meta | 套用結果 |
|---|---|---|---|
| 改版前加入、之後沒再加購 | v1 | 無 | ✅ price 套用、regular/sale **不動**（維持改版前行為，價格不退回原價） |
| 改版前加入 + 攻擊者注入假 regular meta | v1 | 有（偽造） | ✅ price 套用、假 regular **強制忽略** |
| 改版後新加購 | v2 | 有 | ✅ price + regular + sale 全套用（劃線價修好） |
| v2 但 regular/sale meta 被刪 | v2 | 缺 | ❌ fallback 原價 + `error_log` |
| v2 且 regular/sale meta 被竄改 | v2 | 有（偽造） | ❌ fallback 原價 + `error_log` |
| v1 簽章硬改成 `v2.` 前綴 | 偽 v2 | 任意 | ❌ fallback 原價 + `error_log` |
| 同一顧客車內 v1 + v2 品項並存 | 混合 | 混合 | ✅ 逐品項獨立判版，兩者都正確 |

**若日後真的要拔掉 v1**，必須先做 v1→v2 就地升級：在 `restore_from_session()`（每個 request 每個品項只跑一次，比 `before_calculate_totals` 便宜很多）偵測到 v1 簽章且驗章通過時，回查賣場、重新解析三元組、改寫成 v2 meta + v2 簽章；等升級路徑上線並跑滿一段時間後才移除 v1。**Phase 13 本身沒有做這件事**（會把 repository 查詢加回熱路徑，且非當時需求）。

### 版本判斷必須用 `payload_version()`，不可用「meta 是否存在」（Phase 13，安全紀律）

`CartPriceSignatureService::verify()` 只回 `bool`，無法告訴呼叫端是哪個版本驗過的。若 `CartPriceOverrideHook` 改用「regular meta 存不存在」判斷要不要套用 regular/sale，會出現這個漏洞：

> 攻擊者保留合法的 v1 簽章（驗證通過），**額外注入**一筆偽造的 `_profit_regular_price_override`。hook 若看到 meta 存在就以為是 v2 → 用 v2 payload 驗 → v2 分支失敗（因為 v1 簽章從未涵蓋 regular/sale）→ 落到 v1 分支 → 只驗 price → **通過** → 於是套用了一個**從未被簽章保護的假原價**。

因此 `CartPriceOverrideHook::try_apply_for_version()` 一律先呼叫 `$this->signatures->payload_version($signature)` 決定分支，**v1 分支無條件忽略任何 regular/sale meta**（即使 cart_item 上出現這兩筆 meta，也視為未提供）——不是靠 meta 是否存在來判斷版本。這條規則在程式碼註解與 `CartPriceSignatureService::verify()` 的 docblock 都有明寫，且有 IT 安全測試鎖死（`test_legacy_v1_signature_ignores_injected_regular_price_meta`）。

### `OverridePriceResolver`：cart / 單品頁的唯一取價真相（Phase 13）

在 Phase 13 之前，「cart 與單品頁的取價語意必須逐字一致」只是**靠人維持的紀律**（程式碼註解要求兩邊複製時要對齊）——這個約定失敗過一次：cart 端的 `resolve_effective_price()` 回傳單一價格字串，結構上丟失了「原價覆寫」，於是購物車劃線原價一直顯示商品的原始 `regular_price`，而單品頁顯示的是覆寫後的原價，兩邊都「正常運作」、沒有任何錯誤訊息。

Phase 13 把這份 fallback chain 抽成 `Domain\Service\OverridePriceResolver`（純 Domain 服務，不依賴 WP / WC，可在 `tests/Unit/Domain/` 不啟 WP 直接測），`CartPriceOverrideHook` 與 `SingleProductPriceHook` 都改為委派它取 `{price, regular, sale}` 三元組。**任何新的取價需求都應該呼叫這個類別，不要再複製一份 fallback chain**——這把「逐字一致」從一條靠人記住的紀律，變成結構上做不到分歧。

Fallback 語意（`OverridePriceResolver::resolve()` class docblock 明寫）：變體只要有**任一**覆寫欄位（原價或特價），就**整組**採用變體層，**不與商品層逐欄混用**。若改成逐欄 fallback，會出現「單品頁顯示商品層特價、購物車實收變體層原價」這種價格跳動——那正是本類要消滅的問題（與 R-8 的「跨層級 regular < sale」是不同層次的取捨：前者是「哪一層生效」，後者是「同一層內 regular 與 sale 的相對大小」，見 `specs/tech-debt.md` TD-34）。

重構驗收標準：`SingleProductPriceHookTest`（11 method）**零修改**全綠——`git diff` 對該測試檔為空是「委派沒有改變單品頁既有行為」的直接證據。

### `is_on_sale()` 翻轉 → `exclude_sale_items` 優惠券行為變更（Phase 13，R-6）

見上方「cart 也要套 regular / sale」的「行為變更」段落——這裡補充成因供日後排查：`WC_Product::is_on_sale()` 的判斷式是 `'' !== get_sale_price() && get_regular_price() > get_sale_price()`；`WC_Discounts` 對 `exclude_sale_items = true` 的優惠券會排除 `is_on_sale()` 為 `true` 的品項。改版前 cart item 只 `set_price()`，`is_on_sale()` 讀的是商品原始的 regular/sale；改版後套用賣場覆寫的 regular/sale，`is_on_sale()` 會如實反映「這個品項現在正在特價中」，因此可能從「可折扣」變成「不可折扣」。若站長回報「排除特價商品」優惠券對分潤賣場商品的行為改變了，這是預期中的正確行為，不是迴歸。

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

### OverrideItem items 契約必須是巢狀，不可扁平化（Phase 7，BUG-A 修正史）

`ProfitShop.items` 的 wire 契約（`OverrideItemInput` / `OverrideItemOutput`，`specs/api/api.yml:905-952`）**一律是巢狀**：

```json
{
  "product_id": 101,
  "inflated_count": 0,
  "override": { "regular_price": "50000", "sale_price": "49900", "signup_fee": null },
  "variations": {
    "1031": {
      "override": { "regular_price": null, "sale_price": "990", "signup_fee": null },
      "inflated_count": 0
    }
  }
}
```

> `variations[vid].inflated_count` 是 **Phase 9（2026-07-30）** 新增欄位，見下方「變體層覆寫」小節。Phase 7 當時的 `variations[vid]` 只有 `override`；本節 BUG-A 敘述維持 Phase 7 原文不動，新舊行為差異記在下方獨立小節，避免把兩個 Phase 的變更混寫在同一段歷史敘述裡。

`ProfitShopHydrator::from_input`（`Application/UseCase/Shop/Support/ProfitShopHydrator.php:113-128`）、`CptProfitShopRepository`（序列化 `:284-295` / 反序列化 `:346-370`）、`ItemValidator::validate`（`:62-76`）**從一開始就只讀寫這個巢狀形狀**，PHP 端沒有任何一處讀取過扁平的 `price_override` / `variation_id` 欄位。

**BUG-A**：前端 `ItemsEditor` 曾經一直送出後端從未定義的扁平欄位 `{ product_id, variation_id, price_override, inflated_count, sku?, name? }`。PHP 端安靜地忽略了這些從未被讀取的欄位（不是驗證失敗，是根本沒有對應的讀取路徑），使用者在後台設定的價格覆寫「儲存成功」（HTTP 200）但實際上從未寫進 DB，重新整理後價格覆寫消失。因為 PHP 端行為完全正常，這個 bug 潛伏了數個 Phase 都沒被抓到。

**根因診斷（Phase 7 planner 逐檔驗證）**：

```bash
grep -rn "price_override" inc/
# 只命中 build_price_override() 方法名（Hydrator/Repository 內部命名）
# 與 Cart hook 的 _profit_price_override meta（不同語意，Phase 3-D HMAC 簽章用途）
# PHP 端沒有任何一處讀取扁平的 price_override / variation_id 請求欄位
```

**修正（Phase 7）**：**PHP 產品程式碼零變更**。前端 `types.ts` 改為送巢狀 `{ product_id, inflated_count, override: {...}, variations: {...} }`（UI 元件細節見 `react.rule.md` §6.5「items 巢狀契約 + 商品顯示資訊補齊」），PHP 端唯一的工作是**補測試鎖契約**——`ProfitShopHydrator` 在 BUG-A 修正前是**零測試覆蓋**，這正是它能潛伏數個 Phase 的根本原因：

- `tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php`（Unit，不啟 WP，**11 method**）：巢狀 `override` 正確寫入 / 缺欄位為 null / `variations` 數字鍵 / **扁平 `price_override` 被忽略（回歸鎖）** / `product_id <= 0` 靜默跳過 / 字串 `'101'` product_id 轉 int / 非法價格拋 `InvalidPriceOverride`
- `tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php`（IT，**10 method**）：PUT 巢狀 items → GET 斷言原樣還原；`sale > regular` → 400；不存在 product → 404；未包含的商品視為整體覆寫（被移除）
- `tests/e2e/06-profit-shop/profit-shop-items.spec.ts`（API-level，**4 case**）：PUT 巢狀 items → GET 還原；PUT 扁平 items → 斷言價格不被寫入

**三層鎖死 BUG-A 復發**：扁平契約想復活，會同時被三個層級擋下——Unit（`ProfitShopHydratorTest` 11 method）驗證 Hydrator 本身只認巢狀輸入、Integration（`ProfitShopItemsRoundTripTest` 10 method）驗證 HTTP round-trip、e2e（`profit-shop-items.spec.ts` 4 case）驗證真實部署環境下的行為。三層各自獨立、覆蓋範圍不重疊（單元邏輯 / API 契約 / 端到端），任何一層被繞過、誤刪、或未來重構時漏改，另外兩層仍會抓到回歸——這是刻意的縱深防禦設計，不是重複勞動。

**型別選擇**：`override.regular_price` / `sale_price` / `signup_fee` 都是 `string | null`，不是 `number | null`——`PriceOverride` VO 本身是 nullable **string**（`PriceOverride.php:34-48`），用 `bccomp` 走十進位字串比較避免浮點誤差（`PriceOverride.php:66`）。前端若「簡化」改回 `number` 會需要在邊界處重新序列化成字串，且會重新引入十進位 / 浮點誤差風險——**不要**這樣做。

**契約並存期風險評估（低風險，已確認）**：前後端同一 plugin bundle 同時發版，不存在「舊前端 + 新後端」或「新前端 + 舊後端」的過渡期。即使存在，PHP 本來就忽略扁平欄位，行為等同現況，不會更差。DB 內既有 items 資料一直是巢狀 + null（舊前端送的扁平值從未寫入），**不需要 migration**，IT round-trip 測試驗證既有資料可正常讀取。

---

### 變體層覆寫：VariationOverride VO + 委派法 + SingleProductPriceHook（Phase 9）

賣場編輯頁「賣場商品」表格可展開 `variable` / `variable-subscription` 商品，逐一設定每個
變體的原價 / 特價覆寫與灌水購買人數。`OverrideItem::$variations` 的 value 型別因此從
`PriceOverride` 升級為 `VariationOverride`（`Domain/ValueObject/VariationOverride.php`）：

```php
final class VariationOverride {
    public function __construct(
        public readonly PriceOverride $override,
        public readonly InflatedCount $inflated_count
    ) {}
}
```

**為什麼不直接把 `inflated_count` 塞進 `PriceOverride`**：`PriceOverride` 是純粹的三欄價格
VO，同時被 `PriceCalculator` 的六層 fallback chain 與 `ProductSnapshot` 使用；混入與價格無關
的銷量欄位會污染它在那些場景的語意，所以另外包一層 `VariationOverride` 聚合兩者。

#### `get_variation_override()` 簽章不可改——這是委派法能讓下游零改動的關鍵

`OverrideItem::get_variation_override( int $variation_id ): ?PriceOverride` 改用**委派**
取值，但**回傳型別維持 `?PriceOverride` 不變**：

```php
public function get_variation_override( int $variation_id ): ?PriceOverride {
    return ( $this->variations[ $variation_id ] ?? null )?->override;
}
```

`PriceCalculator::calculate()` 與 `CartPriceOverrideHook::resolve_effective_price()` 只需要
價格，兩者因此**零改動**——`git diff --stat` 對這兩個檔案完全空白。若有人「順手」把回傳型別
改成 `?VariationOverride`（例如想省一次 `->override` 存取），這兩處呼叫端會靜默拿到物件而非
價格，PHP 不會報型別錯誤（弱型別 `?->` 呼叫鏈仍會執行），只會在後續運算把物件當字串用時
出現非預期結果——最終效果是**變體覆寫價靜默退回原價 = 商家損失**，且沒有任何錯誤訊息可查。
`tests/Unit/Domain/Entity/OverrideItemTest.php::test_get_variation_override_still_returns_price_override_after_delegation`
用 `assertNotInstanceOf` 鎖死這個簽章契約。

**真實環境已實測驗證**（`wp eval` 直接讀 Repository，非自動化測試）：對一筆帶變體覆寫的真實賣場資料
`readback` 出 `vid=79 override=999/888 ... [override class: PriceOverride]`、
`vid=86 override=777/null ... [override class: PriceOverride]`、未覆寫的
`vid=80 override=NULL ... [override class: -]`——委派後 `get_variation_override()` 回傳的
物件 class 確實是 `PriceOverride`（不是 `VariationOverride`），簽章契約在真實 WordPress 執行環境
（非測試 fixture）成立，與單元測試互為佐證。

注意 `( $this->variations[ $variation_id ] ?? null )?->override`（先 `??` 再 `?->`）而不是
直接寫 `$this->variations[ $variation_id ]?->override`——後者在鍵不存在時仍會觸發 PHP 的
「Undefined array key」Warning（`?->` 只抑制「呼叫方法/屬性於 null」的錯誤，不抑制陣列存取
本身的 Warning），先 `?? null` 正規化成明確的 `null` 才能安全串接 `?->`。

#### `CptProfitShopRepository` 的 `?? 0` 是舊資料唯一的接住點，重構時不可移除

Phase 9 之前寫入的 `_profit_shop_items` postmeta，`variations[vid]` 只有 `override`、沒有
`inflated_count` 鍵。`CptProfitShopRepository::build_override_item()` 反序列化時：

```php
$v_inflated_raw = isset( $variation_row['inflated_count'] )
    ? (int) $variation_row['inflated_count']
    : 0;
```

缺鍵時讀為 `0`（語意正確：沒設定過灌水人數 = 0），下次儲存時該筆自然補上這個鍵，
**不需要 migration**。這行 `?? 0`（實際寫法是 `isset() ? … : 0`）是舊資料唯一的接住點，
拿掉它會讓帶舊格式資料的賣場一律讀取失敗或拋錯。鎖死的測試：
`CptProfitShopRepositoryTest::test_legacy_variations_json_without_inflated_count_defaults_to_zero`
（同時斷言價格仍讀得出來，避免「整筆物件被丟棄所以碰巧得到預設值 0」的偽綠燈）與
`test_legacy_variations_json_is_upgraded_on_next_save`（證明重新儲存後該筆資料自然補齊，
不需額外的資料轉換腳本）。

#### `OverrideItem` 建構子 fail-fast 型別守衛

`OverrideItem::__construct()` 對傳入的 `$variations` 逐一檢查每個 value 是否為
`VariationOverride` 實例，不是就立即拋 `\InvalidArgumentException`：

```php
foreach ( $variations as $variation_id => $variation ) {
    if ( ! $variation instanceof VariationOverride ) {
        throw new \InvalidArgumentException( /* ... */ );
    }
    $this->variations[ (int) $variation_id ] = $variation;
}
```

**理由**：PHP 的 docblock 型別（`array<int, VariationOverride>`）只是給 IDE / PHPStan 看的
文件式標註，語言本身**無法在 runtime 檢查 array 的 value 型別**。如果日後有任何一處呼叫端
不小心混入升級前的舊型別（`PriceOverride`）或其他物件，`get_variation_override()` 委派取
`->override` 只會安靜地得到 `null` 加一則 PHP Warning，**不會拋出任何例外**——下游看到的
結果是「這個變體的覆寫價消失了」，`CartPriceOverrideHook` 因此退回商品原價，商家蒙受損失
卻毫無警訊。這與 BUG-A（前端送扁平契約，PHP 安靜忽略）、cart signature normalize 兩端不一致
（`wc_format_decimal` 正規化沒對齊，簽章驗證安靜失敗退回原價）是**同一種失敗模式**——這個
codebase 已經在兩個不同場景吃過虧：資料形狀錯誤時，寧可在建構當下就 fail-fast 拋例外，
也不要讓它在深層呼叫鏈裡安靜退化成「看起來正常運作，其實悄悄少算了錢」。
`OverrideItemTest::test_construct_rejects_non_variation_override_values` 鎖死此行為。

#### `SingleProductPriceHook`：WC 單品頁套用賣場覆寫價（含變體），解決「價格跳動」

在 Phase 9 開放變體覆寫 UI 之前，`Infrastructure/WooCommerce/` 內只有 3 個 hook
（`AddToCartHook` / `CartPriceOverrideHook` / `CartItemMetaDisplay`），全部只作用於購物車。
顧客的實際動線因此存在落差：賣場前台卡片顯示 override 價 → 點「前往選購」進 WC 單品頁 →
單品頁顯示**商品原始價格**（本外掛零介入）→ 選變體加入購物車 → 購物車又變回 override 價。
這個「單品頁一個價、購物車另一個價」的落差過去一直存在，但因為後台從未開放設定變體覆寫，
沒有人踩過；Phase 9 一開放變體覆寫 UI，這個落差就變成顧客可見的信任問題（原計畫 R-1）。
`Infrastructure/WooCommerce/SingleProductPriceHook.php` 補上這塊，三條紀律缺一不可：

1. **hook 名稱要對，價格區間與單一價格是兩組不同 filter**：WC 的
   `WC_Product_Variable_Data_Store_CPT::read_price_data()` 讀取子變體價格區間時用的是
   `'edit'` context（`class-wc-product-variable-data-store-cpt.php:319-321`），
   `woocommerce_product_variation_get_price` 這系列 filter **只對 `'view'` context 生效**，
   對「價格區間」（`get_available_variations()` 的 `display_price` 之外，還有
   `get_variation_price('min'/'max')` 這類彙總值）**無效**。要覆寫價格區間必須另外掛
   `woocommerce_variation_prices_price` / `_regular_price` / `_sale_price`（三參數簽章
   `($value, $variation, $parent)`）。本類**兩組都掛**——`woocommerce_product_get_price`
   系列覆寫「已選定單一變體」的實際結帳價，`woocommerce_variation_prices_*` 系列覆寫價格
   區間顯示。漏掉任何一組都會出現「單品頁顯示的價格區間」與「選定某個變體後看到的價格」
   不一致。
2. **必須把賣場 ID 併入 `woocommerce_get_variation_prices_hash`**：WC 把變體價格陣列快取在
   transient，`get_price_hash()`（`abstract-wc-product-data-store-cpt.php:433-445`）組
   hash 時只納入掛在 `woocommerce_variation_prices_price` 等 filter 上的**callback 名稱**，
   **不含賣場 ID**。若不處理，「賣場 A 覆寫商品 X 的變體價」與「賣場 B 覆寫同一商品 X 的
   變體價」會算出同一把 hash key，兩個賣場會**互相讀到對方的快取價格**——一般訪客因為
   `$wp_filter` 為空、hash 自然與賣場情境不同而不受影響，問題只發生在「同一個變體商品被
   多個賣場各自覆寫不同價格」的情境。`filter_variation_prices_hash()` 把 `shop_id` 塞進
   hash 來源陣列，讓每個賣場各自落在獨立的快取分桶。
3. **filter 只在 `is_product() && 帶合法 profit_shop_id` 時才註冊，不可改成常駐**：
   `maybe_register_filters()` 掛在 `template_redirect` priority 20（晚於 `ProfitShopRenderer`
   的 9），且**只在確認是單品頁、profit_shop_id 有效、賣場 `status='publish'`、目前商品
   確實屬於該賣場**（三道閘門與 `AddToCartHook` 同一條紀律）之後才呼叫 `add_filter`。
   若改成一律註冊（不分頁面類型），`woocommerce_product_get_price` 這類 filter 在 **view
   context** 會把價格蓋回覆寫價，等於**抵銷了 `CartPriceOverrideHook` 在驗章失敗時刻意
   fallback 回商品原價的安全保護**——那個 fallback 存在的意義就是「簽章對不上就不能相信
   這個覆寫價」，若單品頁的 filter 不看簽章、無條件套用，這道防線會被繞過。

⚠️ **本段（Phase 9 原文）已被兩次改動疊加，讀的時候要一起看 §12.8**：
(1) 上面第 3 點說的三道閘門，2026-08-06 第 6 輪起是**四道**——多了「賣場在販售期間內」
（TD-57；缺了它，期間外仍可從單品頁把無定價變體加進購物車，且**零分潤歸屬**）。
(2) 🔴 **「只在對的時機註冊」不是唯一的防線，也不足以保證安全**：filter 一旦在
`template_redirect` prio 20 掛上就**整個 request 都不解除**，而購物車與商品頁**在同一個
request 裡並存** ⇒ 曾實測到一筆完全沒有簽章的 cart item 被套上覆寫價（1000 → 27）。
真正的防線是 §12.8 那條不變量 + `is_cart_item_object()` 的**物件身分**比對（TD-62）。
(3) 下一段提到的 `resolve_effective_price()` 已於 Phase 13 更名為 `resolve_override_prices()`
並改為委派 `OverridePriceResolver`；「逐字一致」現在是**結構保證**，不再是靠人維持的紀律。

**價格語意必須與 cart 端逐字一致**：變體只要有
任一覆寫欄位（原價或特價），就**整組**採用變體層的 override，**不與商品層逐欄混用**。
`SingleProductPriceHook::resolve_prices()` 的判斷順序（先看變體層 `sale_price` /
`regular_price` 是否非 null，有就整組回傳變體層值；否則才 fallback 到商品層 override）
與 `CartPriceOverrideHook` 完全對齊。若改成逐欄位各自 fallback（例如變體只設了特價，原價
去讀商品層），會出現「單品頁顯示商品層特價 500、購物車實收變體層原價 600」這種本階段
存在的目的就是要消滅的價格跳動。

新增測試：`tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php`
（11 method）：simple 商品覆寫 / 只設原價保留原特價 / 變體覆寫僅作用於該變體 / 變體覆寫不與
父層逐欄混用 / 賣場不存在或非 publish 不覆寫 / 商品不屬於賣場不覆寫 / 無 query string 不
註冊 filter / 非單品頁不註冊 filter / hash 依賣場區隔 / hash 對其他商品不受影響。

**真實環境端到端驗證**（人工在瀏覽器操作，非自動化測試；臨時測試賣場設定：商品 63 Hoodie，
變體 79 覆寫原價/特價 `999/888`、變體 86 只覆寫原價 `777`（不設特價）、變體 80/81 不覆寫；
驗證後臨時賣場與購物車項目皆已清除，站台資料回到驗證前狀態）：

| 驗證項 | 結果 |
|--------|------|
| 單品頁價格區間（帶 `?profit_shop_id`） | `NT$45 – NT$888`（原始應為 `NT$42 – NT$45`）——最低價從 42 變成 45，因為變體 79 的特價 42 被覆寫掉不再是最低值，這個「區間端點位移」是 filter 生效的指紋 |
| `get_available_variations()` 逐變體 `display_price` / `display_regular_price` | 變體 79：888 / 999（覆寫生效）；變體 86：777 / 777（只設原價，`get_sale_price` 正確回 `''` 而非沿用商品原特價，未渲染出假的劃線價）；變體 80 / 81：45 / 45（未覆寫，維持原樣） |
| **負向驗證（最關鍵）**：不帶 `?profit_shop_id` 時 | 價格區間與逐變體價格**全數回到原始值**（`42–45`，79=42、86/80/81=45），零覆寫殘留。同時證明兩件事：filter 只在帶合法 query string 時才註冊（不常駐）；`woocommerce_get_variation_prices_hash` 改對了（沒改的話這裡會讀到 `45–888` 的污染快取） |
| 三處價格一致性 | 賣場前台卡片 → 單品頁（888）→ 加購變體 79 後 Store API `/wc/store/v1/cart` 的 `price` / `line_total` / `total_price` 皆為 `"888"`，三處完全一致 |

商品層 `inflated_count` 前台顯示（第六階段）也已人工驗證：臨時賣場商品 63 設定 `inflated_count: 7`、
真實銷量 873，賣場前台實際顯示「已售 **880** 件」＝ 873 + 7，數字精確吻合；一般賣場（`test-shop-alpha`）
實測 `[E2E] Test Product` 顯示「已售 4 件」、`銷售方案` 顯示「已售 2 件」，`inflated_count` 為
0 的商品（`T-Shirt` / `E2E Frontend Test Course`）**不顯示**「已售 0 件」，`> 0` 守門正確；
`<title>` SEO meta 也確認正確（「測試賣場 Alpha｜測試夥伴 Alpha 的分潤賣場 - turbo」）。

#### ⚠️ 一條會重複發生的失誤模式：規劃期寫的「緩解文案」與後續才拍板的「加強措施」在同一次改動內並存時，文案不會自動跟著更新

第五階段規劃當時，`SingleProductPriceHook`（第六-B 階段）**是否要做**還沒拍板，所以規劃文件先在
`VariationOverrideEditor` 展開列加了一句「據實揭露」：變體覆寫價只在購物車生效、單品頁仍顯示原價。
後來團隊決定連第六-B 一起做，`SingleProductPriceHook` 上線後單品頁**已經**顯示覆寫價，但那句
「仍顯示原價」的文案**沒有人回頭去改**——第一輪文件同步只顧著記錄新功能，直到真的在真實環境把
第六-B 端到端跑過一次（單品頁區間 `42–45` → `45–888`）才發現這句話已經與事實相反。

**這不是本次唯一一次會發生的情況**：任何規劃文件裡「先寫『目前還沒做到 X，所以要跟使用者說 Y』」
的緩解文案，只要後續同一次或下一次改動把 X 做掉了，Y 就必須跟著改，而且**改文案這件事不會被
任何自動化閘門攔到**——tsc / eslint / phpunit 都不會因為一句過時的中文說明而變紅。

**規則**：往後任一次改動讓 `SingleProductPriceHook` 的作用範圍變化（例如額外覆蓋 grouped /
external 商品類型、或反過來收斂範圍），一併檢查 `VariationOverrideEditor` 與其他任何帶有
「單品頁 / 商品單頁是否顯示覆寫價」語意的 UI 文案（`<Alert>` / `<Tooltip>` / 說明文字）是否仍然
成立；新增任何「目前做不到 X」的據實揭露文案時，最好在旁邊的程式碼註解註明「這句話的前提是
Y 尚未實作，Y 做完後必須回來改這裡」，讓下一個動到 Y 的人有機會看到提醒。

---

### items 的陣列順序 = 賣場前台的商品顯示順序（全鏈路保序）

`ProfitShop.items` 是 **list（順序陣列）而非 map**，排列順序本身就是business data，
不是實作細節：

```
前端拖曳排序 → PUT items[] → ProfitShopHydrator::from_input（foreach + $items[] =，保序）
  → CptProfitShopRepository::persist_meta（array_map，保序）→ wp_json_encode（JSON array，保序）
  → 讀回 hydrate（foreach，保序）→ templates/profit-shop-front.php（foreach $items，依序渲染）
```

**任何一層改用 `product_id` 當 key、或中途 `usort` / `ksort`，拖曳排序就會靜默失效**
（存檔回 200、GET 也拿得到資料，只是順序跑掉，非常難察覺）。
`ProfitShopItemsRoundTripTest` 有兩條測試鎖住這個契約：
`test_items_order_is_preserved_through_round_trip`（送什麼順序讀回就是什麼順序）與
`test_reordered_items_persist_the_new_order`（重新排序後新順序取代舊順序）。

前端拖曳實作與分頁限制見 `react.rule.md` §6.5「`ItemsEditor` 商品拖曳排序」。

### Shortcode 模式：短碼已可用，但公開網址**仍然開得起來**（TD-13 只解決一半）

`ProfitShopShortcode`（`Infrastructure/WordPress/ProfitShopShortcode.php`）註冊
`[power_shop_profit_shop id="{post_id}"]`，站長可把賣場商品列表嵌入任意頁面內容。
`#/profit-shop` 列表頁對 shortcode 模式的賣場顯示「複製」按鈕（而非「開啟」），
按下即複製這串短碼。

**但 TD-13 只解決了前半**。`ProfitShopRenderer::maybe_render` 依然**完全不檢查
`mode`**（只看 slug 與 status），所以：

| | 現況 |
|---|---|
| 短碼可用性 | ✅ 已實作，貼進頁面能渲染 |
| `/profit-shop/{slug}/` 公開網址 | ⚠️ **仍然開得起來、仍會被搜尋引擎索引** |

刻意不一起做 Renderer 的 mode 檢查：那會讓**既有已上線**的 shortcode 模式賣場前台網址
突然變 404，blast radius 遠大於本次需求。`specs/tech-debt.md` TD-13 已更新為「部分解決」。

因此 `BasicTab.tsx` 那句「Shortcode 模式仍會保留公開網址」的警語**依然成立、不可移除**
——**不要**因為列表頁現在有「複製」按鈕、看起來功能完整了，就把它當成過時文案清掉。
UI 入口有無，與網址本身是否存在，是兩件不能混為一談的事（同一條紀律的兩種呈現：
`BasicTab` 在頁面內據實告知網址仍存在，列表頁只是不提供「直接開啟」的捷徑）。

**兩個 resolve 函式刻意不合併**（`List/index.tsx`）：

| 函式 | 檢查項 | 為什麼不同 |
|------|--------|-----------|
| `resolveOpenShopBlockedReason` | 無 slug／已 trash | 「開啟」需要組出 `/profit-shop/{slug}/`，沒 slug 就組不出網址 |
| `resolveCopyShortcodeBlockedReason` | **只**檢查已 trash | 短碼以 **`id`** 定位賣場，**沒設 slug 的賣場一樣複製得出可用的短碼** |

看起來 80% 相同，但那個 slug 條件是**唯一且關鍵**的差異，為了 DRY 併成一個函式會讓
「沒設永久連結的 shortcode 賣場」白白失去複製入口。

**為何短碼用 `id` 而非 `slug`**：id 是不變的 post ID；slug 可被站長隨時改動，而**已經貼進
post_content 的短碼是純文字、不會隨之更新**。同理，`ProfitShopShortcode::TAG` 這個 tag
字串一旦改名，所有已發佈頁面裡的舊短碼會安靜地渲染不出東西——WordPress 對未註冊的
shortcode 是**原樣輸出純文字、不報錯**。真要改 tag 必須在 PHP 端保留舊 tag 當 alias。
前端 `PROFIT_SHOP_SHORTCODE_TAG`（`types.ts`）與此常數是同一個硬契約的兩端，改一邊必須改另一邊。

**共用 template 的外層標籤：Phase 14 起兩條路徑統一為 `<div>`**（歷史：Phase 4-C～Phase 13
`templates/profit-shop-front.php` 曾靠 `$is_shortcode_context` 旗標在 `<main>` /
`<div>` 之間切換，`ProfitShopRenderer` 用 `<main>`、`ProfitShopShortcode` 用
`<div>`）。旗標已移除，外層**一律 `<div>`**——理由是 HTML spec 規定一份文件內只能有一個
非 hidden 的 `<main>`，shortcode 情境宿主頁面已經有自己的 `<main>`，巢狀是無效 HTML；
而獨立頁面走 theme 的 `header.php` 時**同樣可能已經有 `<main>`**（取決於 theme 實作），
統一用 `<div>` 才是對兩條路徑都安全的做法，不必去猜每個 theme 的行為。
`ProfitShopShortcodeTest::test_renderer_and_shortcode_paths_render_identical_dom` 逐字比對
兩條路徑的完整輸出（不只比外層標籤），是比舊版兩條各自鎖一個方向更嚴格的回歸鎖；已失去
前提的 `test_template_defaults_to_main_wrapper_without_flag` 隨旗標移除一併刪除。

### 兩條渲染路徑必須傳同一組變數（Phase 14，商業邏輯漏洞的教訓）

`templates/profit-shop-front.php` 同時被 `ProfitShopRenderer::render_shop()`（獨立頁面）與
`ProfitShopShortcode::render_template()`（shortcode）`require`，**兩者必須傳入完全相同的
變數集合**（`$shop_for_template` + `$front_settings`），且都要呼叫
`ProfitShopFrontView::enqueue()`。這不是風格一致性問題，是真實發生過的商業邏輯漏洞：

> 早期只有 `ProfitShopRenderer` 會組出 `$front_settings` 並傳入 template；
> `ProfitShopShortcode` 路徑完全沒傳，template 內 `isset($front_settings)` 判斷失敗，
> fallback 呼叫 `ProfitShopFrontData::settings($shop)` 補算——理論上應該補算出一樣的結果，
> 但當時的程式碼版本**沒有**這個 fallback，`$settings` 直接是空陣列，
> `$settings['shopStatus'] ?? 'published'` 於是**永遠**回傳 `'published'`。
> 結果：**已結束販售（或尚未開賣）的賣場，用 shortcode 嵌入頁面後照樣顯示可加購的按鈕與表單**，
> 而獨立頁面网址（`/profit-shop/{slug}/`）同一個賣場卻正確顯示「商店已關閉」。
> 兩條路徑「都正常運作、沒有任何錯誤訊息」，只有實際去比對兩者輸出才會發現分歧。

同一次修正也補上早期 shortcode 完全沒呼叫 `ProfitShopFrontView::enqueue()`
的缺陷（商品 Modal 開不起來）。**任何新增 / 修改 `render_shop()` 或
`render_template()` 傳給 template 的變數時**，必須同步檢查另一條路徑是否也傳了同一個
變數；`test_renderer_and_shortcode_paths_render_identical_dom` 是這條紀律的自動化防線，
但寫程式碼時不該依賴測試才發現，改動前先確認兩個方法傳入 template 的變數清單一致。

**閉店賣場的 Modal 整段不印，不是「印出來但 disabled」**：`templates/profit-shop-front.php`
只在 `$is_shop_open` 為真時才 `require partials/profit-shop-modal.php`。Modal 內含一張真正
的 `<form method="post">`，即使加購按鈕 `disabled`，表單本身仍然存在於 DOM 內，使用者只要
用瀏覽器開發工具改掉 `disabled` 屬性就能送出——而 `AddToCartHook` 的三道閘門只驗證「賣場
存在 / `status='publish'` / 商品屬於該賣場」，**完全不檢查販售起訖時間**，那道防線接不住
「賣場狀態是 publish 但已過結束時間」這種情境。因此**不印出來**才是唯一可靠的做法（卡片
層面則是按鈕本身停用 + 不綁 click，屬於 UI 層防呆，Modal 層面才是真正擋住表單存在本身）。
`test_shortcode_respects_closed_shop_status` 鎖死「已結束販售的賣場輸出內不得含
`name="add-to-cart"`」。

**shortcode callback 的四條紀律**：

1. **一律 `return` 字串、永不 `echo`**——shortcode 若直接輸出，內容會跑到頁面最前面
   （`the_content` 之外）。實作用 `ob_start()` + `require` + `ob_get_clean()`，
   且 `require` 包在 try/catch 內，渲染拋例外時 `ob_end_clean()` 收掉緩衝並回空字串，
   不讓宿主頁面壞掉
2. **id 驗證與 `AddToCartHook` 同一條紀律**：`ctype_digit` 純數字檢查 + 額外拒前導零
   （`'007'`），避免「看起來像數字」的字串被 `(int)` 悄悄轉成合法 ID
3. **status 可見性與 `ProfitShopRenderer::maybe_render` 逐字一致**：publish 對外可見；
   非 publish 只有 `current_user_can( 'edit_post', $shop->id )` 為 true 才渲染
   （**特定 post id** 的 ownership 檢查，不是 `edit_posts`）
4. **失敗訊息不洩漏賣場存在性**：一般訪客一律拿到空字串；有 `edit_posts` 能力者才看得到
   除錯提示，且「找不到 / 已刪除」與「未發佈且無預覽權限」**共用同一句訊息**——否則
   只有 `edit_posts`（例如 Author）的使用者能靠訊息差異推斷出草稿賣場的存在

不注入 SEO meta（`<title>` / canonical）：宿主頁面自己有，注入會造成雙 title。

### Partner 夥伴圖片：只持久化 image_id，image_url 是唯讀衍生欄位

Partner 夥伴圖片存於 termmeta `_partner_image_id`（attachment ID），**絕不持久化 URL**：

| 欄位 | 層 | 方向 | 說明 |
|------|----|------|------|
| `image_id` | `PartnerInput` / `PartnerSnapshot` / `PartnerOutput` | 讀 + 寫 | attachment ID；`null` / `0` / 負數一律正規化為 null，並**刪除** termmeta（= 移除圖片） |
| `image_url` | `PartnerSnapshot` / `PartnerOutput` | **只讀** | `PartnerTermRepository::hydrate_from_term` 以 `wp_get_attachment_image_url($id, 'thumbnail')` 即時解析 |

**三條紀律：**

1. **`save()` 一律忽略傳入的 `image_url`**——URL 在站台換網域 / 換 CDN / 換圖片尺寸設定後會失效，attachment ID 才是穩定真相來源。`PartnerTermRepositoryTest::test_save_persists_image_id_and_resolves_url_on_read` 刻意傳入假 URL 鎖死這個行為
2. **孤兒 attachment 不自動清除**：attachment 被刪除時 `image_url` 回 null 但 `image_id` termmeta **保留**——讀取路徑不可有寫入副作用，且站長從媒體庫救回檔案後圖片能自動復原。因此「`image_id` 非 null 但 `image_url` 為 null」是**合法狀態**，前端須退回預設頭像（`ProfitPartner/Edit` 的 `setFieldsValue` 對這種情況填 `null`，讓站長重新挑一張而非顯示破圖）
3. **`PartnerInput` 不收 `image_url`**：wire 契約只有 `image_id`（`specs/api/api.yml` 的 `PartnerInput`），避免前端誤以為可以覆寫顯示 URL

前端對應元件（複用商品 `Gallery` / `PartnerSelector` 頭像 / 列表夥伴圖片欄）見 `react.rule.md` §6.5。

### 全域設定頁已整組移除——「有消費點」不等於「該讓站長改」

`#/profit-settings` 設定頁、`SettingsDto`、三個 Settings UseCase、`SettingsRepository`、
`RewriteRulesFlusher` 與 `GET/PUT/POST /power-shop/profit-settings` 三個 endpoint **全部刪除**。
這頁存在期間一共放過四個欄位，四個都被移除，理由分兩類：

**第一類：零消費點**（`default_rate` 預設分潤比例 / `page_template` 頁面模板）

從 Phase 3-B 起就沒有任何一處讀取——`grep -rn "default_rate\|page_template" inc/` 除了 DTO
自己的定義之外一處都沒有。新建賣場不吃 `default_rate`（`rate` 一律由 `ProfitShopInput` 帶），
賣場前台固定 `require templates/profit-shop-front.php`，根本沒有讀 `page_template` 的分支。
這種「存得進去、讀得出來、但沒人用」的欄位比缺功能更糟——它讓站長以為自己已經設定好了。

**第二類：有消費點，但站長改了只會弄壞東西**（`report_slug` / `rewrite_slug`）

| 欄位 | 真實消費點 | 為何仍不該開放 |
|------|-----------|---------------|
| `report_slug` | `add_rewrite_rule` pattern + `PartnerSnapshot.report_url` | 一改就讓**所有已經發給 partner 的報表網址一次失效**，而站長換個前綴得不到任何實質好處 |
| `rewrite_slug` | `register_post_type` 的 `rewrite.slug` | 它產生的 `/{slug}/{post-name}/` 是條**沒有任何程式碼連過去**的半殘網址（見下） |

`rewrite_slug` 那條的推導過程值得記下來，因為光看欄位名會以為它很重要：

1. 全專案 `grep get_permalink inc/` 三處命中**全是商品**（`$item->product_id`），
   沒有任何一處組出或連到 powershop CPT 的 permalink
2. `ProfitShopRenderer::maybe_render` 只在 query var `profit_shop_slug` 有值時才渲染
   （`ProfitShopRenderer.php:112`），而 `/powershop/{post-name}/` 那條 query var 是空的
3. 商品 grid 只存在於 `templates/profit-shop-front.php`，唯一的 `require` 點就是該 renderer
   （另一個是 `ProfitShopShortcode`，但那要短碼）
4. ⇒ `/powershop/{post-name}/` 打開只會拿到**佈景主題的 single template + `post_content`**：
   沒有商品、沒有價格、沒有加購，而且照樣被搜尋引擎索引

讓站長設定一條「沒人連過去、內容半殘」的網址沒有任何正面價值。

**現況**：兩者都是固定常數（`CptRegistrar::DEFAULT_REWRITE_SLUG = 'powershop'` /
`RewriteRules::DEFAULT_REPORT_SLUG = 'profit-report'`），與賣場前台的
`SHOP_REWRITE_PREFIX = 'profit-shop'` 同性質。`get_rewrite_slug()` / `get_report_slug()`
兩個 method **都保留**（單一取值入口，caller 不需知道背後是設定值還是常數），
但改為直接回傳常數、完全不讀 wp_options。

wp_options 內殘留的 `power_shop_profit_settings` 成為孤兒，無人讀寫，**不需要 migration**。

**回歸鎖**（防這頁被重新加回來）：
- `V2ApiRoutingTest::test_profit_settings_routes_are_removed`（IT）：三個 route 都不該註冊
- `FlushRewriteTest::test_slugs_ignore_stale_settings_option`（IT）：wp_options 有殘留設定時，
  三個前綴仍回常數——防止「rewrite rule 用常數、`report_url` 用殘留值」的兩份真相

### rewrite rules 的 flush 改為版本比對，掛 `init` 自動觸發

移除設定頁時**必須**一併處理的配套，否則會留下比原本更嚴重的問題。

舊機制：`RewriteRules::flush_rules_if_needed()` 比對「設定裡的 slug 是否與上次套用不同」，
唯一觸發點是 `UpdateSettings` / `ResetSettings`（= 站長在設定頁按儲存）。

這裡藏著一個**既有缺陷**：全專案**沒有 `register_activation_hook`**
（`grep -rn "flush_rewrite_rules\|register_activation_hook" inc/ plugin.php` 可自行確認），
所以那個藏在設定頁儲存流程裡的呼叫是全專案唯一的 `flush_rewrite_rules()`。新站台安裝後，
若站長從未進設定頁按過儲存，`/profit-shop/{slug}/` 與 `/profit-report/{slug}/`
會直接 404，且沒有任何提示說明原因。刪掉設定頁 = 連這個意外的入口都沒了。

新機制：

```php
private const REWRITE_VERSION = '1';                                    // 改規則時 bump
private const APPLIED_VERSION_OPTION = 'power_shop_profit_applied_rewrite_version';

// __construct()
\add_action( 'init', [ __CLASS__, 'register' ], 12 );
\add_action( 'init', [ __CLASS__, 'flush_rules_if_needed' ], 13 );      // 晚一階，確保 register 已跑
```

三條紀律：

1. **改動任何 rewrite rule（新增 / 移除 / 改前綴常數）時必須 bump `REWRITE_VERSION`**，
   否則既有站台不會重新 flush，新規則安靜失效（網址 404、無錯誤訊息）
2. **`APPLIED_VERSION_OPTION` 必須 autoload = true**（`update_option` 第三參數）——
   `flush_rules_if_needed()` 每個請求都要讀它，走 alloptions cache 才不會每次多一次 DB query。
   版本相同時直接 return，不會呼叫昂貴的 `flush_rewrite_rules()`
3. **這個機制的前提是「rewrite 規則全是常數」**。若日後又把某個前綴變回可設定值，
   版本比對就不成立了（會出現「同版本號、不同規則」的站台），必須改回值比對或兩者併用

測試：`FlushRewriteTest`（IT，4 method）——全新安裝會 flush 並記錄版本 / 版本相同時
early return 不 flush / 版本不同時重新 flush / 殘留設定不影響前綴常數。

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

Phase 7 新增測試（賣場編輯頁商品選擇器，PHP 端僅測試、無產品程式碼變更）——三層鎖死 BUG-A 復發，見 §7「OverrideItem items 契約」：
- `tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php`（**11 method**）：`ProfitShopHydrator` 首次測試覆蓋（BUG-A 潛伏數個 Phase 的根本原因）
- `tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php`（**10 method**）：items 巢狀契約 PUT → GET round-trip，對應 `save-shop-item-overrides.feature` 全 7 Rule
- `tests/e2e/06-profit-shop/profit-shop-items.spec.ts`（**4 case**）：API-level（沿用既有 `request.put` + `X-WP-Nonce` pattern），鎖死「扁平 items 契約已死」——PUT 扁平欄位 → 斷言價格不被寫入

**實測結果**（2026-07-29）：Unit 338 / 669 assertions / 1 failure（327 baseline + 11 新增）；Integration 168 / 723 assertions / 21 failures（158 baseline + 10 新增）；e2e `06-profit-shop` 4 passed；e2e `01`～`05` 162 passed 零 regression。`composer test` 的 21 個 integration 失敗 + 1 個 unit 失敗**全部 pre-existing**，與 Phase 7 改動無關（已用「移出新測試檔案再單獨跑」的方式驗證）。PHP 測試環境（`.wp-env.json` / `tests/bootstrap.php` / `@group` 陷阱）見 `wordpress.rule.md` §11；完整已知問題清單見 `specs/tech-debt.md`。

Phase 9 新增測試（賣場商品變體層價格 / 灌水人數覆寫）：
- `tests/Unit/Domain/Entity/OverrideItemTest.php`（**+8 method，共 19**）：`VariationOverride` 建構 / `set_variation_override` 新增與覆寫 / `remove_variation_override` / `get_variation_inflated_count` / `to_array` 新形狀（含 `inflated_count`）/ **`get_variation_override` 委派後仍回 `?PriceOverride`**（`assertNotInstanceOf` 鎖死簽章）/ 建構子對非 `VariationOverride` 值 fail-fast / `set_variation_override` 第三參數 null 時保留既有灌水數
- `tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php`（**+4 method，共 15**）：variations 迴圈建 `VariationOverride`、缺 `inflated_count` 鍵預設 0、負數正規化、既有 11 method 全綠即證委派法未破壞 BUG-A 回歸鎖
- `tests/Integration/Infrastructure/Persistence/CptProfitShopRepositoryTest.php`（**+2 method，共 12**）：`test_legacy_variations_json_without_inflated_count_defaults_to_zero`（R-7 核心，同時斷言價格仍讀得出來）+ `test_legacy_variations_json_is_upgraded_on_next_save`（證明重新儲存後自然補齊，無需 migration）
- `tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php`（**+5 method，共 17**）：變體層 override + inflated_count round-trip、變體 sale > regular → 400、variation 不屬於 product → 400
- `tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php`（**新檔，11 method**）：見上方「變體層覆寫」小節列舉的 11 個案例
- `tests/Unit/Domain/Service/PriceCalculatorTest.php`：**不改斷言**，`make_item()` helper 內部改用 `array_map` 包 `VariationOverride`（15 個呼叫點原封不動），全綠即為委派法零破壞既有定價邏輯的證明

**實測結果**（2026-07-30，baseline 以動工前實測為準，非文件既有數字——CLAUDE.md 記載的 Phase 8 數字 340/171 與本次動工前實測的 341/173 有落差，1 個 unit failure / 21 個 integration failures 完全吻合，判斷是先前記錄時機差異，不影響回歸判斷）：

| 層級 | Baseline（動工前） | 完成後 | 差異 |
|------|---------------------|--------|------|
| Unit | 341 / 670 assertions / 1 failure | 353 / 694 / 1 | +12 tests，failure 未增加（TD-2 pre-existing） |
| Integration | 173 / 754 / 21 failures | 191 / 850 / 21 | +18 tests，failure 未增加（TD-1 pre-existing，21 條失敗名稱與順序與 baseline 完全相同） |

`composer lint` / `vendor/bin/phpcs inc/`：136/136 → 138/138（+2 新檔）全綠。`@group` 已逐檔驗證（`--filter XxxTest --testdox` 筆數 == 檔內 `test_` 方法數），無靜默跳過。

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
| Phase 7（賣場編輯頁商品選擇器重構：`ProductTableBody` 抽取 + `ProductPickerModal` + items 巢狀契約修正 BUG-A + `ProfitShopHydratorTest` / `ProfitShopItemsRoundTripTest`）| commit `52d7aa8`…`6c96679` 一系列（規劃書 `specs/plan/2026-07-29-profit-shop-product-selector-plan.md`） |
| Phase 8（Partner 夥伴圖片：termmeta `_partner_image_id` + `image_url` 唯讀衍生 + 複用商品 `Gallery` 元件（單張圓形）+ Selector 頭像 + `#/profit-partner` 列表頁對齊標準版型）| commit `52d7aa8` |
| Phase 9（賣場商品變體層價格 / 灌水人數覆寫：`VariationOverride` VO + `OverrideItem` 委派法 + `SingleProductPriceHook`（WC 單品頁套價，解決價格跳動）+ 商品層 inflated_count 接上賣場前台 + `ItemsEditor` 展開列 UI）| commit `ab6d0e3` + `381d8b2`（規劃書 `specs/plan/2026-07-30-profit-shop-variation-override-plan.md`） |
| Phase 10（賣場前台 UI 照搬 legacy：混合式 PHP grid + `profit-shop-front` React bundle（第三個 Vite entry）+ `ProfitShopFrontData` payload + `FrontAjax` 購物車 admin-ajax + `settings` 通道啟用 + `ProfitShopFrontDataSettingsTest`）| commit `ff81dbb`（`issue/L3` 原本的 Phase 9） |
| Phase 11（賣場前台購物車改自訂 REST：`FrontCartApi`（6 endpoint）取代 `FrontAjax` + WC Store API 雙通道、`$_POST` 橋接維持 `AddToCartHook` 三閘門、REST context `wc_load_cart()` 就緒、nonce 死鎖解法）| commit `ff81dbb`（`issue/L3` 原本的 Phase 10） |
| Phase 12（賣場前台退回 WooCommerce 原生加購、移除自訂購物車：`FrontCartApi` 刪檔 + `AddToCartButton` 改動態建隱藏表單 + template 自印 `wc_print_notices()`）| commit `61dced7` |
| Phase 13（購物車顯示賣場覆寫後的原價 / 特價：`OverridePriceResolver` 新增 + `CartPriceSignatureService` v1/v2 簽章版本遷移 + `CartPriceOverrideHook` 套用 regular/sale）| commit `e4a45b8` |
| Phase 14（賣場前台改 PHP + daisyUI + vanilla JS：`js/src/profit-shop-front/` React bundle 整目錄刪除 + `ProfitShopFrontView` 新增 + 卡片 / Modal partials + CSS 統一交給 powerhouse 管理 + 獨立頁面與 shortcode 兩條路徑渲染統一 DOM）| `8cb0b05` |
| Phase 15（商品 Modal 改 AJAX 加購：WooCommerce 原生 `?wc-ajax=add_to_cart` + 按鈕 loading + daisyUI toast；數量 -/+ 按鈕補框線；移除 `CartItemMetaDisplay`，前台不再顯示「來自賣場 XXX」）| `8cb0b05`（與 Phase 14 同一個 commit——兩者在 4 個新檔案內逐行交錯，拆開會產生無法運作的中間 commit）|

設計與決策來源：
- `specs/2026-05-06-profit-shop-design.md`（含 Q1-Q6 plan 階段決策、§4 endpoint、§6.11 slug 衝突、§7 例外對映）
- 各 commit message 內附 reviewer / acceptance-evaluator 結論與順手清單

---

## 12. 前端 SPA 架構（Phase 4-A + 4-B + 6-A2 + 7）

兩個獨立的 React SPA bundle：商家後台 admin SPA（4-A，Phase 7 商品選擇器重構仍在此 bundle 內）+ Partner self-service portal（4-B / 6-A2）。前者掛在 `admin.php?page=power-shop`，後者掛在 `/profit-report/{slug}/`。詳細元件結構與目錄樹見 `react.rule.md` §6.4（`ProductTableBody` 共用元件）、§6.5（admin Profit Shop 系列，含 §12.9 商品選擇器）與 §6.6（Partner Portal）。

### 12.1 Resource 路由

| Resource | 路由 | Page Module |
|----------|------|-------------|
| `profit-shop` | `/profit-shop` / `/create` / `/edit/:id` | `pages/admin/ProfitShop/` |
| `profit-partner` | `/profit-partner` / `/create` / `/edit/:id` | `pages/admin/ProfitPartner/` |
| `profit-migration` | `/profit-migration` | `pages/admin/ProfitMigration/` |

（`profit-settings` 已整組移除，見 §7「全域設定頁已整組移除」。）

### 12.2 useCustom 取代 useTable / useForm

後端 V2Api 統一回 `{code, message, data}`，與 antd-toolkit 預設 dataProvider 預期的 WordPress REST API shape（`x-wp-total` header、payload 直接是 array / object）**不相容**。

**規範：**
- 3 個 profit-* resource **不**使用 `useTable` / `useForm`
- 改用 `useCustom` / `useCustomMutation`，手動讀 `result.data.data`
- 仍須明確指定 `dataProviderName: 'power-shop'`（含元件層 useCustom 如 `SlugInput`）
- Refine 仍提供 `<List>` / `<Edit>` / `<Create>` UI shell + `useInvalidate` 做快取失效

未來若改用 `useTable` / `useForm`，需先為 `'power-shop'` dataProvider 寫 thin wrapper 拆解 `{code, data}`。屬於 Phase 4-B 自選改造項。

### 12.3 dataProviderName: 'power-shop' 統一規範

所有打 profit-* endpoint 的 `useCustom` / `useCustomMutation`（包含元件層的 `SlugInput`、`PartnerSelector`、`ItemsEditor` 內部查詢）**必須**明示 `dataProviderName: 'power-shop'`。Phase 4-A3 的 LOW-1 已對 30+ 處統一補齊，並寫入 CLAUDE.md Common Pitfall #10。

### 12.4 不可逆操作的 4 道閘門 / 二次確認模式

ProfitMigration `ImportModal` 屬不可逆操作，採以下模式：

**ImportModal（IMPORT）4 道閘門**：
1. List 列每 row 的 `Import` 按鈕：`importable=false` 時 `disabled` + tooltip 顯示原因
2. Modal 開啟後 `canSubmit` 仍雙檢 `importable`（防 cache stale）
3. PartnerSelector 必選（強制 partner_term_id）
4. IMPORT 二次確認（typed confirmation 或 confirm dialog）

Modal 一律 `closable=false` / `maskClosable=false` / `keyboard=false` 強制使用者走確認按鈕，避免誤觸 ESC / mask click 被視為「同意」。

> 這裡原本還有一個 `ResetButton`（`#/profit-settings` 的「還原預設」，同樣的二次確認 + 自包
> mutation 紀律）。設定頁整組移除後它一併刪除——但上面這套模式仍是本專案處理不可逆操作的標準，
> 新增同類元件時照著做。

### 12.5 HIGH-RISK 元件的安全紀律

#### ~~RegeneratePasswordButton~~（admin SPA，**已於 2026-08 刪除**）

站長要求把夥伴密碼當一般欄位後，這個元件與 `PasswordSection` 一併刪除，
密碼改為 `PartnerForm` 內的一般欄位（預填明文、隨主表單送出、旁邊一顆純前端「產生」按鈕）。
現行 UI 紀律見 `react.rule.md` §6.5「夥伴密碼是一般欄位」；
後端明文並存的四條紀律見本檔 §6「明文密碼並存」。

⚠️ **後端 `regenerate-password` / `set-password` 兩個 endpoint 仍在**（前端不再呼叫），
是給日後「站長不該看到密碼」情境留的退路——它們各自帶有一般欄位路徑沒有的能力
（VO 強度驗證、系統產生 12 碼強密碼）。刪掉端點等於把退路砍斷。

它原本的 8 條紀律留檔於此，日後若要恢復一次性明文顯示的 UI，照這張表做：
明文僅存元件 `useState`（不進 cache / storage / log / Jotai / Context）/ 5 分鐘 auto-clear +
unmount cleanup（含 `setPwdVisible(false)`）/ Modal `closable=false` + `maskClosable=false` +
`keyboard=false` / `navigator.clipboard.writeText` 不 fallback `execCommand` /
response 型別守衛 / error case 不顯示舊密碼 / 主表單 payload 不含 password /
`Input.Password` 預設 mask。

#### ChangePassword.tsx（partner portal，6-A2 HIGH-RISK 強化版 8 條紀律）

**這頁不隨 admin 端降級**——夥伴自己改密碼是他的帳號安全操作，且 partner portal 是公開網址、
沒有 admin 後台那層登入保護。8 條紀律照舊。

與 admin 端的不同點：partner 自助改密本人輸入明文 current + new，端點
`POST /partner-auth/change-password`（partner_token），成功後本機**強制重新登入**
（後端 password_changed_at 同秒撤銷舊 token，6-A1 PartnerTokenStore::verify L138 `<=` 同秒契約）。

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

#### ImportModal

見 §12.4。（同章節的 `ResetButton` 已隨設定頁移除。）

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

> 曾經有個「ProfitSettings 加強版」變體（`lastFilledSettingsRef` content-diff，處理「reset 後
> server 內容變動但 page 不重新 mount」且該頁沒有 id 可比對的情況）。設定頁移除後一併刪除，
> 但若日後又出現「單例資源 + 可被外部改動」的頁面，content-diff 比對仍是正解——
> 光靠 `filledIdRef` 在沒有 id 的單例頁上不成立。

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
   └─（Phase 15 已移除 CartItemMetaDisplay，前台不再顯示分潤標記）

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

#### Cart UI 分潤標記已於 Phase 15 移除

`CartItemMetaDisplay`（Phase 4-C2）曾用一個 `woocommerce_get_item_data` filter
同時在 mini-cart / cart page / checkout 顯示「來自賣場 XXX」連結。**站長要求前台不顯示這項資訊，
該 class 已於 Phase 15 整檔刪除**（`Loader` 註冊一併移除）。

移除的安全性依據：它是**純顯示**——唯一作用就是往 `item_data` 陣列 append 一筆，
不碰價格、不寫 meta、不參與簽章。`AddToCartHook`(5) 三閘門、`CartPriceOverrideHook`(10/999)
的 `_profit_*` meta 與 HMAC 簽章、`OrderItemSettlementRepository` 的結算紀錄全部不受影響
（實測：移除後購物車仍是覆寫價 240 / 劃線原價 300，Store API 的 `item_data` 為 `[]`）。

訂單頁與訂單 email 不需要另外處理：line item 的 `_profit_*` meta 以底線開頭，
WooCommerce 的 `get_formatted_meta_data()` 預設就會濾掉底線前綴的 meta。

若日後要恢復顯示，見 git commit `f813e07`。**不要**改成在 template 內自行讀
`$cart_item['profit_shop_id']` 印出來——那會繞過原本 `try_find_shop()` 的
「shop 已刪 / 非 publish 就靜默不顯示」保護。

#### Phase 10：前台 UI 照搬 legacy（混合式 PHP + React）

> ⚠️ **本節是歷史紀錄**。這裡描述的 React bundle、`data-ps-product-id` / `.ps-btn` /
> `.ps-not-ready` / `#profit_shop_front_app` 這組 DOM 契約、`ProfitShopFrontData::build()`
> / `build_env()`，都已在 **Phase 14** 被移除或取代（見下方「Phase 14」小節）。現行的
> DOM 契約、資料流與檔案清單以 Phase 14 小節為準；本節保留是因為 Phase 11 / Phase 12 的
> 小節緊接在後、彼此有演進關係，拆掉會讓閱讀脈絡斷裂。

Phase 4-C 的「純 PHP、無 React」在 Phase 10 演進為**混合式**：PHP 仍負責印出商品 grid
（SEO 內容留在 HTML），React/antd 只掛「商品 Modal」與「購物車表格」互動層（後者已於 Phase 12
移除，見下方 Phase 12 小節）。這**不是推翻**
Pitfall #12——legacy 一頁商店本身就是這個架構，照搬它同時滿足「SEO / 不破壞 WC cart /
customer-facing」三個理由與「UI 與舊版完全一致」的要求。前端細節見 `react.rule.md` §6.7。

PHP 端新增三個檔案：

| 檔案 | 職責 |
|------|------|
| `Infrastructure/WordPress/ProfitShopFrontData.php` | 把 ProfitShop + WC 商品組成前端 payload。**欄位名刻意用 legacy 的 camelCase**（`regularPrice` / `salesPrice` / `shortDescription` / `extraBuyerCount`），React 元件才能 1:1 照搬 |
| ~~`Infrastructure/WooCommerce/FrontAjax.php`~~ | 前台購物車 admin-ajax（`nonce` / `add_cart` / `remove_cart` / `update_cart`）。**Phase 11 已刪除**，改為 `Infrastructure/Rest/FrontCartApi.php` 自訂 REST，見下方 Phase 11 小節 |
| `Infrastructure/WordPress/templates/profit-shop-front.php` | 改寫為 legacy 卡片版型（既有檔案） |

**四條紀律（違反會靜默壞掉，且沒有錯誤訊息；第 1、2 條已隨 Phase 11 改寫，見下）：**

1. **購物車 API 層絕不自行寫入價格或分潤 meta**。無論是 Phase 10 的 `FrontAjax` 還是 Phase 11 的
   `FrontCartApi`，加購都只呼叫 `WC()->cart->add_to_cart()` 並確保 `$_POST['profit_shop_id']`
   存在，讓既有 hook 鏈自己做：`AddToCartHook`(5) 三閘門 → `CartPriceOverrideHook`(10) 寫 meta +
   HMAC 簽章 → (999) 驗章 + `set_price`。自建 handler 直接塞
   `cart_item_data['profit_shop_id']` 會**跳過全部三道閘門**（`AddToCartHook.php` 開頭的
   short-circuit）——這是兩個 Phase 共同的鐵律，Phase 11 的 `FrontCartApi::add_to_cart_with_shop_context()`
   同樣遵守，只是改用「呼叫前橋接 `$_POST`、`finally` 還原」的手法（見下方 Phase 11）。

2. **走 REST 一樣可行，只是需要多做兩件事**（Phase 10 曾判斷「走 admin-ajax 而非 REST」，Phase 11 已
   反轉為走 REST；以下是為何兩者都行得通的推理）：
   - WooCommerce 只在 frontend 與 `DOING_AJAX` context 呼叫 `load_cart()`，admin-ajax 因此
     `WC()->cart` 一定就緒；REST route 沒有這個天然優勢，必須自己補 `wc_load_cart()`，且要再主動
     呼叫 `WC_Cart::get_cart()`（`WC_Cart_Session::init()` 把 session 還原掛在 `wp_loaded`，REST
     callback 執行時該 hook 早已跑完，不補這一步購物車會是空的）。
   - 三個 cart hook 的 admin guard 都是 `is_admin() && ! wp_doing_ajax()`。REST request 走
     `/wp-json/`，`is_admin()` 一樣回 `false`，guard 不觸發，hook 照常掛載——這與 admin-ajax
     context 的結論相同，代表**改走 REST 不需要動任何 hook 檔案**。
   - REST 送的是 JSON body，PHP 不會自動填 `$_POST`（`AddToCartHook` 只讀 `$_GET` / `$_POST`），
     這是 REST 相對 admin-ajax 唯一多出來的坑，Phase 11 用「呼叫前橋接 `$_POST`、`finally` 還原」
     解決，見下方 Phase 11 小節。

3. **template 與 payload 的商品過濾條件必須同步**。兩邊都跳過 `'publish' !== $product->get_status()`
   的商品。不同步會產生「有卡片但 payload 找不到商品」→ 點了開不出 Modal，或反之。

4. **DOM 契約不可改**：`data-ps-product-id` / `.ps-btn` / `.ps-not-ready` /
   `#profit_shop_front_app` 是跨 PHP↔React 的介面。改 class 或 attribute 會讓 Modal 開不起來、
   卡片永久灰階，且**不會有任何錯誤訊息**。

**`ProfitShop::$settings` 是前台顯示設定的來源**（端到端已通 Repository `_profit_shop_settings`
post meta / Hydrator / DTO / V2Api）。`ProfitShopFrontData::settings()` 讀 legacy 相同的 key
（`btnColor` / `showConfetti` / `showStock` / `showBuyerCount` / `startTime` / `endTime`），
缺值 fallback 到 legacy 預設，並在 PHP 端算好 `shopStatus`（`published` / `coming` / `closed`）
避免瀏覽器時鐘偏移。三個型別陷阱（有 Unit test 鎖住，見下）：

- **bool 經 REST 往返會變 `'1'` / `''`** → 一律走 `to_bool()`，不可直接 `if ($settings['showStock'])`
- **毫秒時間戳會變字串** → 一律走 `to_timestamp_ms()`；壞資料正規化為 `0`（未設定），
  **不可**當成「已過期的結束時間」，否則整個賣場會誤判為「商店已關閉」
- **`btnColor` 會直接進 inline `<style>`** → 必須走 `to_color()` 的 hex 白名單
  （legacy 是 `echo $btn_color` 毫無驗證，是 CSS injection 洞）

測試：`tests/Unit/Infrastructure/WordPress/ProfitShopFrontDataSettingsTest.php`（12 method /
47 assertion，純 PHP 不啟 WP）。`build_products()` 與（Phase 11 起）`FrontCartApi` 仍待補 IT，見
`specs/tech-debt.md` TD-29。

#### Phase 11：前台購物車改自訂 REST（**已於 Phase 12 整條移除**，保留供日後參考）

Phase 10 的前台購物車是「寫入走 admin-ajax（`FrontAjax`）、讀取與選運費走 WC Store API」的雙通道，
本 Phase 收斂為單一自訂 REST：`Infrastructure/Rest/FrontCartApi.php`（`namespace = 'power-shop'`，
6 個 endpoint：`GET nonce` / `GET`（讀取）/ `POST add-item` / `update-item` / `remove-item` /
`select-shipping-rate`，`permission_callback` 全部 `__return_true`）。回應統一 `{ code, message, data }`，
`data` 一律是完整購物車物件（沿用 WC Store API 的欄位命名慣例：金額為「最小單位整數字串」+
`currency_minor_unit`）。

⚠ **`FrontCartApi` 已於 Phase 12 刪檔**（見下一節）。以下三條知識**不因刪檔而失效**——任何人
日後要再為前台補上非同步購物車 API，都會重新踩到同一批坑，所以整節保留而非刪除。

**最關鍵的實作細節：`$_POST` 橋接**。`AddToCartHook::extract_shop_id_from_request()` 只讀
`$_GET` / `$_POST`，而 REST 的 JSON body 不會填 `$_POST`。`FrontCartApi::add_to_cart_with_shop_context()`
因此在呼叫 `$cart->add_to_cart()` **之前**把 `profit_shop_id` 寫入 `$_POST`，呼叫完（含例外路徑）
以 `finally` 還原：

```php
$_POST['profit_shop_id'] = (string) $shop_id;
try {
    return $cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
} finally {
    // 還原成呼叫前的原值（有原值就還原，沒有就 unset）
}
```

**兩條禁止的替代做法**（與 Phase 10 的紀律 1 一致，重申於此）：改用 `add_to_cart()` 第 5 個參數
`$cart_item_data` 傳 `profit_shop_id`——`AddToCartHook` 開頭的 short-circuit
（`isset($cart_item_data['profit_shop_id'])` 就 return）會讓三道閘門全部被跳過；或在
`FrontCartApi` 內複製一份三閘門驗證邏輯——違反 DRY，兩份規則遲早走鐘。

**REST context 下 `WC()->cart` 不會自動就緒**：每個 callback 開頭都呼叫 `ensure_cart_ready()`：
`wc_load_cart()` 之後再主動呼叫 `WC_Cart::get_cart()`（內部補跑 `get_cart_from_session()`），
與 WooCommerce 自己的 `StoreApi\Utilities\CartController::load_cart()` 同法。

**nonce 死鎖與解法**：賣場頁 `status=publish` 刻意不送 nocache（Phase 5-B CDN 優化），HTML 內
nonce 可能已過期。`rest_cookie_check_errors()` 對「沒送 nonce」的請求會 `wp_set_current_user(0)`
當 guest 放行，對「送了無效 nonce 且有登入 cookie」的請求回 403 `rest_cookie_invalid_nonce`——
若換 nonce 的 endpoint 本身也要求帶有效 nonce，會構成死結。`get_profit_shop_cart_nonce_callback()`
因此先從 `logged_in` cookie 還原身分（`wp_validate_auth_cookie` + `wp_set_current_user`）再產生
nonce，與 WordPress 自己的 `wp_refresh_nonces()` 同一手法。前端判斷「該不該重試」的條件是
**403 且 body `code === 'rest_cookie_invalid_nonce'`**（不是舊 admin-ajax 時代自訂的 `invalid_nonce`）。

測試：**端到端執行期驗證 32 項全通過**（用 `rest_do_request()` 在真實 WP + WC 環境內部呼叫，
涵蓋 6 個 route 註冊、`wc_load_cart()` 在 REST context 生效、三閘門確實跑到、4 筆 `_profit_*`
meta 全部寫入、結帳前套用價格正確、3 則攻擊情境全擋、nonce 可通過 `wp_verify_nonce`）。單元 /
整合測試未補就已隨 Phase 12 一併移除。

#### Phase 12：前台退回 WooCommerce 原生加購，移除自訂購物車

站長要求：賣場前台「移除底下的購物車等資訊」，商品 Modal 的「加入購物車」改用**原本 WordPress
的方式**加入購物車。Phase 10 / 11 為了「不離開頁面完成購物」而建的整條自訂購物車通道（前台購物車
表格 + 樂觀更新 + 自訂 REST）因此失去存在理由，整條移除。

| 動作 | 內容 |
|------|------|
| 刪除（PHP） | `Infrastructure/Rest/FrontCartApi.php`（6 endpoint）+ `Loader` 註冊 |
| 刪除（前端） | `Cart/`（3 檔）、`hooks/useCart.ts`、`api.ts`、`components/LoadingText.tsx`、`cartDataAtom`、全部購物車型別、`.ps-cart-table` CSS、`QueryClientProvider` |
| 改寫 | `AddToCartButton` 改為動態建隱藏 `<form method="post">` POST 回本頁，交給 `WC_Form_Handler::add_to_cart_action()` |
| 新增 | template 自印 `wc_print_notices()` |
| 收窄 | `ProfitShopFrontData::build_env()` 只剩 `siteUrl` / `shopId` / `shopSlug`（移除 `cartApiUrl` / `nonce` / `checkoutUrl`） |

**分潤鏈路一行未改**：`AddToCartHook`(5) / `CartPriceOverrideHook`(10/999) / `CartItemMetaDisplay` /
`SingleProductPriceHook` 四個 hook 檔案完全沒動。原生表單是 `method="post"`，`profit_shop_id`
正常進 `$_POST`，三道閘門照常驗證——這正是 Phase 11 得靠 `$_POST` 橋接才能達成的事，退回原生
表單後不再需要任何補償手段。

**為何 template 必須自印 `wc_print_notices()`**：賣場頁不是 WooCommerce 的 template
（`is_woocommerce()` 為 false），佈景主題掛在 `woocommerce_before_main_content` 上的
`woocommerce_output_all_notices` 不會被觸發。少了它，使用者按下「加入購物車」之後畫面**除了
重新載入之外沒有任何變化**，功能看起來像是壞掉的。`wc_print_notices()` 印完會清空 notices，
所以就算佈景主題在別處也印了一份也不會重複，只是先後順序不同。

**`attribute_*` 的鍵必須取自選中變體的 `attributes` 物件**（WC `get_available_variations()` 的原始
輸出，形如 `attribute_pa_color`），**不可**從前端 `selectedAttributesAtom` 的 `name` 自行加前綴
組出來：那個 `name` 沒有前綴（例：`pa_color`），而自訂（非 taxonomy）屬性的名稱經 WC
`sanitize_title()` 後可能與原始名稱不同（中文屬性會變成 percent-encoded），自行組鍵會在那些商品
上安靜地對不上、被 WC 判為「缺少屬性」。這順帶讓 TD-30 在這條新路徑上不再成立。

**連帶移除的 UX（已知且刻意）**：原生加購會重新載入整頁，樂觀更新、彩帶（`showConfetti`）、
「加入購物車成功」通知在 reload 前都來不及被看到，一併移除；`StockInfo` 的「，已加入購物車 N 件」
與「數量上限扣掉購物車既有數量」同樣失去資料來源，超買改由 WooCommerce 自己在 `add_to_cart()`
時擋下並顯示 notice——那本來就是庫存判斷的唯一真相。**不要**為了補回這些細節就把購物車讀取通道
重新接回來。

前端細節（表單欄位表、三條紀律、bundle 隔離驗收期望值的三次變動）見 `react.rule.md` §6.7。

#### Phase 14：賣場前台改 PHP + daisyUI + vanilla JS，CSS 統一交給 powerhouse 管理

站長提出三件互相牽動的事：(1) power-shop 不再自己管 CSS，全部交給 powerhouse 統一提供；
(2) 賣場前台「獨立頁面」與「shortcode」兩條渲染路徑統一 DOM；(3) 賣場前台移除 React / antd，
改回 PHP + daisyUI + 手寫 vanilla JS。CSS 收斂到 powerhouse 之後，若賣場前台仍是 antd 元件，
會變成「antd 元件外觀 + daisyUI 語意色」的四不像，三件事因此一次做完。

**1. 移除自有 CSS**：`js/src/assets/styles/tailwind.css`（admin + partner portal 原共用
entry）/ `js/src/profit-shop-front/styles.css` / `tailwind.config.cjs` / `postcss.config.cjs`
全數刪除；`package.json` 移除 `tailwindcss` / `daisyui` / `autoprefixer` / `postcss` /
`postcss-import` / `postcss-nesting` 六個 devDependencies。`pnpm build` 後 `js/dist/`
**零 CSS 檔案**。樣式全部改由 powerhouse 的 `admin.min.css`（admin SPA）/ `front.min.css`
（partner portal / 賣場前台）提供。跨 plugin 配套（powerhouse `admin-layout` 補
`id="tw"`、`PartnerPortalRenderer` 顯式呼叫 `enqueue_frontend_assets()`）與三個新踩到的陷阱
（主題蓋掉 daisyUI 按鈕尺寸／`inline` 與 `tw-inline` 的隱藏失效／powerhouse CSS 快取版本號）
完整說明見 CLAUDE.md Common Pitfall #19 / #26 / #27 / #28 與 `react.rule.md` §10。**⚠️ 第一項
（主題蓋掉 daisyUI 按鈕尺寸）動工當下靠 power-shop 逐檔補 Tailwind utility 繞過，已於
2026-08-04 由 powerhouse CSS 建置層系統性修正取代**，見下方「Phase 14 補充修正」。

**2. 統一渲染 DOM**：`templates/profit-shop-front.php` 移除 `$is_shortcode_context` 旗標，
外層**一律 `<div>`**（獨立頁面走 theme 的 `header.php` 時同樣可能已有 `<main>`，統一 `<div>`
才是唯一安全解）。同時修正一個實質 bug：早期只有 `ProfitShopRenderer` 會傳
`$front_settings`，shortcode 路徑拿到空陣列 → `shopStatus` fallback 成 `'published'` →
**已結束販售的賣場用 shortcode 嵌入時照樣能加購**——這不是外觀不一致，是商業邏輯漏洞。
現在 `ProfitShopRenderer::render_shop()` 與 `ProfitShopShortcode::render_template()` 都會傳
`$front_settings` 並都呼叫 `ProfitShopFrontView::enqueue()`（早期 shortcode 完全沒
enqueue，商品 Modal 開不起來）。`ProfitShopShortcodeTest` 新增
`test_renderer_and_shortcode_paths_render_identical_dom`（逐字比對兩條路徑完整輸出，不只比
外層標籤）鎖死這個回歸；已失去前提的 `test_template_defaults_to_main_wrapper_without_flag`
移除。

**3. 賣場前台改 PHP + daisyUI + vanilla JS**：刪除 `js/src/profit-shop-front/` 整目錄
（19 檔）+ `vite.config.ts` 第三個 entry；新增 `inc/assets/js/profit-shop-front.js`
（手寫 vanilla JS，**不進 Vite build pipeline**，只負責五件互動：開 Modal、Gallery 縮圖、
描述展開收合、數量加減、變體選擇）+ `Infrastructure/WordPress/ProfitShopFrontView.php`
（呈現層工具：`currency()` / `number()` / `price_range()` / `embed_youtube()` /
`stock_presentation()` / `max_qty()` / `icon()`（內嵌 SVG，取代 `@ant-design/icons`）/
`js_i18n()` / `enqueue()`；當時為繞過主題蓋掉 daisyUI 尺寸的問題加上 `BTN_SIZE_SM` /
`BTN_SIZE_XS` / `INPUT_SIZE` 三個 Tailwind utility 尺寸常數，**已於 2026-08-04 隨 powerhouse
CSS 建置層系統性修正而移除**，見下方「Phase 14 補充修正」）
+ `templates/partials/profit-shop-card.php`（商品卡片）+
`templates/partials/profit-shop-modal.php`（商品 Modal，`<dialog class="pc-modal">`）。
`ProfitShopFrontData::build_products()` 改為 **public**（卡片與 Modal 共用同一份 payload，
根除「有卡片但 Modal 找不到商品」的同步風險）；`build()` / `build_env()` 隨 React bundle
一併刪除（前台不再有 `wp_localize_script` 的全域 payload，改用**每個 Modal 各自的**
`<script type="application/json" class="ps-variations-data">`，天然支援一頁多個 shortcode
賣場，JS 查找一律限定在最近的 `.ps-profit-shop-front` 祖先內）。

**漸進增強，不再需要任何 `<noscript>` CSS**：每張卡片只印**一個**動作元素（simple →
`<form method="get">`；其他 → `<a href="{permalink}?profit_shop_id=">`），本身無 JS 即可用；
JS 在卡片層攔 click 並 `preventDefault()` 改開 Modal，同時把按鈕文字換成
`data-ps-js-label`。因此不再需要舊版那組 `<noscript>` 樣式、`.ps-not-ready` 灰階、或任何
inline `<style>`。

**⚠ 非販售期間 Modal 整段不印**：`templates/profit-shop-front.php` 只在 `$is_shop_open`
時才 `require partials/profit-shop-modal.php`。Modal 內含一張真正的
`<form method="post">`，即使按鈕是 `disabled`，表單本身仍然存在於 DOM，改一下屬性就能送
出——而 `AddToCartHook` 只驗「賣場存在 / `status=publish` / 商品屬於該賣場」三道閘門，
**不檢查販售起訖時間**，那道防線接不住。不印出來才是唯一可靠的做法（舊版在閉店時也只印
停用按鈕、不印任何表單）。`test_shortcode_respects_closed_shop_status` 鎖死「閉店賣場不得
印出任何可送出的加購表單欄位」。

**顏色全走 daisyUI 語意色**（`pc-btn-primary` / `text-error` / `text-success` /
`text-warning` / `text-base-content/60` / `bg-base-200` / `border-primary`），跟隨站台
daisyUI theme；賣場設定的 `btnColor` 與 `--ps-primary` CSS 變數**不再驅動前台顏色**——
`ProfitShopFrontData::settings()` 的 `colorPrimary` 欄位保留但**已無消費者**（理由見
`ProfitShopFrontData::DEFAULT_COLOR_PRIMARY` 的 docblock：wire 契約穩定 + hex 白名單仍是
有效輸入驗證 + 日後接 daisyUI `data-theme` 巢狀覆蓋的天然載體），登記
`specs/tech-debt.md` TD-38。

**新增技術債**：TD-37（powerhouse CSS 快取版本號）、TD-38（`colorPrimary` 無消費者）、
TD-39（power-shop 樣式跨 repo 建置相依 powerhouse）、TD-40（2026-08-04 補充修正新增，
daisyUI 沒出過規則的元素仍需使用端自行壓過主題）。**TD-26**（商品 Modal 992–1079px
版型破碎，antd `Col lg` 992px 斷點 vs Tailwind `lg` 1080px 斷點不一致）因 antd 整個移除而
**結構性消失**，移至已解決。

不引入新 npm 套件（移除 6 個）；不引入新 composer 套件；不動 Domain / Application 層與
`AddToCartHook` / `CartPriceOverrideHook` / `CartItemMetaDisplay` / `SingleProductPriceHook`
四個 hook 檔案——原生表單 `profit_shop_id` 正常進 `$_POST`，分潤鏈路一行未改。

前端細節見 `react.rule.md` §6.7（歷史紀錄，說明 React bundle 移除脈絡與延續下來的知識）
與 §10（CSS 慣例全改寫）。

**驗證數據**（實測，2026-08-04）：`vendor/bin/phpcs inc/` 137/137 全綠；`php -l` × 10 檔全
過；`pnpm build` 成功，manifest 兩個 entry 的 `css` 皆為 `[]`；`npx tsc --noEmit` 127（=
baseline）；Unit 402 tests / 824 assertions / 1 failure（TD-2 pre-existing）；Integration
236 tests / 1001 assertions / 15 failures（全部 pre-existing，沒有一條在本次改動的檔案
內）；`ProfitShopShortcodeTest` 單獨跑 OK（18 tests / 66 assertions）全綠；真實站台端到端
驗證（`agent-browser`）確認 HTML 內 `ps-btn` / `ps-not-ready` / `profit_shop_front_app` /
`--ps-primary` 全部 0 命中，`<dialog>` 4 個，按鈕計算後樣式 32px（與原版一致），加購表單
`profit_shop_id` 正確送出、賣場覆寫價正確顯示。完整表格見 CLAUDE.md「Phase 14」章節。

#### Phase 14 補充修正：daisyUI 樣式系統性修正（powerhouse CSS 建置層，2026-08-04）

Phase 14 剛完工時，賣場前台的 daisyUI class（`pc-btn-sm` 等）會被佈景主題蓋掉，當時的因應
是在 power-shop 這一側逐顆按鈕補 Tailwind utility——`ProfitShopFrontView::BTN_SIZE_SM` /
`BTN_SIZE_XS` / `INPUT_SIZE` 三個常數。**這批常數已全部移除**，改為在 powerhouse 的 CSS 建置
階段做系統性修正，讓原生 daisyUI class 本身就能穩定勝過主題。

**根因**：daisyUI v4 的元件 class 以 specificity **(0,1,0)** 輸出（例如 `.pc-btn{}`），且
daisyUI v4 **沒有** `important` 可提高權重（config 只有 `styled` / `themes` / `base` /
`utils` / `logs` / `darkTheme` / `prefix` / `themeRoot`）。Tailwind 的 `important: '#tw'`
**只作用在 utilities**，不會套到外掛用 `addComponents` 加進來的規則——修正前 `front.min.css`
內有 438 條未加 scope 的 `.pc-*`，只有 23 條帶 `#tw`。WordPress 佈景主題（實測 Blocksy
`main.min.css`）有一條同樣 specificity (0,1,0) 的 `.button, .ct-button, ...` 規則，**CSS
同分時由載入順序決勝**，主題排在外掛 CSS 之後 → 永遠贏。Tailwind utility 之所以免疫，正是
因為 `#tw .h-8` 是 (1,1,0)，嚴格大於主題那條，與載入順序無關。

**修正**：powerhouse 新增 `scripts/postcss-scope-daisyui.cjs`（PostCSS plugin，從
`tailwind.config.cjs` 的 `important` 讀 scope）+ `postcss.tailwind.cjs`（Tailwind CLI
`--postcss` 旗標用的設定），`build-css:admin` / `build-css:front` 都掛上這道步驟，把所有
`pc-` 開頭的 daisyUI 選擇器整批收進 `#tw`，轉換後 daisyUI 規則變成 `#tw .pc-btn{}`，
specificity 拉高到 (1,1,0)，與 Tailwind utility 同量級，穩定勝過主題。⚠️ **Tailwind CLI
不會自動讀取 `postcss.config.cjs`**（實測 probe plugin 完全沒輸出），CSS 建置必須用
`--postcss` 旗標明確指定才會套用這道轉換。

**根部錨定選擇器不可加 scope**：`#tw` 掛在 `<html>` / `<body>`，若把 `:root .pc-countdown`
轉成 `#tw :root .pc-countdown`，會變成「找 `#tw` 底下的 `:root` 後代」——`:root` 永遠是
`<html>`、不可能是自己的後代，這條規則從此永遠匹配不到、無任何錯誤訊息。已踩到
`:root .pc-countdown`（倒數計時器樣式失效）與 `:root:has(:is(.pc-modal-open, ...))`
（Modal 開啟時鎖住背景捲動的規則失效）。修正 plugin 因此有 `ROOT_ANCHORED` 守門，selector
第一個 compound 是 `:root` / `html` / `body` / `*` / `[data-theme` 一律跳過。

**涵蓋不到的情況**：daisyUI 完全沒出過規則的元素（例如 `.pc-modal-backdrop` 內的
`<button>`），沒有規則可以 scope，主題仍會無對手地套用，**仍要**使用端自行加 Tailwind
utility 壓過去——`templates/partials/profit-shop-modal.php` 的 backdrop button 因此保留
`bg-transparent text-transparent border-0 cursor-default`，這是本次修正後**唯一僅存**的手動
utility 用法。

**power-shop 端變更**：`ProfitShopFrontView::BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE` 三個
常數移除，卡片按鈕 / Modal 加購鈕 / 關閉鈕 / 變體屬性選項 / 數量 −/+ / 數量輸入框全部改回原生
daisyUI class（`pc-btn pc-btn-primary pc-btn-sm`、`pc-btn pc-btn-ghost pc-btn-circle
pc-btn-sm`、`pc-btn pc-btn-xs`、`pc-btn pc-btn-sm pc-join-item`、`pc-input
pc-input-bordered pc-input-sm pc-join-item`）。`ProfitShopFrontView` 內留了一段註解說明常數
被移除的原委。

已知涵蓋不到的情況記為技術債 `specs/tech-debt.md` TD-40。CLAUDE.md Common Pitfall #26
已整條改寫反映本次修正；完整根因推導、build log 數字與真實站台計算後樣式對照表見 CLAUDE.md
「Phase 14 補充修正」章節。

#### Phase 15：商品 Modal 改 AJAX 加購（`?wc-ajax=add_to_cart`）

站長要求 Modal 的「加入購物車」不換頁 + loading + 成功通知。這個需求同時撞上 Phase 12
（整條前台購物車 REST 已刪檔）與 Pitfall #22（禁止 `fetch` 送 JSON body 加購），
因此**通道選擇本身就是這個 Phase 的主要工作**。

**選定 WooCommerce 原生的 `?wc-ajax=add_to_cart`**，power-shop 端零新增 endpoint。
決策依據與五條判準對照、五條「分錯類會讓顧客被重複收費」的紀律、四道降級關卡、
兩個 CSS 陷阱，完整記在 `.claude/CLAUDE.md`「Phase 15」章節，此處只記與本領域最相關的三點：

1. **`$_POST` 必須被填充，這是分潤三閘門的生命線**。這條通道吃
   `application/x-www-form-urlencoded`，PHP 自動填 `$_POST`，
   `AddToCartHook::extract_shop_id_from_request()` 讀得到 `profit_shop_id`，
   三道閘門完整生效——**不需要 Phase 11 那種 `$_POST` 橋接**（那是走 REST JSON body 才需要的補償）。
   實測 A/B/C 對照：帶合法 shop_id → 覆寫價 240/300 + 分潤標記；不帶 → 原價 18；
   偽造 `999999` → 原價 18。

2. **variable 商品送的 `product_id` 是「選中變體自己的 ID」**。`WC_AJAX::add_to_cart()`
   偵測到傳入的是 variation 時會 `$variation_id = $product_id; $product_id = 父商品ID;
   $variation = $product->get_variation_attributes();`，**換算之後**才呼叫
   `WC()->cart->add_to_cart()`。因此到達 `woocommerce_add_cart_item_data` 的 `$product_id`
   是**父商品 ID**，第三閘門 `$shop->has_item( $product_id )` 仍然對得上；
   而且這條通道**不送也不需要** `attribute_*`（WC 自己從變體物件反查），
   順帶讓 Pitfall #22 記載的「自行組 `attribute_` 鍵會對不上」風險在這條路徑上結構性消失。

3. **`WC_AJAX` 只傳 4 個參數給 `add_to_cart()`**（第 5 個 `$cart_item_data` 是預設空陣列），
   所以 `AddToCartHook` 開頭的 short-circuit（`isset($cart_item_data['profit_shop_id'])` 就 return）
   不會誤觸發。**任何情況下都不得改用第 5 個參數傳 `profit_shop_id`**——那會跳過全部三道閘門。

**業務失敗為何不能就地顯示原因**：`WC_AJAX::add_to_cart()` 的失敗分支只回
`{error:true, product_url}`，真正的原因寫在 **session notice**，而 `wp_send_json()` 之前
只有 `woocommerce_cart_redirect_after_error` 一個 filter、那只能改 URL、無法夾帶訊息。
要「不換頁還能顯示原因」就必須自建一條讀 `wc_get_notices()` 的通道——那等於復活
`FrontCartApi` 的一小塊，本次刻意不做，改為依 WooCommerce 官方前端契約導向 `product_url`
（登記 `specs/tech-debt.md` TD-41）。**絕不可改成「再送一次原生表單」**：`wc_add_notice()`
沒有去重，同一則錯誤會被寫兩次；邊界情況（第一次其實成功了）更會變成重複加購。

**Domain / Application 層與四個 cart hook 完全未動**（`git diff` 對它們空白）；
不引入新 npm / composer 套件。回歸鎖：`ProfitShopShortcodeTest::test_open_shop_renders_ajax_dom_contract`
（8 個 `data-ps-*` 屬性 + `name="profit_shop_id"`）。

#### Phase 16：賣場開啟 / 關閉時間 + 期間外呈現方式

賣場編輯頁新增「開啟 / 關閉時間」Tab，可設定販售期間，並針對「尚未開賣」與「已結束販售」
各自選擇前台呈現方式。**後端 Domain / Application 層零修改**——`settings` 是自由 array，
`startTime` / `endTime` 端到端本來就通（TD-22 記載的「存得進去、讀得出來、沒人設定」），
本 Phase 只補了呈現層正規化、template 分支、vanilla JS 與後台 Tab。

**呈現方式是三個互相獨立的區塊（多選），不是三選一：**

| 區塊常數 | 值 | 前台效果 |
|---|---|---|
| `PART_COUNTDOWN` | `countdown` | daisyUI 倒數盤 |
| `PART_TEXT` | `text` | 站長自訂文字（空字串時不印） |
| `PART_PRODUCTS` | `products` | 商品卡片（按鈕停用、不可購買） |

任意組合皆可；**全部不勾（`[]`）＝什麼都不顯示**，只剩賣場標題與站長自訂內容。

**`settings()` 的「意圖」與「結論」分兩組輸出，template 只讀結論：**

| 欄位 | 語意 |
|---|---|
| `beforeDisplay` / `beforeText` | 站長設定的**原始意圖**：尚未開賣時顯示哪些區塊（`string[]`） |
| `afterDisplay` / `afterText` | 同上，已結束販售時 |
| `showCountdown` / `showProducts` | 與當下 `shopStatus` 合併後的**結論**（`published` 等同只有 `products`） |
| `showDisplayText` / `displayText` | 同上；勾了 `text` 但文字為空時 `showDisplayText` 為 `false`，`displayText` 為 `''` |
| `countdownTarget` | `coming` → `startTime`；其餘 → `0`（全零盤面） |

分工是刻意的：`shopStatus` 有三態、呈現設定有兩組，若讓 template 自己配對，
獨立頁面與 shortcode 兩條路徑就各有一份配對邏輯要維持一致——這個 codebase 已經因為
「靠人維持兩份邏輯逐字一致」吃過虧（見上方 `OverridePriceResolver` 一節）。

**七條紀律：**

1. **預設值必須是 `[products]`**（`ProfitShopFrontData::DEFAULT_DISPLAY_PARTS`）。既有賣場的
   `settings` 裡沒有 `beforeDisplay` / `afterDisplay`，預設若不是「只顯示商品」（＝本功能
   上線前的既有行為），全站已排程的賣場會在時間外突然變成空白頁。
2. **🔴「沒設定」與「設定為空」必須用 `is_array()` 區分，不可用 `empty()`。**
   空陣列是**合法值**（站長刻意全部取消勾選），而 `empty([])` 為 true——用它判斷會讓
   站長的取消勾選被靜默還原成「顯示商品」，改了設定卻沒有反應、也沒有任何錯誤訊息。
   前端 `toDisplayParts()` 同理：用 `Array.isArray()`，不可用 `value?.length`。
   `test_empty_display_parts_means_show_nothing` 鎖住這條。
3. **陣列內的未知值由白名單過濾掉**（`to_display_parts()` 逐一比對 `DISPLAY_PARTS` 常數，
   順序也取自常數，讓序列化結果穩定、不受前端送來的順序影響）。這個值決定「商品要不要
   印出來」，是實質的可見性開關，不可原樣帶到 template 再用字串比對。
4. **任何組合都不印商品 Modal**，`$is_shop_open` 那道 gate 一行未改。Modal 內含一張真正的
   `<form method="post">`，而 `AddToCartHook` 的三道閘門**不檢查販售起訖時間**，接不住
   「把 `disabled` 拔掉再送出」。`test_no_display_combination_ever_renders_modal_when_closed`
   對 5 種組合逐一鎖死。
5. **沒勾倒數也沒有可顯示的文字時，輸出必須與功能上線前逐字相同**——排程說明區塊整段不印。
   多印一段文字（或一個空的 `<section>`）會讓所有既有賣場的前台 DOM 產生非預期變動
   （`test_closed_shop_without_display_setting_keeps_browse_behaviour` 鎖住）。
6. **自訂文字經 `WP::sanitize_text_field_deep()`**（`read_json_body()`），換行會被吃掉，
   所以後台用單行 `Input` 而非 `TextArea`——用 `TextArea` 會讓站長打的換行**靜默消失**。
   長度上限 200（`TEXT_MAX_LENGTH`）以 codepoint 計，避免中文被切成半個字。
7. **「全部不勾」只藏內容，不等於關閉存取**。`ProfitShopRenderer` 只檢查 slug 與 `status`，
   公開網址仍回 200（賣場標題 + 站長自訂內容）。改成 404 會讓兩條渲染路徑的輸出分歧，
   直接打破 `test_renderer_and_shortcode_paths_render_identical_dom`（shortcode 情境不可能
   對宿主頁面回 404）。與 TD-13 是同一類問題：呈現設定不是存取控制。已登記 TD-46。

**「已結束 + 倒數」的盤面一律歸零**（`countdownTarget = 0`）：已結束的賣場沒有未來目標可
倒數，真正傳達訊息的是搭配的自訂文字。若要「下一檔期開賣倒數」，必須新增獨立的
`nextStartTime` 欄位——**不可**讓後端猜測，猜錯的成本是顧客看到一個永遠不會到來的時間。
已登記 TD-45。

⚠️ `to_display_parts()` 另有一條**舊格式相容分支**：本設定最初實作成單選字串
（`hidden` / `countdown` / `browse`），同一天內改成多選，開發中的資料庫可能還留著字串值，
因此對映成等價的區塊組合（`hidden` → `[]`、`countdown` → `[countdown, text]`、其餘 → 預設）
而不是丟掉——丟掉會讓站長已設好的倒數安靜消失。前端 `toDisplayParts()` 有同款分支。
這批相容碼**沒有正式發版過**，確認所有開發環境都已重新存檔後可移除。

**倒數盤（daisyUI `pc-countdown`）的三個實作細節：**

- **🔴 字級 utility 掛在外層 `<div>`，不可掛在 `.pc-countdown` 本身**（即使 daisyUI 官方
  範例寫的就是 `class="countdown font-mono text-5xl"`）。powerhouse 的 Tailwind 是
  `important: '#tw'`，`#tw .text-3xl{font-size:1.875rem;line-height:2.25rem}` 的 (1,1,0)
  會蓋掉 daisyUI 根部錨定的 `:root .pc-countdown{line-height:1em}` (0,2,0)——而倒數盤
  正是靠「行高恰等於 `1em`」在運作（`::before` 以 `top: calc(var(--value) * -1em)` 捲動，
  每行高度卻是 line-height）。兩者不等就每格累積偏移，畫面上是「上下兩個數字各露一半」，
  **沒有任何錯誤訊息**。修法是讓 daisyUI 的規則**直接命中**元件本身：直接命中的宣告永遠
  優先於繼承值，與 specificity / CSS 順序無關，因此是決定性的（**不要**改用 `leading-none`
  對打，那是同分靠順序決勝；何況它在 `front.min.css` 沒產出）。完整推導見 CLAUDE.md
  Pitfall #29 與 `ProfitShopFrontView::countdown_unit()` 的 docblock。
- **只支援 0–99**：`.pc-countdown > *:before` 的 content 是 `"00\A 01\A … 99"` 這串字面值，
  靠 `top: calc(var(--value) * -1em)` 捲到對應那一行；`--value` 超過 99 會捲過字串
  結尾、**顯示成空白**（不是報錯）。天數欄位因此同時印出 `.pc-countdown` 與純文字兩格，
  用 `tw-hidden` 切換（**不可**改用 `tw-inline`，見 CLAUDE.md Pitfall #27）。
- **首屏數字由 PHP 用伺服器時間印出**，之後每秒遞減才交給 JS——與 `resolve_shop_status()`
  同一條理由（避免瀏覽器時鐘偏移造成「後台已開賣、前台仍在倒數」）。JS 停用時畫面是一個
  靜止但正確的快照。倒數歸零後 JS 會重新載入頁面，但**只有初始 remaining > 0 才排程**，
  否則臨界秒數會變成「載入 → 立刻歸零 → reload」的迴圈。

**後台送出 `settings` 的兩條紀律**（實作見 `utils.ts` 的 `mergeScheduleSettings()`）：
以既有 `record.settings` 展開為底（否則 `btnColor` / `showStock` / `showBuyerCount` /
`showConfetti` 與 legacy 未知 key 會被整批清掉）、清空的時間寫 `0` 而非刪 key
（`base` 裡的舊值不會被覆蓋，症狀是「時間清掉了，存檔後又跑回來」）。前端細節見
`react.rule.md` §6.5。

#### 變體比對：任意 (Any) 屬性是 wildcard，但具體變體必須優先（2026-08-05）

賣場前台商品 Modal 的變體比對（`inc/assets/js/profit-shop-front.js` 的 `matchVariation()` /
`findBestVariation()` / `isAllAttributesChosen()` / `hasAnyAttribute()`）在 2026-08-05 分三輪
修復。這段邏輯決定「使用者選了哪些屬性 → 送哪一個 `variation_id` 進購物車」，而 **Phase 9 的
變體層價格覆寫是以 variation_id 為鍵**——挑錯變體在本專案不是顯示瑕疵，是**收錯錢**，因此
紀律比 WooCommerce 官方更嚴。

三輪的分工：第一輪修「Any 屬性被當成必須等於空字串」的主 bug（該類商品結構性無解）；
第二輪修「wildcard 豁免沒有配對具體優先」而少收錢；第三輪把第二輪的兩趟法換成
**單趟 + 具體度計分**（兩趟法只分兩級，仍有一整類會挑錯的情境，見下）。
⚠️ 讀舊 commit / 舊文件時若看到「兩趟」「`findVariation( allowWildcard )`」「第一趟 / 第二趟」
「三個以上屬性才會有極限」「結果與陣列順序無關」——**那些敘述已經全部作廢**，以本節為準。

##### WooCommerce 的「任意 (Any)」表示法：空字串，且**每個變體都一定帶滿全部屬性鍵**

`wc_get_product_variation_attributes()`（`woocommerce/includes/wc-product-functions.php:862`）
先把父商品**全部** `is_variation` 屬性 seed 成 `''`（原始碼註解逐字寫著
`// Add it - 'any' will be assumed.`），再用該變體自己的 postmeta 逐一覆蓋。沒被覆蓋到的那
一格就維持空字串 = 「任意」。

兩個推論，兩個都被本專案的程式碼依賴：

1. 變體的 `attributes` 物件**必定含有父商品全部的變體屬性鍵**（缺鍵代表資料異常或被第三方
   `woocommerce_available_variation` filter 動過）。
2. 空字串**不是**「值為空」，是 **wildcard**。把它當成「必須等於空字串」來比對，該屬性就
   **沒有任何使用者輸入能匹配**（使用者在 UI 上永遠選不出空字串）——這正是第一輪修復前的
   bug：含 Any 屬性的商品在 Modal 內**結構性無解**，按鈕永遠停用，且沒有任何錯誤訊息。

##### 「選滿」與「值比對」是兩件獨立的事，不可擠進同一個 if

官方 `add-to-cart-variation.js` 也把這兩件事分開放：

| 官方位置 | 做什麼 | 對應本專案 |
|---|---|---|
| `:200` `attributes.count && attributes.count === attributes.chosenCount` | 使用者是否**每一組屬性都選了具體值** | `isAllAttributesChosen()` |
| `:575` `isMatch()` 的 `val1.length !== 0 && val2.length !== 0` 才比對值 | 空字串**豁免**，不參與否決 | `findBestVariation()` 內 `expected === ''` 的 `continue` 分支 |

⚠️ **Any 屬性一樣要求使用者選出具體值**——`WC_Cart::add_to_cart()`
（`class-wc-cart.php:1091`）的註解寫得很白：`// Filter out 'any' variations, which are empty,
as they need to be explicitly specified while adding to cart.`。所以豁免只發生在**比對**這一層，
不能連帶放寬**選滿**那一層；混寫成一個判斷式的話，只選 Color 不選 Size 也會匹配成功、按鈕啟用，
送出後被 WooCommerce 判「缺少必填屬性」拒絕——症狀從「按鈕永遠停用」變成「按了換頁還報錯」，
只是換一個 bug。

##### 「選滿」判斷刻意以 DOM 為來源，不從變體 JSON 的鍵集反推

`attributeNames` 數的是畫面上 distinct 的 `data-ps-attr-name`，**不是** `Object.keys(variation.attributes)`。
三個理由，第二個是關鍵：

1. 需求本身就是「選滿**畫面上**所有屬性欄位」，畫面上有幾組，DOM 說了算。
2. 🔴 **兩道防線不可押在同一個假設上**。上面推論 1（變體必含全部鍵）正是 wildcard 比對賴以
   成立的前提；若「選滿」也從變體 JSON 反推，第三方 filter 一旦剝掉空值鍵，**兩道防線會同時
   失效**——畫面上有兩組屬性、JSON 裡只剩一組，使用者只選一組就被判定「已選滿」，接著把一個
   實際上是 catch-all 的變體送進購物車。
   （「挑哪一個變體」這件事計分法本身不受剝鍵影響，理由見下節；但「選滿」是另一道關卡，
   它會失效。兩者不要混為一談。）
3. 官方 `getChosenAttributes()`（`:526`）數的也是 DOM 欄位。

`attributeNames` 用 `indexOf` 而非 `{}` 當 set：屬性名若剛好叫 `toString` / `constructor`，
物件查表會命中 `Object.prototype` 的成員而被誤判為重複、少算一組。`isAllAttributesChosen()`
內同理用 `typeof value !== 'string'` fail-closed。

##### 🔴 挑選規則：具體屬性數最多者勝（單趟計分）——刻意偏離官方

`findBestVariation()` **掃完全部候選**（不 early return），對每個匹配成功的變體算一個
`score` = 它的 `attributes` 中**值非空字串**的屬性個數（＝它釘死了幾個條件），取最高分者；
`matchVariation()` 只多加一道 `isAllAttributesChosen()` 前提。`expected === ''` 走 `continue`
（wildcard，**不計分**），這一行與 `score++` 就是具體度差異的唯一來源。

**為什麼不能是「第一個命中者勝」：**

```
#1 { color: '',    size: '' }  ← 全域 catch-all，售價 500
#2 { color: 'red', size: '' }  ← 紅色專屬覆寫，售價 800
選 red + l →  #2 的約束集是 #1 的嚴格超集，語意上毫無歧義，#2 是唯一正解
```

而 `get_available_variations()` 的順序是 `menu_order ASC, ID ASC`——先建 catch-all 是很自然
的操作順序，「命中即回傳」就會拿到 #1。後果有四層，**四層都沒有任何錯誤訊息**：

| # | 後果 |
|---|------|
| 1 | **金額直接錯**：站長為 red 設的 800 被繞過，Modal 顯示 500、實收 500 |
| 2 | **Phase 9 變體層覆寫一併失效**：`OverrideItem::$variations` 以 variation_id 為鍵，針對 #2 設的 `VariationOverride`（原價／特價／灌水人數）永遠套不到。`SingleProductPriceHook` / `CartPriceOverrideHook` 只會忠實按 #1 算——**三道閘門沒有任何一道會發現拿錯了變體** |
| 3 | **AJAX 分流跟著錯**：`hasAnyAttribute()` 判斷的是「選中的那一個」，挑錯就可能該走 AJAX 卻降級換頁，或反之 |
| 4 | **原生降級路徑同樣錯**：hidden input 的 `variation_id` 寫進去的是錯的那個 |

⚠️ **官方就是「第一個命中者勝出」**——`findMatchingVariations()`（`:553`）收集全部匹配者後，
呼叫端（`:231-232`）直接 `.shift()` 取第一個，完全不分具體與 wildcard。**本專案刻意比官方
嚴謹**，因為官方沒有分潤價格覆寫這一層：在官方那裡挑錯變體只是顯示差異，在本專案等於**少收
錢**。這是明示的產品決策，**不是**沒讀官方原始碼。

⚠️ **連帶的已知取捨**：同一個「catch-all + 部分具體覆寫」商品，賣場 Modal 會選 #2、WC 單品頁
（官方 `.shift()`）會選 #1，**兩處顯示價可能不同**。可達性低（variable 卡片雖連向 permalink，
但 JS 啟用時被攔截改開 Modal），且 #2 才是語意正確解，因此接受；日後若有人回報「Modal 800、
單品頁 500」，那是這個取捨、不是新 bug。

**🔴 兩趟法（第二輪的作法）為什麼不夠——這是最容易被「簡化」回去的地方**

第二輪曾用 `findVariation( false ) || findVariation( true )`：第一趟排除所有含空字串的變體、
第二趟才放行。它只分**兩級**——「全具體」與「其餘全部」（1 個 wildcard、2 個、全 wildcard
混為一談）。只要**參與競爭的兩個變體都不是全具體**，它們就一起落到第二趟，回到陣列順序決勝。
上面那個 `#1` / `#2` 反例正是如此：**兩個屬性就會壞，不需要三個**。

命中率不是理論風險（第三輪以窮舉 + 隨機差分實測，裁判函式獨立實作、不共用受測程式碼）：

| 樣本 | 兩趟法挑到「非唯一最佳解」 | 計分法 |
|---|---|---|
| 3 屬性隨機差分（22401 個有匹配樣本） | 2768 次（**12.4%**） | **0** |
| 2 屬性窮舉（104 組） | 4 | **0** |
| 形狀庫窮舉 11760 案例（含剝鍵變形） | 960 | **0** |

**為什麼計分法完整而兩趟法不完整**：全具體變體的 score = 屬性總數 = 最大可能值，所以
「全具體必勝」是計分法的**自然推論**、不需要特例；而兩趟法把「全具體 vs 其餘」寫死成二元
分級，其餘級別全部混為一談。更一般地：若 X 釘死的屬性集合是 Y 的**嚴格超集**，則
`score(X) > score(Y)`，**被支配者永不勝出**——這是可證的，不只是統計結果。

**📌 平局取陣列第一個，而那正是唯一沒有客觀正解的情況**

`{red, ''}` vs `{'', large}`、使用者選 red + large：兩者 score 都是 1、誰也不是誰的超集，
站長的兩條規則本身就衝突，資料裡沒有任何欄位承載「哪條優先」。此時退回 WooCommerce 官方
`.shift()` 的行為（取陣列第一個，實際順序由 `menu_order ASC, ID ASC` 決定）是合理且可預期
的——而且與單品頁一致。**唯一保留順序決勝的情況，正好是唯一沒有客觀正解的情況。**
（顯示價會依變體建立順序而定，已登記 `specs/tech-debt.md` TD-53；真要消歧義應該在後台偵測
並警告這種互相衝突的 Any 配置，而不是在前端 JS 猜。）

**📌 對「第三方 filter 剝掉空值鍵」天然免疫（僅限挑選這一層）**

若有外掛在 `woocommerce_available_variation` 上把空字串的屬性鍵整個刪掉，
`#90 {color:'red'}`（size 鍵被剝掉）在「有沒有空字串」的**二元**判斷下會被誤認為「全具體」
而直接勝出，蓋掉真正更具體的 `#91 {color:'red', size:'large'}`——兩趟法會踩，實測確認。
計分法比的是**具體屬性數的絕對值**（#90 = 1 < #91 = 2），不看有沒有空字串，因此不受影響。
⚠️ **但這條免疫只涵蓋「挑哪一個」**：`hasAnyAttribute()` 仍是靠「有沒有空字串」判斷的，
剝鍵情境下它會誤判為全具體 → 走 AJAX → 伺服器端 `get_variation_attributes()` 仍拿回帶 `''`
的完整集合 → 被 `WC_Cart::add_to_cart()` 判缺必填屬性而拒絕。這是既有弱點，非本輪引入。

**⚠️ 三條不要做的事：**

1. **不要改回「命中即 `return`」**（不論單趟或分級多趟）。全 Any 商品在任何寫法下表面上都會
   動，手測極易誤判「沒差」；真正壞掉的是上面那種混合商品，而且只在特定陣列順序下才壞。
2. **不要為了「對齊官方 `.shift()`」而改**。理由見上：官方沒有分潤覆寫這一層。
3. **不要拿效能當理由。** 計分法因為不能提早 return，在多數情境其實**比兩趟法慢**（實測：
   真實規模 ≤ 24 變體差距 < 0.01 ms；最壞的人造情境 1000 變體全 Any 為 2.1 ms），只有「完全
   無命中」時較快。但兩者都遠低於一幀（16 ms），而 `refresh()` 每次點選只呼叫一次。
   **選計分法的理由是正確率（12.4% → 0），不是速度。**
   ⚠️ `matchVariation()` 的 docblock 目前有一句「單趟計分比舊的兩趟法**更快**」與實測相反，
   已登記 `specs/tech-debt.md` TD-54，下次動這個檔案時順手改掉。

##### 兩條不可刪的守門

| 守門 | 職責 | 誤刪的後果 |
|---|---|---|
| `keys.length > 0`（`findBestVariation()` 尾端，與 `score > bestScore` 同一個 `if`） | 防 **vacuous truth**：`attributes` 為 `{}` 時內層迴圈一次都不跑、`matched` 保持初值 `true`、`score` 保持 `0` | 那個空殼變體會以 score 0 成為候選，在「全部候選都是 0 分」時勝出（例如全 Any 商品）。**它不是「已選滿」檢查**（那是 `isAllAttributesChosen()` 的職責），兩者不可互相取代 |
| `picked === null`（內層迴圈） | 第二道 fail-closed：變體宣告了某屬性、但畫面上沒有對應的選項群組（`is_variation` 屬性未指派任何 term） | 這種商品在官方單品頁同樣選不齊、WooCommerce 後端也會拒收，放行只是把錯誤延後到送出時 |

##### `hasAnyAttribute()` 在第一輪修好之前是不可達死碼

它負責「選中的變體含 Any 屬性 → 不走 AJAX，交給原生表單送出」。理由在 WooCommerce 原始碼
裡是硬的：`WC_AJAX::add_to_cart()` **全文不讀 `$_POST['attribute_*']`**，它偵測到傳入的是
variation 之後，一律用 `$product->get_variation_attributes()` 自己反查——對 Any 屬性拿回來
的就是**空字串**，接著 `WC_Cart::add_to_cart()` 濾掉空值、判定缺少必填屬性而拒收。前端就算
把 `attribute_*` 一起送過去也沒有用（那條通道根本不看）。原生表單送的是使用者實際選的值，
不受影響——所以降級走原生表單是這條路徑唯一可行的解。

但第一輪修好之前，含 Any 屬性的變體**根本匹配不出來**，這個函式因此從未被執行過（不可達
死碼），第一輪之後才第一次真正跑到。

⇒ **「Any 屬性商品加購會換頁、沒有 toast」是正確結果，不是回歸。** 不要因為「Phase 15 明明做了
AJAX 加購，怎麼這個商品還在換頁」就把這道降級拿掉——拿掉之後那筆加購會被 WooCommerce 直接
拒絕，使用者看到的是「按了沒反應」。⚠️ 換頁之後**目前也不會有成功提示**（`.ps-shop-notices`
容器有印、內容是空的），那是另一個獨立的缺陷，見 `specs/tech-debt.md` TD-49。

#### 無定價商品：四道閘門與 per-cart-item 授權（2026-08-06）

「商品／變體**自身沒有價格**（`_price` 為空字串），只靠賣場覆寫價出售」——這個組態從 Phase 9
開放變體層覆寫起就設定得出來，但**前台從頭到尾買不到**，而且沒有任何錯誤訊息。

2026-08-06 共四輪：第 4 輪找根因並修好顯示層；第 5 輪把 cart 層授權從「product ID × 整個
request」收斂成「**單一 cart item**」；第 6 輪收斂 11 條驗證缺口（TD-56～62 / 65）；
第 7 輪修掉兩條**第 6 輪自己引入或沿用**的缺陷（判準未對齊、`apply_override()` 巢狀重入）。
⚠️ 讀第 4 輪的 commit / 註解時若看到 `$priceless_rescue`、`rescue_for()`、`build_rescue_map()`、
`read_cart_source()`——**那整組已刪檔、不存在**，以本節為準。

##### 🔴 三條跨輪教訓（每一條都是實際踩過才寫下來的，優先於下面所有細節）

**(a) 一條規則若同時存在「顯示」與「加購」兩條路徑，必須兩邊都實作。**
R-4 裁決（「商品層覆寫不救半成品變體」）第一次只實作在顯示層，前台正確顯示「暫無可購買規格」、
卡片停用——**而 `POST /?wc-ajax=add_to_cart` 帶那個變體 ID 加購成功、帶合法簽章、產生真實訂單**
（TD-56）。最惡毒的部分是：**顯示層探針會回報 PASS**，因為它問的是「畫面對不對」，
而畫面確實是對的。判斷一個「該不該賣」的規則有沒有實作完整，唯一可靠的問法是
「**繞過畫面直接送 POST 會怎樣**」。

**(b) 抽共用 helper 統一呼叫端，不等於對齊了語意。**
第 6 輪把顯示層與加購層的行內判斷抽成 `PricelessRescuePolicy`，確實消滅了「兩個呼叫端各寫一份」
——**但從未與真正決定行為的那個下游（`OverridePriceResolver`）對齊**：政策用「條目**存在**」、
resolver 用「條目存在**且有 ≥1 個非 null 價格**」，於是「全 null 空條目」被政策放行、
再由 resolver fallback 到商品層覆寫，正好構成 R-4 禁止的組合（TD-66）。
兩份定義還在，只是換了位置繼續放著。**抽共用 helper 時要對齊的不是「其他呼叫端」，
而是這個判斷最終服務的那個決策。**

**(c) 凡是寫進註解的行為斷言，都必須有當次實測支撐。**
本專案已經因此吃虧**三次**：TD-20（`<Alert>` 文案宣稱「單品頁仍顯示原價」，而同一次改動的
`SingleProductPriceHook` 已讓它顯示覆寫價）、TD-54（docblock 宣稱單趟計分「更快」，實測慢 2.55×）、
TD-67（`reset_to_original_price()` 註解宣稱 `remove_cart_item()` **不會**呼叫 `calculate_totals()`
——與實際行為相反，且被第 5 輪當成「已驗證事實」沿用進第 6 輪的設計）。
這類缺陷 `phpcs` / PHPUnit / `tsc` **一個都攔不到**，只有這條紀律擋得住。
⚠️ 連帶紀律：**引用第三方原始碼行號時必須寫出版本**——本節行號一律以本機站台的
**WooCommerce 10.1.4** 為準（已逐行核對），而 wp-env 容器裝的是另一個版本
（實測 11.0.0-rc.1，同兩行分別位移到 `:135` / `:1476`）。行號對不上時先確認 WC 版本，
不要直接認定註解寫錯。

##### 根因：四道閘門，同一個條件 `'' !== get_price()`

行號以 WooCommerce 10.1.4 為準（見上方 (c) 的版本警告）。

| # | 位置 | 何時跑 | 不通過的後果 |
|---|---|---|---|
| ① 顯示 | `class-wc-product-variable.php:335` → `variation_is_visible()`（`class-wc-product-variation.php:593`） | `get_available_variations()` | 該變體被 `continue` 跳過，**不進前台 payload** |
| ② 加購 | `class-wc-cart.php:1228` `is_purchasable()` | `WC_Cart::add_to_cart()` | 直接 throw、加不進去 |
| ③ 每次載入購物車 | `class-wc-cart-session.php:183` `woocommerce_cart_item_is_purchasable` | 每一個載入購物車的請求（`wp_loaded`） | **把品項移除**（`:184-214`）並顯示「已無法購買」 |
| ④ Store API / Block | `StoreApi/Utilities/CartController.php:270`（加購）/ `:688`（`validate_cart_item`） | Cart Block / Store API / Block 結帳 | `NotPurchasableException` |

**為什麼覆寫價「疊不上去」**：賣場覆寫價是在「拿到變體清單**之後**」才逐筆疊上去的
（`ProfitShopFrontData::build_variations()` 的 foreach）。閘門 ① 把清單濾成空的，那個 foreach
一次都不會進迴圈，`$item->get_variation_override()` 從未被呼叫——**沒有東西可以疊**。
所以修法必須是**修根因**（讓這些商品在對的範圍內 `get_price()` 回覆寫價），而不是逐一拆掉四道
閘門；拆閘門會把「真的不可購買」的商品（下架、父商品未發佈、庫存耗盡）一起放行。

##### 🔴 授權粒度是本題的核心：綁「一筆 cart item」，不是「一個 product ID」

第 4 輪用一張 request 級的「product ID → 價格」救援表，等於對整個請求宣告
「這個商品在這裡到處都值 27 元」。授權本來只該是「**這一筆通過 HMAC 驗章的 cart item** 值 27 元」。
三個獨立 lens（安全 / 回歸 / 真實站台）各自驗到同一批後果，**全部沒有任何錯誤訊息**：

| 第 4 輪的後果 | 實測症狀 |
|---|---|
| **無歸屬銷售**（最嚴重） | 從賣場合法加購一次建立救援表後，**同一個請求內**該商品在一般單品頁變成可購買；不帶 `profit_shop_id` 再加一次 → 顧客付到分潤價、**cart item 零 `_profit_*` meta、零簽章、零分潤紀錄**。夥伴拿不到結算 |
| **一般情境的 `get_sale_price()` 被污染** | 守門是**逐欄位**判空字串，而絕大多數正常商品的 `get_sale_price()` 本來就回 `''` → 整批正常商品被拖進救援邏輯。實測：原價 999 無特價的變體，在購物車有該賣場品項時，一般單品頁顯示 `~~$999~~ $999`、`is_on_sale()` 由 false 翻 true → 帶 `exclude_sale_items` 的優惠券整批失效 |
| **陳舊簽章價格復活** | 救援表無條件合併持久化購物車 usermeta（WooCommerce 自己的條件在 `class-wc-cart-session.php:116`，只在 session cart 為 null 或帶合併旗標時才做）。而 usermeta 永不過期、簽章也沒有時效 → 站長把覆寫價改高之後，舊價仍可在全站復活 |

⇒ **支點是 WooCommerce 免費提供的**：`WC_Product_Factory::get_product()` 每次都
`return new $classname(...)`，`wc_get_product()` **不做實例快取**。因此對「某一筆 cart item
自己的 `WC_Product` 實例」呼叫 `set_price()`，**在結構上不可能**外溢到同一請求內任何其他地方——
一般單品頁 / 商店彙整 / 搜尋結果 / 相關商品拿到的都是**另一個物件**，價格仍是 `''`、
`is_purchasable()` 仍是 false。第 4 輪把這個免費的隔離性丟掉，改用全域 filter 自己重建了一個
更差的版本。

**不要**因為「一張表比較快 / 比較好懂 / 少一次驗章」就改回去。上表三條會一起回來，而且
自動化測試不會紅——它們全都發生在「不帶 `profit_shop_id` 的一般情境」，是既有測試的盲區。

##### 各閘門的介入點與授權來源

| # | 介入方式 | 授權綁在什麼上 | 驗證失敗時 |
|---|---|---|---|
| ① | `OverridePriceFilters::arm()` / `disarm()`，`try/finally` 包住**單次** `get_available_variations()` | 單次函式呼叫 × 單一 (shop, product) | resolver 回 `null` → filter 原樣放行 → `get_price()` 仍 `''` → 正確被擋 |
| ② | 一次性 `$pending_add`（見下） | 單一 `variation_id ?: product_id` × 單次 `add_to_cart()` | 未 arm → WC 照常 throw |
| ③ | `woocommerce_cart_item_is_purchasable`（`authorize_cart_item()`） | **該筆 cart item 自己的 `_profit_*` meta + HMAC 驗章** | 回傳**原始** `$purchasable`（false）→ WooCommerce 自己移除品項（**fail-closed**） |
| ④ | 無 per-item filter（見下），靠 ③ 已對同一個 `$product` 實例 `set_price()` | 同 ③ | 同 ③ |

**閘門 ③ 的 hook 是唯一正解**：`grep -rn "woocommerce_cart_item_is_purchasable"` 全 WooCommerce
**只有 `class-wc-cart-session.php:183` 一處**，而且是唯一帶 `$key` / `$values` / `$product` 的
per-item purchasable hook——可以直接從 `$values` 讀該筆的 `_profit_*` meta 驗簽章，授權因此天然
綁在單一 cart item 上。三道早退缺一不可，**第二道就是「一般商品被污染」的直接修法**：

1. `$purchasable` 已為 true → 不介入（一般商品完全不進入後續邏輯）
2. `$product->get_price( 'edit' ) !== ''` → 不介入。它不可購買是**別的原因**（下架 / 父商品未發佈 / 缺貨），那些理由我們無權推翻。⚠️ **守門條件是「這個商品自身有沒有價格」，不是逐欄位判空字串**
3. 驗章不過 → 回傳**原始** `$purchasable`，**絕不回 `true`**——回 true 會製造「`is_purchasable()` 為真但 `get_price()` 為空」這種 WooCommerce 從未預期的狀態，接著 `reset_to_original_price()` 的 `set_price('')` 就會變成 0 元商品

**閘門 ④ 不需要另外的 hook**（讀過原始碼確認，不是猜的）：`CartController::validate_cart_item()`
讀的是 `$cart_item['data']`（`:681-688`），而那個物件正是 `class-wc-cart-session.php:234` 的
`'data' => $product`——**與閘門 ③ callback 收到的 `$product` 是同一個實例**。在 ③ 就地
`set_price()` 等於同時修好 ④。另有第二層保險：`StoreApi/Routes/V1/Checkout.php:425-436` 的順序是
`calculate_totals()`（→ `apply_override()` 999）在 `validate_cart()` **之前**。
⚠️ Store API 的 `cart/add-item`（`:270`）**刻意讓它失敗**——那條通道不帶 `profit_shop_id`、
沒有 cart item 可驗章，無定價商品經它加購必然被拒，這是正確的 fail-closed，不是漏了一個 hook。

##### 閘門 ② 的一次性授權：四個關閉點取代 try/finally

`add_cart_item_data`（`class-wc-cart.php:1191`）拿不到 `WC_Product` 物件，只有 id，所以這一道
只能用旗標。四個必須照做的點：

1. **比對鍵一律是 `variation_id ?: product_id`**。WC 自己在 `:1073` 就把傳入的 variation 換算成
   「父商品 + 變體」，`:1228` 檢查的是 `wc_get_product( $variation_id ?: $product_id )`。
   用父商品 ID 比對會讓**同父商品的其他變體**一起被放行 = 重新製造一個小號的「無歸屬銷售」。
2. **只在 `$target->get_price( 'edit' ) === ''` 時才 arm**。全站 99.9% 的加購不會進這個分支，
   「一般商品的價格永遠不經過我方 filter」因此是可斷言的性質。
3. **關閉點 (1) 是 `add_cart_item_data()` 的第一行無條件清空**——這是我們能取得的最強
   try/finally 等價保證（arm 與 consume 之間是 WooCommerce 的本體，包不住）。它同時涵蓋
   grouped 商品一個請求內多次加購，以及上一次 add 在 `:1200`–`:1318` 之間拋例外的殘留。
   **⇒ 旗標在結構上無法被用來加購第二件商品**：任何第二次 `add_to_cart()` 都必先觸發
   `add_cart_item_data()`（`:1191` 早於 `:1228`），第一行就清掉了。
4. 關閉點 (2) `woocommerce_add_to_cart`（`:1338`）prio 1 —— 同一個 callback 順便把價格釘在
   `cart_contents[$key]['data']` 上（否則同請求後續的 mini-cart / fragments 會顯示 NT$0）；
   關閉點 (4) `wp_loaded`（`PHP_INT_MIN`）/ `woocommerce_cart_emptied` 是給 worker-mode PHP
   （FrankenPHP / RoadRunner / Swoole）的不變量宣告，本站 PHP-FPM 用不到但不可刪。

**關閉點 (3)：`template_redirect`（`PHP_INT_MIN`）——加購失敗後的殘留上限**（N-3，第 6 輪補上）。
`WC_Cart::add_to_cart()` 整個包在 try/catch 內（`class-wc-cart.php:1347`），失敗時自己
`wc_add_notice` + `return false`，**失敗路徑上沒有任何 hook 會跑到**（`woocommerce_add_to_cart`
在 `:1343`，只在成功路徑）。所以 arm 與 disarm 之間一旦失敗，旗標本來會留到請求結束，
該請求內任何 `wc_get_product($target_id)` 都會拿到覆寫價且 `is_purchasable()` 為 true。
這一行把上限收斂到「`template_redirect` 之前」，兩條落地路徑都涵蓋：

- **表單加購**：`WC_Form_Handler::add_to_cart_action` 掛 `wp_loaded` prio 20
  （`class-wc-form-handler.php:33`），失敗後請求繼續往下 ⇒ 本行在**任何頁面渲染之前**清乾淨。
- **`?wc-ajax=add_to_cart`**：`WC_AJAX::do_wc_ajax` 掛 `template_redirect` prio 0
  （`class-wc-ajax.php:36`），`PHP_INT_MIN` 排在它**之前**，因此**不會誤清當次加購的授權**；
  而該路徑失敗後立刻 `wp_send_json` 結束、沒有任何渲染，殘留無從被消費。

⇒ `wp_loaded` 與 `template_redirect` 之間（`parse_request` / `send_headers` / `wp`）沒有任何價格
渲染，**殘留視窗內不存在可觀察的效果**。⚠️ 這條 prio 的**方向性**是關鍵：排在 `do_wc_ajax`
之後就會把當次合法加購的授權一起清掉，變成「AJAX 加購無定價商品永遠失敗」。

⚠️ **`wc_get_cart_item_data_hash()` 不含價格**（`wc-cart-functions.php:591-604`，只 hash `type` +
`variation_attributes`），所以「加購時價格被 arm、下次載入時沒有」**不會**觸發
`class-wc-cart-session.php:204` 的 data_hash 不符移除。這件事必須寫在註解裡，否則下一個人會以為
漏處理了 hash 一致性。

##### 驗章失敗時不可變成 0 元——守門與還原值都必須取 `_price`

`reset_to_original_price()` 對「商品自身沒有原價可以退回」（`get_price('edit') === ''`）的情況
**把品項移出購物車**。若照舊 `set_price('')`，WooCommerce 會當 0 計算，
把「竄改 ⇒ 付原價」倒轉成「竄改 ⇒ **免費**」。

🔴 **守門欄位必須是 `get_price('edit')`，不可寫成「sale 與 regular 皆空」**（S-4 / TD-60，
第 6 輪已修）。兩者**不等價**：只寫 `_price` 是 WooCommerce 明文承認的合法狀態
（`class-wc-product-data-store-cpt.php:818-823` 的註解寫「`_price` can also be set as a
temporary active price」），匯入 / 程式建立的商品常見 `price='500' / regular='' / sale='' /
is_purchasable()=true`——用舊守門，這種**完全正常販售中**的商品一旦驗章失敗就被直接刪出購物車。

⚠️ **只改守門、不改還原值等於沒修**：還原那一行若用 `sale ?: reg` 推導，對這類商品會得到空字串
→ `set_price('')` → 下一個請求被閘門 ③ 判定不可購買而移除，顧客看到的症狀完全一樣，
只是從「立刻消失」延後成「下一個請求消失」。兩處都要取 `$own = get_price('edit')`。

⚠️ **觸發不需要攻擊者**：輪替 `AUTH_SALT`（標準資安事件處置動作）會讓**全站簽章一次失效**
⇒ 所有這類品項同時走進這條路。這也是下面那條重入缺陷會在真實世界被引爆的場景。

##### 🔴 `remove_cart_item()` **會**遞迴回本 hook——`$applying` 旗標是必要的

本檔曾經斷言「`WC_Cart::remove_cart_item()` **不會**呼叫 `calculate_totals()`，所以不會遞迴回本
hook」。**那句話是錯的**，而且被第 5 輪當成已驗證事實沿用。逐行查證（WooCommerce 10.1.4）：

```
includes/class-wc-cart.php:1372  do_action( 'woocommerce_cart_item_removed', $cart_item_key, $this );
includes/class-wc-cart.php:131   add_action( 'woocommerce_cart_item_removed',
                                    array( $this, 'calculate_totals' ), 20, 0 );
```

⇒ `reset_to_original_price()` 內的 `remove_cart_item()` 會**從 `apply_override()` 自己的 foreach
內部**再次觸發 `woocommerce_before_calculate_totals` → 回到本 hook（priority 999）。
實測（探針夾在 998 / 1000 量測**真實堆疊深度**）：

| 驗章失敗的無定價品項數 N | 修正前巢狀深度 | 修正後 |
|---|---|---|
| 2 | **2** | `calculate_totals` 2、`apply_override` body **1** |
| 6 | **6** | 同上 |
| 12 | **12** | 同上 |
| 16 / 50 | —（1:1 成長） | 同上，真實堆疊峰值恆為 28 frames |

**與 N 1:1 成長**，每一層都是完整的 `calculate_totals()`（正式環境含稅金 / 優惠券 / 運費）；
Xdebug 預設 256 frame 限制下大購物車會 fatal。正確性在修正前後**逐格相同**（混合購物車實測：
合法品項留存、覆寫價未漏套、error notice 每筆剛好 1 則、總額一致），所以**不是**定價或授權繞過
——但它會在上面那個 `AUTH_SALT` 場景全站同時觸發。

**修法選 re-entrancy 旗標（`private bool $applying`），不選「收集 key、迴圈後再刪」**：
後者同樣能把深度壓到 2，但**沒有消滅重入**——重入時仍會對剩下每筆品項重新驗一次 HMAC（O(N×M)）；
旗標讓巢狀呼叫在第一行 `return`，且不需要新增「待刪清單」這個狀態。
**`try/finally` 不可省略**：旗標若因例外卡在 `true`，本 request 後續的 `calculate_totals()`
會完全跳過套價 ⇒ 錯誤定價且無錯誤訊息。

⚠️ 迴圈內移除的安全性靠**兩件事**，不是一件：(1) 迴圈跑的是 `$cart->get_cart()` 回傳的陣列
**複本**（沒有「邊迭代邊改集合」問題）；(2) 旗標擋下重入。**若日後移除旗標，就必須改成
「收集 key、迴圈結束後再刪」**，否則深度立刻回到 1:1 成長。

⚠️ **量測方式本身踩過一次坑**：第一版回歸測試用 reflection 讀 `$applying` 判斷「body 有沒有跑」
——把守門改成 `if ( false )` 之後旗標**仍然會被設成 `true`**，測試回報「深度 1」**全綠 = 假綠燈**。
改成夾 998 / 1000 量測真實堆疊深度才騙不過（紅燈實測 N=8 → 深度 8），而且與實作解耦
（換成收集-後刪同樣適用）。另需 `remove_action` 拿掉 `set_up()` 用 `new` 額外註冊的第二個實例，
否則量到的深度比正式環境多一層（正式環境走 `Loader` 的 `::instance()`，恆為 1 個實例）。
回歸鎖：`CartPriceOverrideHookTest::test_apply_override_never_re_enters_itself` +
`test_reentrancy_guard_preserves_mixed_cart_correctness`。（TD-67）

##### R-4 裁決：變體層無覆寫就不救（政策已抽成 `PricelessRescuePolicy`，兩條路徑共用）

**裁決**：站長只設了**商品層**覆寫時，「自身從未定價的半成品變體」**不該**因此變成商品。
理由：站長設商品層覆寫的意圖是「這個商品在我的賣場賣 X 元」，不會預期連沒填價格的半成品變體
都一起上架；而 `ItemsEditor` 的展開列本來就提供逐變體設定的入口，要救就明確設變體層覆寫，
操作與結果一一對應。

🔴 **判斷的唯一實作是 `Infrastructure/WooCommerce/PricelessRescuePolicy::should_rescue_priceless(
WC_Product, OverrideItem ): bool`**，兩個執行點各自呼叫它、**不各留一份**：

| 執行點 | 何時判斷 |
|---|---|
| `OverridePriceFilters::resolve_for_product()`（顯示層） | 三個 `filter_*` 與三個 `variation_prices_*` 的共同收斂點，`SingleProductPriceHook`（單品頁）與 `build_variations()`（賣場卡片）共用 |
| `CartPriceOverrideHook::add_cart_item_data()`（加購層） | **必須在 `resolve_override_prices()` 之前** |

⚠️ **加購層不能用「resolver 解不解得出價格」判斷**——`OverridePriceResolver` 照 Phase 13 契約
會 fallback 到商品層覆寫，對半成品變體一樣解得出價格。這正是 TD-56 的成因：R-4 第一次實作時
只放在顯示層，前台正確顯示「暫無可購買規格」、卡片停用，但
`POST /?wc-ajax=add_to_cart` 帶半成品變體 ID **加購成功並帶合法簽章**——站長從未定價、
在自己賣場頁上看不到的 SKU 會產生真實訂單。安全 / 回歸 / 真實站台三個 lens 各自獨立驗到。

⚠️ 加購層把載好的 `WC_Product`（`variation_id ?: product_id`）**往下傳給 `arm_pending_add()`**，
兩者因此必然看到同一個實例（淨查詢數不變）。分兩次 `wc_get_product()` 目前結果相同是巧合，
而兩者不一致的後果是「政策拒絕、旗標卻開了」。

回傳 `true` **不代表「正在救援」**——商品自身有價格時也回 `true`，意思是「這道政策不阻擋」。
只有唯一一種組合回 `false`：**變體**自身無定價 **且** 沒有變體層覆寫**價**。

##### 🔴 判準必須與 `OverridePriceResolver` 逐字相同（TD-66，第 7 輪）

```php
// PricelessRescuePolicy::has_variation_price_override()
// 必須與 OverridePriceResolver:54-57 完全一致
null !== $override && ( null !== $override->regular_price || null !== $override->sale_price )
```

**「條目存在」不是判準**。`OverrideItem::get_variation_override()` 回的是
`$this->variations[$vid]?->override`——**只要那個鍵存在就回一個 `PriceOverride` 物件**，
即使三個價格欄位全是 null。用「條目存在」判斷，會讓「全 null 空條目」被政策放行，
接著 `OverridePriceResolver` 照 Phase 13 契約 fallback 到**商品層**覆寫
——正好構成 R-4 / S-1 明文禁止的那個組合。

⚠️ **空條目不需要竄改就會出現，是站長正常後台操作的產物**：`ItemsEditor.tsx` 在站長替某個變體
設定**灌水購買人數**時，用 `emptyVariationOverride()`（三個價格皆 null）建出條目；
「打了價格又清空」同樣留下空條目。送出路徑原樣送 `variations`，後端 `ItemValidator` 只驗
「變體存在且屬於該商品」，沒有任何一處會清掉它。
**「設了灌水人數但不覆寫價格」是合法狀態——要修的是判準，不是資料**，
所以**不要**改成「在前端 prune 空條目」。

實測後果（判準對齊前，wp-env 端到端）：variable 商品兩個變體皆無定價、賣場只設**商品層**覆寫
500/450、變體[0] 帶一個空條目 + `inflated_count 7` ⇒ 政策 `should_rescue=true` ⇒ 該變體以
**NT$450 加購成功**、`regular=500`、分潤 meta 齊全、v3 簽章合法、session reload 後授權仍為
`true`；`build_products()` 的 `variations` 有 1 筆、卡片 `data-ps-disabled="0"` 可點、Modal 照印
——**顧客不需要偽造任何請求，賣場頁自己就把它送上門**（比原始 S-1 需要手工 POST 更容易觸發）。
對齊後三個消費端一起關上：政策 `false` / 加購被擋（件數 0）/ `variations` 為 `[]`。

⚠️ **`signup_fee` 刻意排除**：resolver 取價時本來就不看它，納入會再度造成判準分歧。
⚠️ **不可改成呼叫 `OverridePriceResolver` 來判斷**：它會 fallback 到商品層，對空條目一樣解得出
價格，等於把守門變成恆真。判斷必須發生在 resolve **之前**。

三個必須知道的性質：

1. `get_price( 'edit' )` **不會遞迴**——`abstract-wc-data.php:880` 只在 `'view'` context 才
   `apply_filters`。
2. 🔴 **這道守門只在「變體自身無定價」時成立**。變體自身有價格時守門不成立，
   `OverridePriceResolver` 既有的商品層 fallback 語意（Phase 13 的**唯一取價真相**）
   一個字都沒有變。**絕不可**把這段邏輯搬進 `OverridePriceResolver`——那會同時改掉購物車的
   取價語意，讓「已經合法存在、變體自身無定價但吃商品層覆寫」的既有 cart item 失去價格。
3. 它依賴 `OverrideItem::get_variation_override()` 回傳 `?PriceOverride`（Phase 9 的委派法契約）。
   若有人把回傳型別改成 `?VariationOverride`，`->regular_price` / `->sale_price` 會直接讀不到、
   守門靜默失效——`OverrideItemTest` 的 `assertNotInstanceOf` 就是這條相依的鎖。
   ⚠️ 注意這裡**不能**再寫成「無覆寫時為 `null`」——那正是 TD-66 的錯誤前提：
   鍵存在就非 null，與「有沒有覆寫價」是兩件事。

回歸鎖（四條，缺任一條都留下缺口）：

| 測試 | 鎖住什麼 |
|---|---|
| `test_parent_level_override_never_rescues_priceless_variation_on_add_to_cart` | R-4 本體，三段：手工 POST（四道閘門完整跑）/ `add_to_cart()` 第 5 參數（short-circuit 路徑）/ 變體層覆寫對照組 |
| `test_empty_variation_entry_is_not_treated_as_variation_level_override` | 空條目判準，fixture 刻意帶 `inflated_count 7`，同時鎖政策 / 加購 / `build_products()` payload 三個消費端 |
| `test_real_variation_level_override_still_rescues_priceless_variation` | **正向對照**，防止守門被寫成一律拒絕的假綠燈 |
| `test_price_only_product_survives_signature_failure_and_falls_back_to_own_price` | S-4 守門欄位（fixture 必須「先正常存檔、再直接改 meta」——`handle_updated_props()` 會把 `_price` 覆寫成 `sale ?: regular`，用 CRUD 造不出這個狀態） |

⚠️ 第一條**抓不到** TD-66：它建的賣場**完全沒有 `variations` 條目**，只走到 `null` 分支。
新增回歸鎖時要問的是「這個 fixture 走到的是哪一條分支」，不是「有沒有測到這個功能」。
（TD-56 / TD-66 皆已解決，見 `specs/tech-debt.md` 已解決章節。）

⚠️ **已知且刻意不修**：所有變體都不可購買時，variable **父商品**在單品頁
（`/product/x/?profit_shop_id=…`）仍會顯示商品層覆寫價。不可利用——`WC_Cart::add_to_cart()`
（`class-wc-cart.php:1178-1188`）對 variable 父商品且 `variation_id` 不是它的變體時直接
`throw`（實測 `variation_id=0` 加購回件數 0）；而修法唯一的落點會**遞迴回正在執行的那組 filter**
（`get_available_variations()` → `variation_is_visible()` → `get_price()` view context）。
賣場前台不會連到那裡（卡片已是「暫無可購買規格」且不印 Modal）。見 `specs/tech-debt.md` TD-68。

##### 第四道閘門：販售期間（`AddToCartHook`）

`AddToCartHook` 新增第三道檢查（安全規則編號 1–5 的第 3 條）：賣場必須在販售期間內。
在此之前**後端完全沒有任何時間檢查**——`profit-shop.rule.md` 先前明寫「非販售期間 Modal 必須
整段不印」，理由正是「三道閘門不檢查販售起訖時間，接不住把 `disabled` 拔掉再送出」。這道閘門
把那個缺口補上，手工 POST 對已結束 / 尚未開賣的賣場不再注入 `profit_shop_id`。

三條紀律：

1. **定義必須與 template 的 `$is_shop_open` 共用同一份實作**——`ProfitShopFrontData::is_sale_window_open()`
   內部就是 `settings()['shopStatus'] === 'published'`。安全閘門依賴呈現層 helper 看似分層不純，
   但兩者若各寫一份，就會回到「畫面說關店、後端卻收單」；正規化語意（壞資料 → 0 = 未設定、
   四種 start/end 組合分支）也只有 `resolve_shop_status()` 這一份是對的。
2. **一律以伺服器時間判斷**（`time()`）。`startTime` / `endTime` 是 epoch 毫秒、與時區無關，
   不受站台 `timezone_string` 或瀏覽器時區影響（TD-44 講的是**顯示**時區，與此無關）。
   **永不讀任何客戶端時間**——這與 Phase 10 修掉 legacy「前端 `dayjs()` 重算 shopStatus」是同一條紀律。
3. **未設定開始 / 結束時間 ⇒ 不限制**（絕大多數賣場的 `settings` 裡根本沒有這兩個 key）。
   已實測：未設排程與販售期間內的賣場，加購行為與這道閘門上線前**逐字相同**。

**`is_sale_window_open()` 目前有四個消費端，其中三個是安全閘門**（2026-08-06 第 6 輪起）：

| 消費端 | 角色 |
|---|---|
| `AddToCartHook::inject_profit_shop_id()` | 第三道閘門（原始那一道） |
| `CartPriceOverrideHook::add_cart_item_data()` | **重驗**，涵蓋上游 short-circuit 的路徑（TD-59） |
| `SingleProductPriceHook::maybe_register_filters()` | 單品頁入口（TD-57） |
| `CartPriceOverrideHook::rescue_still_authorized()` | 只靠救援才有價的品項的授權回查（TD-61） |

⚠️ 這代表 **TD-63（壞資料 fail-open）的範圍已擴大**：那個 helper 原本只是呈現層取捨，
現在同時決定「後端收不收單」。改動 `resolve_shop_status()` 的正規化語意前必須同時評估這四處。

🔴 **「已在購物車內的品項受不受此閘門影響」分成兩條規則，差異點才是關鍵**：

| 品項 | 販售期間結束後 | 為什麼 |
|---|---|---|
| **商品自身有價格** | **不撤銷**（閘門 ③ 只驗簽章、不看時間） | 活動結束的那一秒靜默清空所有顧客的購物車比放行更糟，與「v1 簽章分支永久保留」同一條紀律；「加入購物車 = 價格已鎖定」是一般電商的正常行為。要收緊應該是產品決策 + 明確 notice，不是安全修補 |
| **只靠救援才有價**（`get_price('edit') === ''`） | **撤銷**（`rescue_still_authorized()`） | 三條理由：(1) 那個品項在賣場之外**根本不是商品**，它沒有價格，保留等於保留一個沒有定價依據的可成交項目；(2) 移除**不是靜默的**——WooCommerce 自己在 `class-wc-cart-session.php:184-214` 會加一則 error notice 告知顧客；(3) blast radius 極小，這類商品是 2026-08 才第一次變得可購買 |

⚠️ 這兩條看起來矛盾，**併成一條就會壞掉**：併成「都不撤銷」＝站長把賣場整個下架都停不掉那些
訂單（TD-61）；併成「都撤銷」＝翻掉 Phase 3-D 既有邊界、大量正常購物車在活動結束當下被清空。
`rescue_still_authorized()` 因此**只**掛在 `authorize_cart_item()` 走完前兩道早退之後的窄路徑上，
一般商品連查詢都不會發生（實測 0 queries）。

##### 簽章版本遷移 v2 → v3：綁定商品身分（2026-08-06 第 6 輪）

```
v1： cart-signature:v1|{shop}|{partner}|{price}
v2： cart-signature:v2|{shop}|{partner}|{price}|{regular}|{sale}
v3： cart-signature:v3|{shop}|{partner}|{price}|{regular}|{sale}|{product_id}|{variation_id}
```

**為什麼要綁**：簽章原本只保護「多少錢」，一組錯配的 meta 頂多把「本來就可購買的商品」標錯價；
但 2026-08 起簽章同時是**「不可購買的商品變不變成可購買」的唯一授權來源**。探針實測：
把 A 商品的整組 `_profit_*` meta 原封套到「不在任何賣場」的 B 商品上，`authorize_cart_item()`
回 `true`、B 的 `get_price()` 變成 `'27.00'`。

**兩個 id 都簽、不是只簽 `target_id`**：只簽 `variation_id ?: product_id` 在數學上已足以阻擋
搬移；額外簽 `product_id` 另外鎖住「同一個 target 不能被宣稱屬於另一個父商品」——第四道閘門
（`has_item()`）與 `rescue_still_authorized()` 的賣場回查用的都是**父商品 ID**，那個欄位若不在
簽章內，改掉它就能讓回查指向錯誤的 `OverrideItem`。成本是零。

**不新增任何 meta**：`product_id` / `variation_id` 是 WooCommerce cart item 的原生欄位，
複製一份成 `_profit_*` meta 只會多一個可以互相矛盾的來源。

**v3 另做一次身分交叉比對**：`verify_item()` 比對「cart 陣列欄位」與
「`cart_contents[$key]['data']` 這個實際被定價的物件」，脫鉤即 fail-closed。正常路徑必然一致
（WC 自己就是用 `variation_id ?: product_id` 建出那個物件），但授權現在決定可購買性，
不該把一致性當成外部保證。

過渡期行為矩陣：

| cart item | 版本 | regular/sale meta | 身分比對 | 結果 |
|---|---|---|---|---|
| Phase 13 之前加入 | v1 | 無 | **不做** | ✅ 只套 price；注入的 regular/sale 一律忽略 |
| Phase 13 ～ 本輪之間 | v2 | 有 | **不做** | ✅ 全套用。⚠️ 商品身分不受保護——TD-58 對 v2 **仍然成立** |
| v2 簽章被搬到別的商品 | v2 | 有 | 不做 | ⚠️ **仍會通過**。窄化在「本輪之前建立的既有 cart item」，且無 HTTP 可達寫入點 |
| 本輪之後新加購 | v3 | 有 | ✅ | ✅ 全套用 |
| v3 被搬到別的商品 / id 被改 | v3 | 有 | ✅ | ❌ fail-closed → fallback 原價 |
| 舊簽章硬改成 `v3.` 前綴 | 偽 v3 | 任意 | ✅ | ❌ domain prefix 內含版本字串，hash 天生不互通 |
| 一台車混合 v1 + v2 + v3 | 混合 | 混合 | 逐筆 | ✅ 逐品項獨立判版 |

🔴 **為什麼不順手把 v2 也加上身分比對**：v2 payload 從未包含那兩個欄位，能拿來比對的兩個來源
（cart 陣列、cart 物件）**都在攻擊者的同一個控制面內**，比對零安全增益；反而會把「陣列欄位與
物件因任何第三方外掛而暫時不一致」的既有購物車誤殺。這與 v1 分支拒絕碰 regular/sale 是**同一條
推理**：舊版本只驗它自己簽過的欄位。

🔴 **`LEGACY_PAYLOAD_VERSIONS` 只准增加、永遠不准減少**（TD-35 同一條理由：持久化購物車無過期
機制）。版本判斷一律用 `payload_version()`，**絕不可**用「某個 meta 在不在」——那是已知的繞過
手法（保留合法舊簽章 + 注入偽造欄位 → 新分支驗失敗 → 落回舊分支 → 通過 → 套用一組從未被簽章
保護的值），Phase 13 已經修過一次同型漏洞。

##### 🔴 不變量：`OverridePriceFilters` 永遠不影響任何一筆 cart item 的價格

cart item 的價格由 `CartPriceOverrideHook` + HMAC 簽章**獨佔**決定。

這條不變量取代了原本那段**錯誤的**安全論證（「購物車 / 結帳**請求**不會走到這兩條路徑」）——
前提是「請求類型」，但**購物車與商品頁在同一個 request 裡並存**：`SingleProductPriceHook` 在
`template_redirect` prio 20 掛上 filter 後**整個 request 都不解除**，佈景主題 / 外掛
（mini-cart fragments、免運進度條、幣別切換器）只要在渲染期再呼叫一次 `calculate_totals()`，
一筆**完全沒有 `_profit_*` meta、沒有簽章**的 cart item 就會被套上覆寫價（探針實測
1000 → 27），而 `apply_override()` 根本沒碰它（`isset(META_SHOP_ID)` 就 `continue`）⇒ 整條
HMAC 保護被繞過；`woocommerce_after_calculate_totals` prio 1000 的 `set_session()` 還會把被
污染的 `line_total` 寫進 session。（TD-62；與 TD-20 / TD-54 是同一種失效模式。）

**執行點**：`resolve_for_product()` 開頭的 `is_cart_item_object()`，判準是**物件身分**（`===`）
而非 product ID——`WC_Product_Factory::get_product()`（`class-wc-product-factory.php`）每次
`return new $classname( ... )`、**不做實例快取** ⇒ 購物車裡的實例與單品頁 /
`get_available_variations()` 拿到的必然是不同物件，零誤判：同一個商品在單品頁照樣套覆寫價，
只有「購物車裡的那一份」被排除。**用 product ID 比對會把單品頁一起排掉、直接廢掉 Phase 9。**

註冊時機仍然照舊收斂，但那是**縱深防禦的第二層、不是唯一一層**。
被否決的兩個替代方案：「`before_calculate_totals` 前解除、after 重掛」只擋 `calculate_totals()`
一條路（mini-cart 走 `wc_get_price_to_display()`、Store API schema 走 `get_price()`，都不在裡面），
且會讓 `has_filter()` 斷言變得依賴呼叫時機；「每次價格渲染 arm/disarm」則沒有可掛的點。

##### F-1：變體價格 transient 必須混入覆寫值本身

`filter_variation_prices_hash()` 除了 `shop_id` 還要併入覆寫內容的指紋
（`md5( wp_json_encode( $item->to_array() ) )`）。理由：WC 把變體價格陣列存在 transient，
而站長改的是**賣場** post 的 `_profit_shop_items`，**不會**觸發 WooCommerce 對**商品** transient
的任何清除。Phase 9 起就有這個缺口，但當時覆寫只是「平移既有區間」；本次改動後覆寫決定
**變體存不存在**，後果從「數字不對」升級成**幽靈商品**（實測：清掉覆寫後單品頁仍顯示已不存在的
價格區間，手動 DELETE transient 才恢復；加上指紋後改價即時生效、無需清快取）。

##### F-2：`variable-subscription` 不可用嚴格字串比較

`'variable' === $type` 這種寫法讓 `variable-subscription` 整段落在守門之外，實測症狀是：
卡片價格區塊印成**空的 `<p>`**、`data-ps-disabled="0"` 可點、Modal 開得起來卻**沒有任何屬性選項、
沒有價格，而加入購物車按鈕是啟用的**，按下去整頁 reload、什麼都沒發生、**沒有任何錯誤訊息**。

單一真相是 `ProfitShopFrontView::VARIABLE_TYPES` / `is_variable()`（partial 只拿得到 payload
字串時用它）；有 `WC_Product` 物件的地方（`ProfitShopFrontData::build_product()`）用 WC 自己的
`$product->is_type( ProfitShopFrontView::VARIABLE_TYPES )`。
`build_variations()` 的 `instanceof \WC_Product_Variable` 守門**不需要改**——已查證
`WC_Product_Variable_Subscription extends WC_Product_Variable`。

#### DRY：URL prefix 統一用 `RewriteRules::SHOP_REWRITE_PREFIX`

凡是要組 `/profit-shop/{slug}/` URL 的地方（`ProfitShopRenderer` 的 canonical、`ProfitShopShortcode` 的提示文案等），**必須**從 `RewriteRules::SHOP_REWRITE_PREFIX` 取，不要 hardcode 字串 `'profit-shop'`，避免日後改前綴時遺漏。

### 12.9 商品選擇器 ProductPickerModal（Phase 7）

`#/profit-shop/edit/{id}` 的商品選擇器從單選 `Select` 改為「按鈕開啟 `SimpleModal` + 完整篩選器 + 分頁列表 + 跨頁勾選」，解決 10 萬商品規模下無法篩選的問題。UI 元件架構、`ProductTableBody` 共用拆法、`SimpleModal` 踩坑細節見 `react.rule.md` §6.4 / §6.5；本節只記錄與 Profit Shop domain 契約相關的部分。

**三層錯誤處理分工**（`specs/plan/2026-07-29-profit-shop-product-selector-plan.md` 錯誤處理策略）：

1. **前端守門**（防止無效請求出門）：批次上限 100 disabled、dedupe、特價 > 原價的 Form rule、`Number.isFinite` 過濾非數字 id
2. **後端 Domain 驗證（唯一真相）**：`ItemValidator`（商品 / 變體存在性）+ `PriceOverride` VO invariant（非負十進位、sale ≤ regular）。**不因前端已擋就放寬**——繞過前端直打 API 一樣會被 400 擋下
3. **降級不阻斷**（顯示層失敗不影響業務）：補齊查詢失敗 / 商品已刪除 → 只影響顯示（`useShopItemProducts`，見 `react.rule.md` §6.5），編輯與儲存路徑完全不受影響

**100 / 500 上限是前端專屬限制，後端沒有對應驗證**：單次批次上限 100 筆、賣場商品總數軟上限 500 筆，都只在前端擋（`ProductPickerModal` disabled + `notification.warning`）。後端 `ItemValidator` / `PriceOverride` 沒有數量上限檢查——這是刻意的（Q1 範圍邊界：不動 Domain 層），代表**繞過前端直接呼叫 API 可以送超過 500 筆的 items 陣列而不被拒絕**。若未來要把上限收斂成硬性業務規則，需要在 `ItemValidator` 或新的 UseCase 層補驗證，屬於後續產品決策（見 `specs/tech-debt.md`）。

**Modal 只做新增，不做移除**：移除既有賣場商品仍在下方的商品列表操作，`ProductPickerModal` 內沒有移除入口（Q4 範圍邊界）。

**變體不可選**：`variations` 契約結構保留（見 §7「OverrideItem items 契約」），但 Modal 內的變體子列 checkbox 維持 disabled，UI 不開放選變體（Q3 範圍邊界）。

> Phase 9 補充：上面說的是 **Modal 內選擇父商品時不能順便勾選變體**，這條邊界維持不變。
> 變體覆寫本身**另有入口**——已加入賣場的父商品，若是 `variable` / `variable-subscription`，
> 可在 `ItemsEditor` 主表格的商品名稱欄展開設定每個變體的價格與灌水購買人數，見 §7「變體層
> 覆寫」與 `react.rule.md` §6.5。兩者是不同層級的操作：Modal 負責「把哪些商品加進賣場」，
> 展開列負責「已加入的可變商品要不要逐變體覆寫」。
