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

**價格語意必須與 `CartPriceOverrideHook::resolve_effective_price()` 逐字一致**：變體只要有
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

**共用 template 的 `<main>` / `<div>` 旗標**：`templates/profit-shop-front.php` 同時服務
`ProfitShopRenderer`（獨立頁面）與 `ProfitShopShortcode`（嵌入宿主頁面），靠
`$is_shortcode_context` 決定外層標籤。**未定義時預設 `false`**，`ProfitShopRenderer`
路徑的輸出因此一個字元都沒變。理由是 HTML spec 規定一份文件內只能有一個非 hidden 的
`<main>`，shortcode 情境宿主頁面已經有自己的 `<main>`，巢狀是無效 HTML。
`ProfitShopShortcodeTest` 有兩條測試分別鎖死兩個方向
（`test_output_uses_div_wrapper_and_never_main` 與
`test_template_defaults_to_main_wrapper_without_flag`），避免日後有人「順手統一」成單一標籤。

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

#### Phase 10：前台 UI 照搬 legacy（混合式 PHP + React）

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

#### DRY：URL prefix 統一用 `RewriteRules::SHOP_REWRITE_PREFIX`

凡是要組 `/profit-shop/{slug}/` URL 的地方（CartItemMetaDisplay、未來的 ProfitShopRenderer 內部組 canonical 等），**必須**從 `RewriteRules::SHOP_REWRITE_PREFIX` 取，不要 hardcode 字串 `'profit-shop'`，避免日後改前綴時遺漏。

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
