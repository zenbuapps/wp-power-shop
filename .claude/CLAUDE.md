# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Version:** 3.0.12
> **Project Type:** WordPress Plugin (WooCommerce extension, React SPA admin UI)

## Project Summary

**Power Shop** replaces WooCommerce admin screens with a React/TypeScript SPA built on Refine + Ant Design, served at `admin.php?page=power-shop`.

- **PHP Namespace:** `J7\PowerShop` | **Text Domain:** `power_shop`
- **React Mount:** `#power_shop` | **REST Namespace:** `power-shop` → `/wp-json/power-shop/`
- **Entry:** `plugin.php` → `Bootstrap` → `Admin\Entry` + `Domains\Loader`
- **Frontend:** `js/src/main.tsx` → `App1.tsx` (Refine + HashRouter)

## Build / Dev / Lint Commands

```bash
# Frontend
pnpm dev              # Vite dev server (HMR, port 5178)
pnpm build            # Production build → js/dist/
pnpm lint             # ESLint
npx tsc --noEmit      # TypeScript type-check

# PHP
composer lint          # PHPCS (WordPress-Core/Docs/Extra, config: phpcs.xml)
vendor/bin/phpstan analyse inc --memory-limit=1G   # PHPStan level 9 (config: phpstan.neon)

# Release
pnpm sync:version     # Ensure package.json == plugin.php version
pnpm release:patch    # Bump + build + GitHub Release + ZIP
pnpm release:minor
pnpm release:major
```

## Common Pitfalls

1. **Don't** add logic in `plugin.php` — only `Plugin` class + `Plugin::instance()` call
2. **Don't** read `window.power_shop_data` directly — use `useEnv()` or `env` from `@/utils`
3. **Don't** use `fetch`/`axios` for API — use Refine hooks with explicit `dataProviderName`
4. **Don't** forget `memo()` on page components — Refine re-renders can be expensive
5. **Don't** place business logic in route components — extract to hooks
6. **Always** specify `dataProviderName` on Refine hooks for non-default resources
7. **Always** register new REST API classes in `Domains\Loader`
8. **Always** use `WP::sanitize_text_field_deep()` on all REST request params
9. **Don't** remove the Enqueue Guard (`General::in_url(['page=power-shop'])`)
10. **Don't** 在 ProfitShop / ProfitPartner / ProfitMigration / ProfitSettings 用 `useTable` / `useForm`（後端 `{code, data}` 與 antd-toolkit dataProvider shape 不相容）—— 用 `useCustom` / `useCustomMutation` 並明確指定 `dataProviderName: 'power-shop'`
11. **Partner self-service portal** 是**獨立 SPA bundle**（mount 點 `#profit_partner_portal`，URL `/profit-report/{slug}/`），與 admin SPA 完全隔離。Auth 走 cookie + sessionStorage metadata（永不存 token）；不用 Refine / dataProvider，直接 axios + `useCustom`/`useCustomMutation`；不打包 admin（grep `App1` / `Refine` 0 命中為驗收）。

## Profit Shop Domain（v1 開發中）

> 全新「分潤賣場」系統，與舊版 legacy 一頁商店共存。
> 設計文件：`specs/2026-05-06-profit-shop-design.md`
> 架構與規範：`.claude/rules/profit-shop.rule.md`

**目前狀態**：Phase 4-A + 4-B 完工 → 下一步 Phase 4-C（賣場前台 + AddToCart 整合）

### Phase 1（Domain 層）已完工（commits `cbd0522` / `8359918` / `9ecdb77`）

| 層 | 內容 | 路徑 |
|----|------|------|
| ValueObject | PriceOverride / ProfitRate / PartnerSlug / InflatedCount / ShopMode | `inc/classes/Domains/ProfitShop/Domain/ValueObject/` |
| Entity | OverrideItem / ProfitShop / SettlementRecord | `inc/classes/Domains/ProfitShop/Domain/Entity/` |
| Service | PriceCalculator（fallback chain）/ ProfitCalculator / RoundingStrategy interface | `inc/classes/Domains/ProfitShop/Domain/Service/` |
| Repository Interface | ProfitShopRepository / PartnerRepository / SettlementRepository | `inc/classes/Domains/ProfitShop/Domain/Repository/` |
| DTO | ProductSnapshot / PartnerSnapshot / FilterCriteria | `inc/classes/Domains/ProfitShop/Domain/Snapshot\|Criteria/` |
| Exception | 8 個 final class extends \DomainException | `inc/classes/Domains/ProfitShop/Domain/Exception/` |

### Phase 2（Infrastructure 層 + CPT）已完工（commits `fb216c7` / `2004b00`）

| 層 | 內容 | 路徑 |
|----|------|------|
| WordPress Registrar | CptRegistrar（powershop CPT，`show_in_rest=false`）/ TaxonomyRegistrar（profit_partner，termmeta 顯式 register_meta）/ RewriteRules（`/profit-report/{slug}/`） | `inc/classes/Domains/ProfitShop/Infrastructure/WordPress/` |
| Persistence | CptProfitShopRepository（JSON items 序列化、trashed find→null）/ PartnerTermRepository（`wp_hash_password`、`is_in_use` 反查）/ OrderItemSettlementRepository（`wc_*_order_item_meta`，HPOS 雙路徑） | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/` |
| Sub-Loader | ProfitShop\Loader（內部 sub-loader，註冊到 `Domains\Loader`） | `inc/classes/Domains/ProfitShop/Loader.php` |
| Domain 補洞 | PersistenceFailure（替代 4 處 RuntimeException）+ status invariant 修正 | `inc/classes/Domains/ProfitShop/Domain/Exception/` |
| 測試 | 7 個 IT 檔（28 + 4 邊界測試），`tests/Integration/Infrastructure/` | `tests/Integration/Infrastructure/` |

### Phase 3-A（Domain 缺口補完 + Application 骨架）已完工（commit `c1f50ea`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain Exception | 9 個新 final class（PartnerNotFound / ProductNotFound / InvalidVariation / InvalidCredentials / TooManyAttempts / Forbidden / SlugConflictException / RateLimitExceeded / LegacyShopNotImportable） | `inc/classes/Domains/ProfitShop/Domain/Exception/` |
| Domain Entity 修正 | ProfitShop `$status` protected + `status()` getter + `change_status()` mutator（VALID_STATUSES invariant） | `inc/classes/Domains/ProfitShop/Domain/Entity/ProfitShop.php` |
| Domain ValueObject | SlugConflict（DDD 純度修正：避免 Exception 反向依賴 Application） | `inc/classes/Domains/ProfitShop/Domain/ValueObject/SlugConflict.php` |
| Repository Interface | PartnerRepository 補 `delete()` + 實作 | `inc/classes/Domains/ProfitShop/Domain/Repository/` + Persistence |
| Application DTO | 12 個 immutable DTO（PHP 8.1 readonly，from_array / to_array round-trip） | `inc/classes/Domains/ProfitShop/Application/DTO/` |
| Application Service Stub | 7 個 Service stub（method 拋 BadMethodCallException，留 3-B/C 實裝） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| 測試 | 41 條新 Unit Test（NewExceptionsTest / ProfitShopChangeStatusTest / DtoRoundTripTest / PartnerRepositoryDeleteContractTest） | `tests/Unit/` |

### Phase 3-B（Admin CRUD UseCase + V2Api）已完工（commit `b549796`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Application UseCase | 18 個 UseCase（8 Shop + 5 Partner + 2 Migration + 3 Settings） | `inc/classes/Domains/ProfitShop/Application/UseCase/` |
| Application Port | 7 個 interface（ProductLookup / SlugConflictLookup / LegacyShopRepository / RewriteRulesFlusher / SettingsRepository / ItemValidator / SlugConflictDetector） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Application Service | 4 個 Service 實裝（ItemValidator / SlugConflictDetector / SettingsRepository / ProductSnapshotProvider） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Infrastructure Adapter | WpProductLookup / WpSlugConflictLookup（spec §6.11 五類衝突 SQL）/ RewriteRulesFlusher / LegacyOnePageShopRepository | `inc/classes/Domains/ProfitShop/Infrastructure/(Persistence\|WordPress)/` |
| Presentation | ExceptionMapper（spec §7 13 種 Domain Exception → HTTP）+ V2Api（17 個 admin endpoint，`namespace=power-shop`，partner POST/PUT/DELETE 升 `manage_options`） | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/` |
| Domain 補洞 | InvalidShopMode Exception | `inc/classes/Domains/ProfitShop/Domain/Exception/InvalidShopMode.php` |
| 測試 | 99 個新測試（69 UseCase Unit + 10 Service Unit + 30 IT） | `tests/Unit/Application/` + `tests/Integration/Infrastructure/Rest/` |

### Phase 3-C（Partner Auth + Reports，安全核心）已完工（commits `e98edbf` / `cb62bdd` / `f47a531`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Application Port | 4 個 interface（TransientStore / Clock / EmailNotifier / SettlementSummaryProvider） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Application Service | PartnerAuthService（rate-limit short-circuit + 統一 InvalidCredentials）/ PartnerTokenStore（sha256 hash 儲存，**不存明文** + dual safety expires_at）/ LoginRateLimiter（5 次失敗鎖定 + email warn-and-swallow） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Auth UseCase | LoginPartnerUseCase / LogoutPartnerUseCase（idempotent）/ GetCurrentPartnerUseCase / RegeneratePartnerPasswordUseCase（admin-only，wp_generate_password 12 字元） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Auth/` |
| Report UseCase | GetPartnerKpiUseCase / GetPartnerTrendUseCase / ListPartnerSettlementsUseCase（**partner_term_id 鎖死於 caller，禁止從 query string 取**） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Report/` |
| Infrastructure Adapter | WpTransientStore / SystemClock / WpAdminEmailNotifier / WpSettlementSummaryProvider（Phase 3-C placeholder 回 0/空陣列，3-D 將補真實 SQL） | `inc/classes/Domains/ProfitShop/Infrastructure/(WordPress\|Persistence)/` |
| Presentation | V2Api 新增 7 個 endpoint（regenerate-password + partner-auth ×3 + partner-reports ×3）+ `partner_token_permission`（從 X-Partner-Token / Authorization Bearer / Cookie 取）+ Set-Cookie HttpOnly/Secure/SameSite=Lax/Path=/wp-json/power-shop/ | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php` |
| 測試 | 73 個 test method / 194 assertion（紅燈→綠燈→二輪 PASS，Unit 265/486 全綠、Phase 3-C IT 46/129 全綠） | `tests/Unit/Application/` + `tests/Integration/Infrastructure/Rest/` + `tests/Support/`（FixedClock / InMemoryTransientStore / SpyEmailNotifier / InMemorySettlementSummaryProvider） |

**Domain / Application 約定（4-layer DDD）**：
- Domain：純 PHP，不依賴 WP / WC，例外統一 `extends \DomainException`
- Application：UseCase + DTO（PHP 8.1 readonly）+ Port interface（DIP 注入）
- Infrastructure：Persistence + WordPress + Rest，是唯一可呼叫 WP / WC API 的層
- Presentation：ExceptionMapper（Domain Exception → HTTP）+ V2Api（單一 ApiBase，namespace `power-shop`）
- Test：`tests/Unit/**` 純 PHP；`tests/Integration/**` 啟 WP；`tests/Support/**` fakes 與 production interface 同步 implements

### Phase 3-D（Settlements 真實聚合 + AddToCart Hook + 三輪安全強化）已完工（commits `78a050f` / `64e4c04` / `4e1c780` / `3b36a2a`）

| Batch | Commit | 範圍 | 測試 |
|-------|--------|------|------|
| Batch 1 | `78a050f` | T-1 PartnerRepo `get_password_changed_at()` + T-7 CartPriceSignatureService（HMAC-SHA256 + `cart-signature:v1` domain prefix + `v1.` version prefix + hash_equals） | 16 method（CartPriceSignatureServiceTest）+ 4 method（PartnerRepositoryGetPasswordChangedAtContractTest）+ 4 method（PartnerTermRepositoryGetPasswordChangedAtTest IT） |
| Batch 2 | `64e4c04` | T-2 PartnerTokenStore 撤銷比對 + 4-interface DI（`SaltProviderInterface` 加入）+ `hash_hmac('sha256', $token, wp_salt('auth'))` 升級 + T-5 WpSettlementSummaryProvider 真實 SQL（94 行 → 545 行，5 道安全防線：prepare / IDOR / HPOS 雙路徑 / bcadd 精度 / pagination cap） | 9 method（PartnerTokenStorePasswordRotationTest）+ 端到端 IT（PartnerPasswordRotationRevokesTokenTest）+ 21 method（WpSettlementSummaryProviderRealAggregationTest） |
| Batch 3 | `4e1c780` | T-3 LoginRateLimiter +per-IP 雙維度（換 slug 攻擊防禦，IP sha256 hash + 不解析 X-Forwarded-For）+ unknown slug `record_ip_only_failure` + T-8 CartPriceOverrideHook（**新層！`Infrastructure/WooCommerce/`**）：`add_cart_item_data` / `get_cart_item_from_session` / `before_calculate_totals` priority 999 + 4 筆 `_profit_*` meta + draft shop fallback + `wc_format_decimal($price, '')` normalize | 14 method（LoginRateLimiterIpDimensionTest）+ 14 method（CartPriceOverrideHookTest IT，含 draft shop test） |
| Batch 4 | `3b36a2a` | T-4 V2Api 收尾整合：regenerate-password 加 admin nonce（`X-WP-Nonce`）+ `Cache-Control: no-store, no-cache, must-revalidate` + extract_partner_token 三路徑優先級 PHPDoc 化；三輪 reviewer 11 條安全強化：LOW-T2-1（PartnerTokenStore `partner-token:v1\|` domain prefix）/ LOW-T2-2（verify try/catch fail-closed + log injection 過濾）/ LOW-T5-1（V2Api page max 10000 cap）/ LOW-T5-2（format_datetime MAX_TIMESTAMP cap）/ M-1（`power_shop_partner_login_client_ip` filter hook）/ M-2（LoginRateLimiter IP hash 升 hash_hmac + `SaltProviderInterface` DI + KEY_PREFIX_IP v2）/ MAJOR-1（`is_hpos_enabled` 共用一次計算）/ MAJOR-2（format_money 非數字 fail-fast）/ MAJOR-3（trend INNER JOIN 1:1 假設 docblock） | 全部既有測試 ctor 補注入 `FixedSaltProvider` regression 防護 |

### Phase 3-E（validate-slug + ExceptionMapper 解封 + OpenAPI 同步）已完工（commit `0660fc7`）

| 項 | 內容 | 路徑 |
|----|------|------|
| T-9 validate-slug | `ValidateSlugUseCase`（格式守門：空 / >60 字元 / `^[a-z0-9_-]+$`，違規拋 `InvalidPartnerSlug`）+ `SlugValidationOutput` readonly DTO（`available` / `conflicts` / `is_available()`）+ V2Api `GET /profit-shops/validate-slug?slug=xxx`（permission `null`） | `Application/UseCase/Shop/ValidateSlugUseCase.php` + `Application/DTO/SlugValidationOutput.php` |
| T-10 ExceptionMapper 解封 | `ExceptionMapper::should_mask()` private static helper + `power_shop_exception_mapper_mask` filter（test seam，default = `!WP_DEBUG`），測試 try/finally 復原；13 種 Domain Exception 對映邏輯與 V2Api callsites 完全未動 | `Infrastructure/Rest/ExceptionMapper.php` |
| T-11 OpenAPI 同步 | `specs/api/api.yml` 從 1996 行擴增至 2940+ 行：26 個 Profit Shop endpoint（Phase 3-A/B/C/E 全收）+ 21 個 component schemas + 3 個 securitySchemes（`PartnerToken` / `PartnerBearer` / `PartnerCookie`）+ partner endpoint 裸 payload 標註 + partner-reports 三 endpoint description 鎖死「禁從 query string 讀 partner_term_id」 | `specs/api/api.yml` |
| 測試 | 9 method（ValidateSlugUseCaseTest，FakeSlugConflictDetector）+ 紅燈 IT 7 method（待 wp-env 跑） | `tests/Unit/Application/UseCase/Shop/` |

### Phase 4-A（商家後台 React CRUD UI）已完工（commits `1c1e3b9` / `883edc9` / `6610548`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-A1 | `1c1e3b9` | ProfitShop CRUD（List / Edit / Create）+ SlugInput 即時驗證（debounce + useCustom enabled 切換）+ profitShopExceptionMapper（12 種 code → 友善繁中訊息）+ Q1 落坑紀錄（後端 `{code, data}` 不相容 antd-toolkit dataProvider，改 useCustom / useCustomMutation） | 12 檔（+1175 行） |
| 4-A2 | `883edc9` | Partner CRUD（List / Edit / Create + PartnerForm + PartnerSelector）+ RegeneratePasswordButton ⚠ HIGH-RISK（明文僅 useState、5 min auto-clear、Modal closable=false / maskClosable=false / keyboard=false、navigator.clipboard 無 fallback）+ ItemsEditor（OverrideItem 列表編輯，dedupe）+ ShopActionsButtons（publish / unpublish / duplicate）+ ProfitShopForm 升級（partner_term_id → PartnerSelector、items → ItemsEditor）；reviewer + security 雙審 PASS | 18 檔（+1490 行） |
| 4-A3 | `6610548` | ProfitMigration（List + ImportModal ⚠ HIGH-RISK 4 道閘門）+ ProfitSettings（單頁 + ResetButton ⚠ HIGH-RISK 自包 mutation）+ OneShop 改造為雙入口（新版 / 舊版 wp-admin）+ 三輪 reviewer 累積 9 條順手清單清完（含 LOW-1 統一補 `dataProviderName: 'power-shop'` 30+ 處、LOW-2 Input.Password 預設 mask + 5 min timer 補 setPwdVisible(false) bugfix）；reviewer + security 雙審 PASS | 18 檔（+945 行） |

**Phase 4-A 完工要點**：
- 4 個 resource：ProfitShop / ProfitPartner / ProfitMigration / ProfitSettings（全部掛在 marketing parent）
- 共用工具：`utils/profitShopExceptionMapper.ts`（12 種 ErrorCode → 友善繁中訊息）
- HIGH-RISK 元件 3 個：RegeneratePasswordButton（明文密碼）/ ImportModal（不可逆 import）/ ResetButton（不可逆 reset）
- 防 refetch 覆寫 pattern：`filledIdRef` + `queryOptions.refetchOnWindowFocus: false`（4-A1 起沿用）
- ProfitSettings：`filledRef` + `lastFilledSettingsRef` 雙保險（首次填表 + reset 後 server 變動自動重填）
- npx tsc --noEmit baseline 不變，pnpm lint 全綠，既有 SPA（Product / Order / Users）零 regression

### Phase 4-B（Partner self-service portal，獨立 SPA）已完工（commits `bc3ea5c` / `7e40969` / `23e76e9`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-B1 | `bc3ea5c` | Partner portal Skeleton：PartnerPortalRenderer（PHP `template_redirect` priority 9，攔 query var `profit_partner_report`，partner term 不存在 → 404，存在 → 輸出**獨立** HTML 骨架，**不**走 `get_header` / `wp_head` / `get_footer`）+ Vite multi-entry（v4wp `input: string[]` 同時打 admin 與 partner-portal 兩個 bundle）+ Auth 系統（axios `withCredentials` + 401 interceptor + sessionStorage 只存 metadata 不存 token + AuthContext status 三態 + 跨 partner 雙保險：`status='loading'` masking + partner 物件遮蔽 null）+ Login 頁（rate-limit `Retry-After` parse + cooldown 倒數 + `partnerExceptionMapper` fallback 到 admin `profitShopExceptionMapper`） | 15 檔（+990） |
| 4-B2 | `7e40969` | Partner portal Dashboard 殼：DateRangeFilter（5 preset：本月 / 上月 / 近 7 天 / 近 30 天 / 自訂，dayjs + Segmented + RangePicker）+ KPI 4 卡（總銷售 / 待結算 / 已結算 / 已退款，mobile stack）+ LoadingScreen + ErrorBoundary（**partner-portal 唯一 class component**）+ `api/reports.ts`（`fetchKpi`，**partner_term_id 永不出現在 query**，後端 token 鎖死）+ `useKpi` typed `useQuery<TKpiOutput, AxiosError>` | 8 檔（+571） |
| 4-B3 | `23e76e9` | Partner portal Trend + Settlements + 收尾：TrendChart（echarts 折線圖，**callback ref pattern** 取代 `useRef` + `useEffect` 解決 reviewer C-1 race condition）+ SettlementsTable（desktop / mobile 響應式 + status filter + `formatAmount`）+ `useTrend` / `useSettlements`（typed `useQuery`）+ `apiClient` baseURL 內建（`readPartnerEnv` 純函式，免每次傳 `apiUrl`）+ `useAuth.ts` 拆兩層 re-export 簡化（直接 import `AuthContext`）+ Vite manifest fallback（PartnerPortalRenderer `render_maintenance` 503 + `nocache_headers`，防 manifest 缺 entry 看到空白頁）；reviewer 二輪 PASS（C-1 echarts init / m-1 echarts chunk dedup 自承不準 / m-2 / n-3 全清） | 14 檔（+~840） |

**Phase 4-B 完工要點**：
- 完整 partner self-service portal：`/profit-report/{slug}/` URL + 獨立 SPA bundle（mount 點 `#profit_partner_portal`，與 admin 完全隔離）
- HashRouter（與 admin 一致，避免 BrowserRouter 與 RewriteRules 衝突）+ Login + Dashboard（KPI / Trend / Settlements 三 widget）
- HTTP-only cookie auth + sessionStorage metadata（**永不存 token**，XSS 防護）
- 跨 partner 雙保險（status `'loading'` 而非 `'authenticated'` + partner 物件 mismatch 遮蔽 null）
- 401 interceptor 在 React tree 之外（`window.location.hash` 跳轉，`isLoginPage` 防迴圈）
- rate-limit 倒數 UX（`Retry-After` header parse + 防呆 NaN / negative）
- echarts callback ref pattern（reviewer C-1 教訓：dispose race + ref attach timing）
- Vite manifest fallback maintenance 503（reviewer L-3 修補）
- partner bundle 隔離驗收：grep `App1` / `Refine` / `@refinedev` / `dataProviderName` / `wc-rest` / `wp-rest` / `X-WP-Nonce` 全部 0 命中
- 不引入新 npm 套件（複用 admin 既有 antd / react-query / axios / antd-toolkit `simpleDecrypt` / dayjs / echarts）
- 不動 admin SPA 任何檔案 / 6 個 admin dataProvider 配置
- pnpm build / lint / tsc baseline 維持，composer lint 全綠

### Phase 4-C 預告（待辦）

**下一階段：分潤賣場前台 + AddToCart 整合**

優先順序：
1. **分潤賣場前台**：`/profit-shop/{slug}/` 路由顯示 partner 專屬商品列表（含 PriceCalculator fallback chain）
2. **AddToCart 前端整合**：cart_item_data 注入 4 筆 `_profit_*` meta + signature 由後端簽發後夾帶（後端 CartPriceOverrideHook 已就緒）
3. **'power-shop' dataProvider thin wrapper**（Phase 4-A1 自決踩坑紀錄遺留）：包 `{code, data}` 讓未來頁面能用 useTable / useForm
4. **賣場前台技術選型 brainstorming**（PHP page template vs React）+ Cart UI 顯示分潤標記
5. **Partner 自助修密碼**（需後端新 endpoint，Phase 4-B 不做）

### Reviewer 累積的「未來補強建議」（非 blocking）

由 Phase 3-D Batch 1-3 的 reviewer 順手清單彙整（已於 Batch 4 解決部分）：

- [ ] **security L-1**：全域 mass-spray rate-limit（1000/hour 跨任意 slug + IP）
- [ ] **security M-4**：`error_log` 路徑安全提示寫入 README / 設計文件
- [ ] **T-3 補測**：`PartnerAuthServiceTest` 加 unknown slug + IP-only record 端到端測試
- [ ] **T-3 文件**：`specs/2026-05-06-profit-shop-design.md` §6 加 IP 偵測信任邊界章節 + `power_shop_partner_login_client_ip` filter hook 用法範例
- [ ] **T-8 perf**：`reset_to_original_price` 的 N+1 query micro-cache
- [ ] **T-8 邊界**：`before_calculate_totals` priority 999 對 `PHP_INT_MAX` 的其他 plugin 防護評估
- [ ] **rate-limit 5/15min 改 3 次**（reviewer L-3，產品決策層）
- [ ] **token 三條讀取路徑收斂**（reviewer M-5，目前已 PHPDoc 化但未刪路徑）

## Specs

Complete specs live in `specs/` using AIBDD Discovery multi-view architecture. See `specs/README.md` for the full index.

| View | Path | Count |
|------|------|-------|
| Activity | `specs/activities/*.activity` | 4 |
| Feature | `specs/features/**/*.feature` | 20 (12 command + 8 query) |
| UI | `specs/ui/*.md` | 9 pages |
| API | `specs/api/api.yml` | OpenAPI 3.0 |
| Entity | `specs/entity/erm.dbml` | 9 tables |
