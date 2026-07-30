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
pnpm lint:ts          # ESLint。⚠ 實際指令含 --fix，會改檔案；唯讀檢查改用 npx eslint "js/src/**/*.{ts,tsx}"（不加 --fix）
npx tsc --noEmit      # TypeScript type-check

# PHP
composer lint          # PHPCS 唯讀檢查 (WordPress-Core/Docs/Extra, config: phpcs.xml)
pnpm lint:php          # ⚠ 實際指令含 phpcbf（自動修正，會改檔案）+ phpcs + phpstan；唯讀檢查改用上面的 composer lint + 下面的 phpstan analyse
vendor/bin/phpstan analyse inc --memory-limit=1G   # PHPStan level 9 (config: phpstan.neon)

# Release
pnpm sync:version     # Ensure package.json == plugin.php version
pnpm release:patch    # Bump + build + GitHub Release + ZIP
pnpm release:minor
pnpm release:major
```

> **注意**：`package.json` 沒有 `lint` 這個腳本，只有 `lint:ts` / `lint:php`（見 `package.json:12-13`）。照舊文件寫的 `pnpm lint` 執行會直接失敗（`ERR_PNPM_NO_SCRIPT`）。

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
12. **賣場前台**（`/profit-shop/{slug}/`，customer-facing）走 **PHP page template + theme 整合**（**非 React**）。原因：customer-facing + SEO（自然流量重點）+ 不破壞 WC cart / checkout 既有流程。AddToCart 用 **query string `profit_shop_id`** + WC `woocommerce_add_cart_item_data` filter 在 cart_item_data 注入分潤標記，再交給 Phase 3-D `CartPriceOverrideHook` 簽章 / 套價。注意這條路與 Partner Portal SPA（`/profit-report/{slug}/`）路線**完全不同**：partner portal 走獨立 SPA + 不走 theme；賣場前台走 PHP template + 走 theme。
13. **Always** 讓 admin 列表頁遵循 `.claude/rules/admin-list-ui.rule.md` 的「篩選卡片 + 實體列表 & 新增按鈕」標準版型（基準範本 `#/products`）：兩張 `antd-toolkit` 的 `Card`、新增按鈕在列表卡片內 Table 之上、Table 鋪 `defaultTableProps` + `getDefaultPaginationProps({label})`。**Don't** 用 Refine `<List>` 當列表頁外框、**Don't** 用 `antd` 的 `Card`。
14. **Don't** 假設 `pnpm lint` 存在——實際腳本是 `pnpm lint:ts`（含 `--fix`）與 `pnpm lint:php`（含 `phpcbf`，會改檔案）；CI / agent 唯讀檢查用 `npx eslint "js/src/**/*.{ts,tsx}"`（不加 `--fix`）與 `composer lint`。
15. **Don't** 把 antd `<Form>`（尤其 `type="submit"` 按鈕）直接放進 `antd-toolkit` 的 `SimpleModal` 而不處理事件冒泡——遮罩對所有冒泡 click 呼叫 `preventDefault()`，會連帶取消表單送出且無任何錯誤訊息。修法與清單見 `react.rule.md` §6.4 與 `admin-list-ui.rule.md` §7。
16. **Don't** 對 `wc-rest` resource 只設 `pagination.pageSize` 就假設分頁生效——WC REST API 只認 `per_page`，要把它當 **filter** 送才會生效，否則靜默退回每頁 10 筆。詳見 `react.rule.md` §3。
17. **Don't** 用 `agent-browser` 之類的瀏覽器自動化 CLI 截圖時假設呼叫端 shell 的 `cd` 會影響它的相對路徑——`agent-browser` 是**獨立行程**，相對路徑是相對於它自己的工作目錄（通常是專案根），**不是**呼叫端 shell 當下所在目錄。截圖 / 輸出檔案一律傳**絕對路徑**，否則檔案會落在專案根目錄，混進 `git status` 的 untracked 清單，有被 `git add -A` 誤收進版本庫的風險。
18. **Don't** 以為 Refine dataProvider 的錯誤物件（`HttpError`）帶有 `error.response.data`——Refine 只保留 `{ message, statusCode }`，原始 response body（含後端 `code` / `data`）已經不見，`statusCode` 甚至可能與真實 HTTP 狀態碼不符。錯誤訊息解析要 fallback 到 `error.message`，不要假設一定讀得到 `response.data.code`。詳見 `react.rule.md` §3。
19. **Don't** 寫帶 `tw-` prefix 的 Tailwind class——`tailwind.config.js` **沒有設 `prefix`**，class 一律無前綴（`mb-2` / `flex`）。唯一的例外是 config `plugins` 手寫的 6 個（`tw-hidden` / `tw-block` / `tw-inline` / `tw-fixed` / `tw-columns-1` / `tw-columns-2`，因為無前綴版與 WP 衝突被 `blocklist` 擋掉）。寫錯不會有 build 錯誤，樣式只是安靜地不生效。另：Tailwind entry 是 `js/src/assets/styles/tailwind.css`，**新增 SPA bundle 時必須 import**，否則該 bundle 的 utility 全部無效。詳見 `react.rule.md` §10。
20. **Don't** 假設 `<Tooltip>` 包 disabled `<Button>` 會顯示提示——antd v5 拿掉了 v4 的 disabled 相容包裝，瀏覽器又不對 disabled `<button>` 派發滑鼠事件，提示因此永遠不會出現，且沒有任何錯誤訊息。也**不要**只靠 antd 目前會在 `disabled` 時抽掉 `<Button href>` 的 `href` 就假設安全——外開連結按鈕該拆成停用 / 可用兩條 render 分支，別把 `href` 和 `disabled` 一起塞給同一顆 `<Button>`。詳見 `react.rule.md` §2。
21. **Don't** 想把 antd `<Table>` 的展開圖示放進「某個欄位內」（而非表格最左端）時，還去改 `components.body.row` 或另外處理展開列——用 `showExpandColumn: false` 關掉預設最左展開欄，改在目標欄位自刻按鈕 + 受控 `expandedRowKeys`（非受控點了沒反應）即可；`expandable` 與自訂 `components.body.row`（例如 dnd-kit 拖曳排序）**零衝突**，因為 rc-table 渲染展開列時只傳 `className` / `style` 給自訂 row component、**不傳 `data-row-key`**，會自動落進「先判斷 `data-row-key` 是否存在」這種既有守門的 fallback 分支。`expandedRowRender` 也天然 lazy（沒展開過的列不 render，收合是 `display:none` 不 unmount），查詢可以直接寫在展開列渲染的子元件裡，不需要自己刻快取。詳見 `react.rule.md` §6.5「`ItemsEditor` 變體展開列」。

## Profit Shop Domain（v1 開發中）

> 全新「分潤賣場」系統，與舊版 legacy 一頁商店共存。
> 設計文件：`specs/2026-05-06-profit-shop-design.md`
> 架構與規範：`.claude/rules/profit-shop.rule.md`

**目前狀態**：Phase 4-A + 4-B + 4-C + 5 + 6-A（含 6-A1 後端 + 6-A2 前端 UI）+ 7（賣場編輯頁商品選擇器重構）+ 8（Partner 夥伴圖片）+ 9（賣場商品變體層價格 / 灌水人數覆寫）完工 → Profit Shop 全套上線就緒（Domain / Application / Infrastructure / Admin SPA / Partner Portal / 賣場前台 + AddToCart 端到端 + Partner 自助修密碼端到端 + 商品選擇器 Modal 化 + Partner 夥伴圖片 + 變體層價格覆寫含單品頁一致性）。Phase 7 / 8 / 9 尚未 commit（實作變更見 `git status`），已知問題與技術債見 `specs/tech-debt.md`。

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

### Phase 4-C（賣場前台 + AddToCart 端到端整合）已完工（commits `2d6a76a` / `f813e07`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-C1 | `2d6a76a` | 賣場前台 PHP renderer + Theme 整合 template：URL `/profit-shop/{slug}/`（`RewriteRules::SHOP_QUERY_VAR='profit_shop_slug'` / `SHOP_REWRITE_PREFIX='profit-shop'` 常數，priority 'top'）+ `ProfitShopRenderer`（`template_redirect` priority 9，DI 注入 `ProfitShopRepositoryInterface`，draft 預覽走 `edit_post($shop->id)` 對特定 post ownership 檢查，二輪修 HIGH-1 從 `edit_posts`）+ `find_by_slug` 補上 Repository（雙保險 trash 過濾）+ `templates/profit-shop-front.php`（走 `get_header` / `get_footer`，與 partner portal 完全脫離 theme 不同；商品 grid + simple form / variable+grouped link 雙路徑 add-to-cart + WC `<del>` / `<ins>` 原價特價 + 全 escape）+ `<title>` / `<link rel='canonical'>` SEO meta + `remove_action wp_head rel_canonical / _wp_render_title_tag` 防雙 canonical / 雙 title | 7 檔（+433） |
| 4-C2 | `f813e07` | AddToCart Hook + Cart UI 分潤標記：`Infrastructure/WooCommerce/AddToCartHook`（**priority 5，早於** Phase 3-D `CartPriceOverrideHook` priority 10）從 query string `profit_shop_id` 注入 cart_item_data；**三道閘門縱深防偽造**：(1) shop 存在 (2) `status='publish'` (3) `product_id ∈ shop->items()` O(1) lookup；只讀 `$_GET + $_POST`（**排除 `$_COOKIE`** 攻擊面，security M-1 修）；`ctype_digit` 純數字檢查（拒 `'01'` / `'+1'` / 浮點 / 負數）；short-circuit「上游已帶則 return」防 Phase 3-D IT regression；`is_admin && !wp_doing_ajax` 不掛 hook + `error_log` 過濾 `\r\n\t`。`CartItemMetaDisplay`（`woocommerce_get_item_data` filter，**一次涵蓋 mini-cart / cart page / checkout**，shop 已刪靜默不顯示但仍可結帳；URL 用 `RewriteRules::SHOP_REWRITE_PREFIX` DRY 統一來源，wordpress M-1 修；`target='_blank' rel='noopener'` 防 reverse tabnabbing）。`Loader.php` 註冊順序：4-C1 ProfitShopRenderer → 4-C2 AddToCartHook (5) → 3-D CartPriceOverrideHook (10) → CartItemMetaDisplay (999) | 3 檔（+308） |

**Phase 4-C 完工要點**：
- 端到端流程：customer 訪問 `/profit-shop/{slug}/` → 渲染商品 grid → 點加購（form action `home_url('/')` 或 permalink + `add_query_arg('profit_shop_id', ...)`） → WC add-to-cart → AddToCartHook (priority 5) 三閘門驗證並注入 cart_item_data → CartPriceOverrideHook (priority 10) 寫 4 筆 `_profit_*` meta + HMAC sign → CartItemMetaDisplay 顯示「來自賣場 XXX」 → CartPriceOverrideHook (priority 999) 結帳前驗章 + `set_price` → Order 完成 → `OrderItemSettlementRepository` 寫 partner 分潤紀錄
- 4 大攻擊情境驗證：偽造 shop_id（find null → return unchanged）/ 偽造 product_id 不在 shop（product_in_shop 拒）/ 拿正版 shop 套未授權商品（product_in_shop 拒）/ cart session 後手動改（兩 filter 同 request 同步串接，無 race）
- DRY 統一：所有 URL 前綴用 `RewriteRules::SHOP_REWRITE_PREFIX` 單一來源
- composer lint 146/146 全綠；既有 4-A / 4-B / Phase 3-D / 3-E 完全 0 regression
- 不引入新 npm / composer 套件

### Phase 6-A1（Partner 自助修密碼）已完工（commit `e974ae4`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain ValueObject | `PartnerPassword`（密碼複雜度規則：≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字；`mb_strlen($value, 'UTF-8')` 以 codepoint 計，避免 byte / multibyte 語意混亂；不 trim、不 normalize、value() 原樣回傳） | `inc/classes/Domains/ProfitShop/Domain/ValueObject/PartnerPassword.php` |
| Domain Exception | `WeakPassword`（final extends \DomainException，attr `reasons: string[]` + `getReasons()` getter；對映 422 `weak_password` + `data.reasons`） | `inc/classes/Domains/ProfitShop/Domain/Exception/WeakPassword.php` |
| Application UseCase | `ChangePasswordUseCase`（流程嚴格鎖死：assert_not_blocked → find_by_id → verify_password → new PartnerPassword → save → reset → audit log；pseudo-slug `pwchange:{id}` 與 login slug 維度隔離；弱密碼**不**污染 rate-limit） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Auth/ChangePasswordUseCase.php` |
| Application DTO | `ChangePasswordInput`（@internal，攜帶明文 current/new password，**不**提供 to_array）+ `ChangePasswordOutput`（success + password_changed_at，可安全序列化） | `inc/classes/Domains/ProfitShop/Application/DTO/ChangePasswordInput.php` + `ChangePasswordOutput.php` |
| Service / TokenStore | `PartnerTokenStore::verify` L138-156 同秒撤銷契約（M-1 race window 修補）：`issued_at <= password_changed_at` 視為已撤銷（從 `<` 反轉為 `<=`，閉合「同秒簽發舊 token + 同秒改密」場景） | `inc/classes/Domains/ProfitShop/Application/Service/PartnerTokenStore.php` |
| Presentation | V2Api 新增 `POST /partner-auth/change-password`（permission `partner_token`，body 不過 sanitize_text_field 保留密碼字面值；success path：Set-Cookie Max-Age=0 + Cache-Control no-store/no-cache/must-revalidate + Pragma no-cache；L-4 fail-fast `_partner_term_id <= 0` 拋 InvalidArgumentException 防污染 `pwchange:0` rate-limit）；`make_partner_login_rate_limiter()` factory 抽出（DRY，login + change-password 共用）；ExceptionMapper 加 `WeakPassword → 422 weak_password` + `data.reasons[]`（在 \DomainException fallback 之前判斷，否則被吞成 400 validation_failed） | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php` + `Infrastructure/Rest/ExceptionMapper.php` |
| 測試 | 35 個新 test method：8 method（PartnerPasswordTest）+ 3 method（WeakPasswordTest）+ 12 method（ChangePasswordUseCaseTest）+ 11 method（PartnerChangePasswordEndpointTest IT）+ 1 method（ExceptionMapperWeakPasswordTest IT）+ 反轉 PartnerTokenStorePasswordRotationTest 同秒 case 為「已撤銷」契約 | `tests/Unit/Domain/ValueObject/` + `tests/Unit/Domain/Exception/` + `tests/Unit/Application/UseCase/Partner/Auth/` + `tests/Integration/Infrastructure/Rest/` |

**Phase 6-A1 完工要點**：
- 8 條安全紀律：(1) current_password 必驗（防 cookie 偷取後改密鎖人）/ (2) pseudo-slug `pwchange:{id}` 與 login slug 維度隔離 / (3) 改密成功後 password_changed_at 寫入 + Set-Cookie Max-Age=0 → 舊 token 立即失效（M-1 同秒覆蓋）/ (4) 弱密碼不污染 rate-limit / (5) response 不洩漏密碼 / hash / token / (6) audit log 不含明文密碼 / (7) admin nonce 不能走此 endpoint（permission `partner_token`）/ (8) Cache-Control: no-store, no-cache, must-revalidate（success path）
- M-1 race window 修補：`issued_at == password_changed_at` 同秒視為已撤銷（從 `<` 反轉為 `<=`），縱深防禦覆蓋「同秒簽發舊 token + 同秒改密」場景
- L-2 multibyte 規則：PartnerPassword 長度檢查以 codepoint（mb_strlen UTF-8）為單位，避免 ASCII vs 中文 / emoji 字元語意不一致
- L-4 fail-fast：V2Api callback 對 `_partner_term_id <= 0` 拋 InvalidArgumentException，防 permission_callback 流程缺陷被繞過時污染 `pwchange:0` rate-limit 維度
- IP 取值流程與 partner login DRY：filter_var FILTER_VALIDATE_IP 防呆 + `power_shop_partner_login_client_ip` filter hook 共用
- composer lint 全綠；既有 Phase 3-D PartnerTokenStorePasswordRotationTest 同秒 case 契約反轉為「已撤銷」（檔頭 docblock 同步）
- 不引入新 npm / composer 套件 / 不動 Phase 4-A SPA / 4-B Portal / 4-C 賣場前台

### Phase 6-A2（Partner 自助修密碼前端 UI）已完工（commit `c80b4cc`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Frontend Hook | `useChangePassword`（typed `useMutation<TChangePasswordOutput, AxiosError, TChangePasswordInput>` + `mutationKey: ['partner-change-password']` + `retry: 0` 防重送） | `js/src/partner-portal/hooks/useChangePassword.ts` |
| Frontend Page ⚠ HIGH-RISK | `ChangePassword.tsx`（antd Form 三欄位：current / new / confirm；Form rule 阻擋 new === current / new !== confirm；cooldown timer + success state lock + setTimeout cleanup ref + mutation.reset；submit button `disabled = isLoading \|\| isCoolingDown \|\| success` 三重守門；成功後 `forceLogoutAndRedirect('password_changed')`） | `js/src/partner-portal/pages/ChangePassword.tsx` |
| Auth API wrapper | `changePassword(payload): Promise<TChangePasswordOutput>` + `TChangePasswordInput` / `TChangePasswordOutput` typed + 加 `X-Skip-Auth-Redirect` header（防 401 interceptor 干擾 success notification UX） | `js/src/partner-portal/auth/api.ts` |
| AuthContext helper | `forceLogoutAndRedirect(reason?: string)`（與既有 `handleLogout` 並存：前者強制 redirect 並附 query reason；後者依 `isLoginPage` 防迴圈） | `js/src/partner-portal/auth/AuthContext.tsx` |
| Routes | App.tsx 加 `/change-password` 路由（authenticated only） + Dashboard 加「修改密碼」link button 入口 | `js/src/partner-portal/App.tsx` + `pages/Dashboard.tsx` |
| Login one-shot notice | Login 頁讀 `?reason=password_changed`（KNOWN_REASONS 白名單，未來可擴 `session_expired` 等）顯示一次性 success notification | `js/src/partner-portal/pages/Login.tsx` |
| ExceptionMapper context-aware | `mapPartnerException(error, context?: 'default' \| 'change-password')` 第二參數 + `KNOWN_WEAK_PASSWORD_REASONS` 白名單 + `isKnownWeakPasswordReason` type guard；既有 Login 不傳第二參數預設 `'default'`，向後相容 | `js/src/partner-portal/utils/partnerExceptionMapper.ts` |

**Phase 6-A2 完工要點**：
- HIGH-RISK 8 條紀律（二輪後強化版）：(1) 明文密碼**只**在 antd Form state，不進 cache / storage / log / (2) 不 console.log 印密碼 / (3) submit 後 form.resetFields 分支策略（weak_password 只清 current；其他全清）/ (4) submit button 三重守門 `disabled={mutation.isLoading \|\| isCoolingDown \|\| success}` / (5) 成功後 `forceLogoutAndRedirect`（後端 password_changed_at 已撤銷舊 token）/ (6) `mutation.reset()` 清 react-query variables（防 React DevTools 窺）/ (7) Form rule 阻擋 new === current / new !== confirm / (8) setTimeout cleanup ref + useEffect unmount cleanup（防使用者中途離開後仍觸發 logout）
- `X-Skip-Auth-Redirect` header 防 401 interceptor 干擾 success notification（master 自決技術決策）
- `forceLogoutAndRedirect` 與 `handleLogout` 並存（前者強制 redirect + reason，後者依 `isLoginPage` 防迴圈）
- 失敗清欄位策略分支（MAJOR-3）：weak_password UX 友善只清 current；其他敏感全清
- success state lock（MAJOR-4，A+ 級品質）：連返回按鈕都 disabled，蓋 1.5s race
- `partnerExceptionMapper` context-aware 向後相容（Login 不傳第二參數預設 `'default'`）
- 隔離驗收（assets/main-*.js）：`App1 \| @refinedev \| Refine \| dataProviderName \| wc-rest \| wp-rest \| X-WP-Nonce` → **0 命中**
- 0 admin SPA 修改 / 0 新 npm 套件 / 0 後端變動（純前端 UI 串接 Phase 6-A1 endpoint）
- TS partner-portal 範圍 0 errors（admin baseline 129 不變）/ ESLint partner-portal 0 errors / 0 warnings / pnpm build 39.90s ✓

### Phase 7（賣場編輯頁商品選擇器重構）已完工（尚未 commit）

> 上游規格：`specs/clarify/2026-07-29-profit-shop-product-selector.md` + `specs/plan/2026-07-29-profit-shop-product-selector-plan.md`（695 行，含資料流分析、錯誤/失敗處理登記表、48 條驗收清單）

| 階段 | 範圍 | 檔案 |
|------|------|------|
| 第一階段 | BUG-A 契約對齊：`types.ts` items 改巢狀（`override{regular_price,sale_price,signup_fee}` + `variations`）、`Edit/index.tsx` 填表 / 送出兩路徑對齊、`ItemsEditor` 價格欄改 2 欄（原價覆寫 / 特價覆寫）、新增 `ProfitShopHydratorTest`（Unit）+ `ProfitShopItemsRoundTripTest`（IT） | `js/src/pages/admin/ProfitShop/types.ts` + `Edit/index.tsx` + `components/ItemsEditor.tsx` + `components/ProfitShopForm.tsx` + 2 個新測試檔 |
| 第二階段 | 抽 `ProductTableBody` 共用子元件（純重構，`#/products` 零 regression）：`useProductTableQuery.ts` 抽出 `useTable` 設定、`ProductTableBody.tsx` presentational 主體、`useColumns` 加 `showActions` 參數 | `js/src/components/product/ProductTable/`（`index.tsx` 改外殼 + 2 個新檔） |
| 第三階段 | 新增 `ProductPickerModal`：`SimpleModal` 內嵌 `ProductTableBody`，跨頁勾選、三重 disabled（變體 / 已加入 / 破 100 上限）、`syncWithLocation: false` 隔離 URL 污染、選取狀態自帶 `useState`（不接 Jotai atom） | `js/src/pages/admin/ProfitShop/components/ProductPickerModal/` |
| 第四階段 | `ItemsEditor` 接上 Modal：移除舊 `useSelect` + `Select`，改「選擇商品」按鈕；`handleBatchAdd` 雙層 dedupe + 100/500 上限提示 | `js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx` |
| 第五階段 | 商品顯示補齊：`useShopItemProducts`（`wc-rest` `include=` 分 5 chunk，每 chunk 100）+ `_enrichState` 四分支降級顯示（ok / missing / failed / pending） | `js/src/pages/admin/ProfitShop/hooks.ts` |

**Phase 7 完工要點**：
- **BUG-A 修正**：前端過去送後端從未定義的扁平欄位 `price_override` / `variation_id`，PHP 只讀巢狀 `override` / `variations` → 使用者設定的價格覆寫存檔後消失。**PHP 端零產品程式碼變更**——`ProfitShopHydrator` / `CptProfitShopRepository` / `ItemValidator` 本來就已經讀寫巢狀契約，成因 100% 在前端；PHP 端工作只有補測試鎖契約（`ProfitShopHydrator` 先前零測試覆蓋，是 BUG-A 能潛伏數個 Phase 的根本原因）。詳見 `.claude/rules/profit-shop.rule.md` §7
- `ProductTableBody` 共用子元件：`useTable` 刻意留在 caller（shell 或 Modal），不移進 body——shell 需要 `searchFormProps` / `tableProps.dataSource` 給 Jotai 同步 effect 用，這個設計讓 `#/products` 的 diff 是純 JSX 搬移，是零 regression 最強的保證
- `ProductPickerModal` 三重 disabled：`isVariation(record.type) || existingIds.has(Number(record.id)) || (selected.length >= 100 && !isSelected)`；跨頁勾選以 `record` 物件累積（不依賴當前頁 `dataSource`），關閉 Modal 時 reset
- 100 筆單次批次上限（硬）、500 筆賣場總數軟上限，皆僅前端擋，後端無對應數量驗證（記為技術債，見 `specs/tech-debt.md`）
- 全域閘門：實測數據見下方「驗證數據」表（tsc / eslint / build / 既有 SPA regression 全數確認）
- 專案無前端單元測試框架（vitest / testing-library），UI 行為以型別 + lint 閘門 + 48 條手動驗收清單把關；BUG-A 契約回歸另補 API-level e2e spec（`tests/e2e/06-profit-shop/profit-shop-items.spec.ts`，沿用既有 `request.put` + `X-WP-Nonce` pattern，非 UI 驅動）
- 過程中發現並修正 3 個 unrelated 環境問題（隨手修，非本次 scope 但擋路必須先解決）：`main.tsx` 缺 `<AntdApp>` provider（第四階段發現，詳見 `react.rule.md` §2）、`.wp-env.json` core 版本釘住、`tests/bootstrap.php` Abilities API polyfill 時序（後兩者詳見 `.claude/rules/wordpress.rule.md` §11）
- 13 條踩坑知識分別寫入 `react.rule.md`（元件架構 + SimpleModal / AntdApp provider / wc-rest 分頁三條踩坑）、`admin-list-ui.rule.md`（Modal 情境必須關閉的元素清單）、`wordpress.rule.md`（測試環境 5 條）、`specs/tech-debt.md`（跨領域已知問題，含既有 21 個 pre-existing integration 測試失敗、`phpstan analyse inc` 在 HEAD 就跑不起來等，皆與本次改動無關）、Common Pitfalls #17（`agent-browser` 截圖絕對路徑）
- 不引入新 npm / composer 套件；不動 Domain 層（`PriceCalculator` / `CartPriceOverrideHook` / `OverrideItem` Entity / `PriceOverride` VO 一律未改）

**驗證數據**（實測，2026-07-29）：

| 層級 | 結果 | 說明 |
|------|------|------|
| Unit | 338 / 669 assertions / 1 failure | 327 baseline + 11 新增（`ProfitShopHydratorTest`）；failure 是 pre-existing 的 `LoginRateLimiterIpDimensionTest`（見 `specs/tech-debt.md` TD-2） |
| Integration | 168 / 723 assertions / 21 failures | 158 baseline + 10 新增（`ProfitShopItemsRoundTripTest`）；21 個全部 pre-existing（見 `specs/tech-debt.md` TD-1） |
| e2e `06-profit-shop` | 4 passed | 新增（`profit-shop-items.spec.ts`） |
| e2e `01`～`05` | 162 passed（27.6 分鐘） | 既有，零 regression |
| `npx tsc --noEmit` | 127 | baseline 129，降 2（刪除死 prop `cardProps` 順帶消掉既有 unused 錯誤） |
| `npx eslint "js/src/**/*.{ts,tsx}"` | 0 errors / 135 warnings | baseline 0 errors / 136 warnings |
| `pnpm build` | 成功 | 各階段 37.94s ～ 48.62s |
| `git diff --name-only -- inc/` | 0 | PHP 產品程式碼零修改，BUG-A 純前端成因的直接證據 |

BUG-A 修正有**三層鎖**：Unit 11 + Integration 10 + e2e 4，扁平契約想復活會在三個層級同時被擋下，任何一層被繞過或誤刪，另外兩層仍會抓到回歸。詳見 `.claude/rules/profit-shop.rule.md` §7。

### Phase 8（Partner 夥伴圖片）已完工（尚未 commit）

Partner 可設定夥伴圖片，顯示於分潤夥伴列表、編輯頁與賣場編輯頁的分潤設定 selector。

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain Snapshot | `PartnerSnapshot` 加 `?int $image_id` + `?string $image_url`（後者是**唯讀衍生欄位**，`save()` 一律忽略） | `Domain/Snapshot/PartnerSnapshot.php` |
| Application DTO | `PartnerInput` 加 `image_id`（`null` / `0` / 負數正規化為 null = 移除圖片）；`PartnerOutput` 加 `image_id` + `image_url` | `Application/DTO/Partner{Input,Output}.php` |
| Infrastructure | `PartnerTermRepository` 新增 termmeta `_partner_image_id` 讀寫 + `resolve_image_url()`（`wp_get_attachment_image_url($id, 'thumbnail')`）；孤兒 attachment 回 null URL 但**保留 ID**（讀取路徑零寫入副作用） | `Infrastructure/Persistence/PartnerTermRepository.php` |
| UseCase / Hydrator | `CreatePartner` / `UpdatePartner` 傳遞 `image_id`；`PartnerHydrator::to_output` 帶出 `image_id` + `image_url`。**V2Api 零修改**（`from_array` / `to_array` 自動涵蓋） | `Application/UseCase/Partner/` |
| Frontend | **直接複用商品頁 `Gallery` 元件**（加 4 個 optional props：`name` / `shape` / `title` / `allowSetThumbnail`，商品頁行為零變化）+ `PartnerForm` 圖片欄（單張圓形）+ List 夥伴圖片欄 + `PartnerSelector` 選項頭像 + `utils.ts`（欄位名對映 + `toImageId`） | `js/src/components/product/fields/Gallery/` + `js/src/pages/admin/ProfitPartner/` |
| 列表頁對齊 | 順手把 `#/profit-partner` 對齊 `admin-list-ui.rule.md` 標準版型（篩選卡片 + FilterTags + 新增按鈕移進列表卡片 + `NameId` Link 欄 + `defaultTableProps`），新增 `List/Filter.tsx`（關鍵字 / 夥伴圖片有無，client-side `useMemo` 過濾） | `js/src/pages/admin/ProfitPartner/List/` |
| OpenAPI | `PartnerInput.image_id` / `PartnerOutput.image_id` + `image_url` schema | `specs/api/api.yml` |
| 測試 | Unit +2（`DtoRoundTripTest`：image_id 正規化 / output null 契約）、IT +3（`PartnerTermRepositoryTest`：持久化只寫 ID / null 清除 termmeta / 孤兒 attachment 保留 ID） | `tests/Unit/Application/DTO/` + `tests/Integration/Infrastructure/Persistence/` |

**Phase 8 完工要點**：
- **只持久化 `image_id`，`image_url` 是唯讀衍生欄位**——換網域 / CDN 時 URL 會失效，attachment ID 不會。IT 刻意傳入假 URL 鎖死 `save()` 忽略它的行為（詳見 `profit-shop.rule.md` §7「Partner 夥伴圖片」）
- 「`image_id` 非 null 但 `image_url` 為 null」是**合法狀態**（attachment 被刪除），前端退回預設頭像 / 讓站長重挑，而非顯示破圖
- `PartnerSelector` 的 `label` 變 ReactNode 後 `optionFilterProp="label"` 失效，名稱搜尋靠 option 上的純字串 `name` / `slug` + 自訂 `filterOption`（見 `react.rule.md` §6.5）
- V2Api / ExceptionMapper / Domain Service / Auth 全部零修改；不引入新 npm / composer 套件
- **驗證數據**：Unit 340 / 677 assertions / 1 failure（338 baseline + 2 新增，failure 為 pre-existing TD-2）；Integration 171 / 742 assertions / 21 failures（168 baseline + 3 新增，21 failures 全 pre-existing TD-1）；`npx tsc --noEmit` 127（baseline 127）；`npx eslint` 0 errors / 135 warnings（baseline 同）；`composer lint` 154/154 全綠；`pnpm build` 成功

### Phase 9（賣場商品變體層價格 / 灌水人數覆寫）已完工（尚未 commit）

> 上游需求：`#/profit-shop/edit/{id}` 的「賣場商品」表格，`variable` / `variable-subscription` 商品在**商品圖片左側**顯示展開鈕，點開後可對每個變體覆寫價格與灌水購買人數。
> 實作計劃：`specs/plan/2026-07-30-profit-shop-variation-override-plan.md`（含資料流分析、錯誤 / 失敗處理登記表、8 個階段）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain ValueObject | **新增** `VariationOverride`（`PriceOverride` + `InflatedCount` 兩參數皆必填，`to_array()` 輸出 `{override, inflated_count}`；刻意不把 `inflated_count` 塞進 `PriceOverride`，避免污染它在 `PriceCalculator` / `ProductSnapshot` 的其他用途） | `Domain/ValueObject/VariationOverride.php` |
| Domain Entity | `OverrideItem::$variations` 型別升級為 `array<int, VariationOverride>`；`get_variation_override(int): ?PriceOverride` **簽章不變、改為委派**（`return ( $this->variations[$vid] ?? null )?->override`）；新增 `get_variation_inflated_count()`；`set_variation_override()` 加可選第三參數；建構子加 fail-fast 型別守衛（非 `VariationOverride` 值立即拋 `\InvalidArgumentException`） | `Domain/Entity/OverrideItem.php` |
| Infrastructure（新層） | **新增** `SingleProductPriceHook`：WC 單品頁套用賣場覆寫價（含變體），解決「單品頁一個價、購物車另一個價」的價格跳動；`template_redirect` priority 20，只在 `is_product()` 且網址帶合法 `profit_shop_id` 時才註冊 filter；沿用 `AddToCartHook` 三道閘門（shop 存在 / `status=publish` / product 屬於該 shop）；掛 `woocommerce_product_get_price` 系列（單一變體實際結帳價）+ `woocommerce_variation_prices_*` 系列（價格區間，因 WC 讀值走 `'edit'` context 而需另掛）+ 改寫 `woocommerce_get_variation_prices_hash` 併入 shop_id（防跨賣場快取污染） | `Infrastructure/WooCommerce/SingleProductPriceHook.php` |
| Application / Persistence | `ProfitShopHydrator::build_override_item()` + `CptProfitShopRepository::build_override_item()` 的 variations 迴圈改建 `VariationOverride`；反序列化對缺 `inflated_count` 鍵的舊資料一律 `?? 0`（唯一向後相容接住點，不需 migration） | `Application/UseCase/Shop/Support/ProfitShopHydrator.php` + `Infrastructure/Persistence/CptProfitShopRepository.php` |
| 前台顯示 | 商品層 `inflated_count` 首次接上賣場前台：`get_total_sales() + inflated_count` > 0 時顯示「已售 N 件」；變體層 `inflated_count` 暫無顯示位置（見 `specs/tech-debt.md` TD-15） | `Infrastructure/WordPress/templates/profit-shop-front.php` |
| Loader | 註冊 `SingleProductPriceHook::instance()` | `Loader.php` |
| OpenAPI | `OverrideItemInput.variations.additionalProperties` 加 `inflated_count`（`integer`, `default: 0`, `minimum: 0`），description 同步標註向後相容與 TD-15 | `specs/api/api.yml` |
| Frontend 型別 / 填表 | `TVariationOverride` 加 `inflated_count: number`；`Edit/index.tsx` 填表時逐筆正規化 `variations`（補齊 `override` 三欄 + `inflated_count: 0`，與商品層同一套理由：DB 可能回缺欄位的舊資料） | `pages/admin/ProfitShop/types.ts` + `Edit/index.tsx` |
| Frontend Hook | **新增** `useProductVariations(productId, enabled)`：走 `wc-rest` `GET /products/{id}/variations`，`per_page` 當 filter 送（§3 陷阱）；**刻意設計成由展開列的子元件呼叫**，不在 `ItemsEditor` 那一層查，靠 rc-table `expandedRowRender` 天然 lazy 避開 N+1 與 Rules of Hooks 限制 | `pages/admin/ProfitShop/hooks.ts` |
| Frontend 共用工具 | **新增**（計劃外，master 自決）`utils.ts`：`emptyPriceOverride` / `emptyVariationOverride` / `toInputValue` / `toPriceString` / `isSaleOverRegular` 五個純函式，供 `ItemsEditor`（商品層）與 `VariationOverrideEditor`（變體層）共用，避免兩份實作讓「留空 vs 填 0」語意分歧 | `pages/admin/ProfitShop/utils.ts` |
| Frontend 元件 | **新增** `VariationOverrideEditor`：展開列內容，4 欄變體子表格（變體 / 目前價格 / 覆寫價格 / 灌水購買人數）+ 孤兒鍵處理（賣場存了某 variation_id 但 WC 已無此變體，顯示「變體已失效」+ 移除鈕，不可靜默隱藏）+ R-1 據實揭露 `<Alert>` | `pages/admin/ProfitShop/components/VariationOverrideEditor/index.tsx` |
| Frontend 表格改版 | `ItemsEditor`：`showExpandColumn: false` 關掉 antd 預設最左展開欄，改在「商品名稱」欄自刻展開鈕（滿足「圖片左側」需求）；受控 `expandedRowKeys`；`rowExpandable` 限定 `isVariable(product?.type)`；三個變體 patch handler（最小重建範圍：只重建該商品那一列 + 該變體那一筆）；`validateItemsPriceOverride` 擴充涵蓋變體層（訊息同時指出商品與變體）；換頁 / 移除商品時同步清理 `expandedKeys` | `pages/admin/ProfitShop/components/ItemsEditor.tsx` |
| 測試 | 5 個 PHP 測試檔異動：`OverrideItemTest`（+8）、`ProfitShopHydratorTest`（+4）、`CptProfitShopRepositoryTest`（+2）、`ProfitShopItemsRoundTripTest`（+5）、**新增** `SingleProductPriceHookTest`（11）；`PriceCalculatorTest` 的 `make_item()` helper 改用 `array_map` 包 `VariationOverride`，15 個呼叫點斷言不變 | `tests/Unit/` + `tests/Integration/` |

**Phase 9 完工要點**：
- **委派法把破壞面壓到最小**：`OverrideItem::get_variation_override()` 簽章維持 `?PriceOverride` 不變，`PriceCalculator` / `CartPriceOverrideHook` / `ItemValidator` / `V2Api` / `AddToCartHook` **五檔零修改**（`git diff --stat` 完全空白），只有 `OverrideItemTest` 需要新增測試鎖住「委派後回傳型別不漂移」這個 invariant（`assertNotInstanceOf`）；**真實環境 `wp eval` readback 已人工驗證**回傳 class 確實是 `PriceOverride`，簽章契約在真實執行環境成立
- **R-1 CRITICAL GAP 已解決，且已人工端到端驗證**：規劃階段發現「變體覆寫價只在購物車生效，WC 單品頁沒有價格 filter」會讓顧客看到「單品頁一個價、購物車另一個價」的價格跳動，一旦開放變體覆寫 UI 就會變成使用者可見的信任問題。`SingleProductPriceHook`（第六-B 階段）補上這塊，且刻意收斂作用範圍（只在 `is_product()` 且帶合法 `profit_shop_id` 時才註冊 filter），不影響購物車 / 結帳既有流程。真實環境測試確認：單品頁價格區間隨變體覆寫位移（`42–45` → `45–888`）、逐變體 `display_price` / `display_regular_price` 正確、**負向驗證**（不帶 query string 時價格與 hash 皆完整回到原始值，零殘留）、賣場卡片 / 單品頁 / 購物車三處價格一致（皆為 888）。詳見 `profit-shop.rule.md` §7「SingleProductPriceHook」
- **R-2 部分解決，商品層已人工驗證**：商品層 `inflated_count` 首次接上賣場前台顯示（此前是「存得進去、讀得出來、沒人用」的反模式），真實環境實測「已售 880 件」＝真實銷量 873 + `inflated_count` 7，數字精確吻合；一般賣場 `test-shop-alpha` 亦實測正確（含 `> 0` 守門）。變體層因為賣場前台只渲染父商品卡片、變體選擇整段發生在 WC 單品頁，暫無顯示位置，前端已誠實揭露（Tooltip 文案刻意與商品層不同，見 `react.rule.md` §6.5），登記 `specs/tech-debt.md` TD-15
- **rc-table 內部行為逐行驗證後才動手**：`showExpandColumn: false`、展開列不帶 `data-row-key`（與既有 dnd-kit 拖曳排序零衝突）、`expandedRowRender` 天然 lazy 且收合不 unmount——三條都是先讀 `rc-table` 原始碼才確認可行，不是憑經驗猜的，詳見 `react.rule.md` §6.5「`ItemsEditor` 變體展開列」
- **舊資料向後相容零 migration**：`CptProfitShopRepository` 對缺 `inflated_count` 鍵的舊 `variations[vid]` 一律 `?? 0`，下次儲存自然補齊；兩條 IT 測試鎖住這個行為（缺鍵讀為 0 且價格仍讀得出來、重新儲存後自然升級格式）
- **三層錯誤處理分工延續 Phase 7 慣例**：前端守門（批次上限、Form rule）+ 後端 Domain 驗證（`ItemValidator` + `PriceOverride` VO，唯一真相，不因前端已擋就放寬）+ 降級不阻斷（變體補齊查詢失敗只影響顯示，不影響編輯與儲存）
- 過程中發現並登記數個非本次 scope 但值得記錄的觀察：`composer lint` 在 HEAD 就不綠（成因是未追蹤的 `.claude/tmp/` 暫存目錄被 phpcs 掃到，`vendor/bin/phpcs inc/` 才是有意義的閘門，TD-18）、`memo(VariationOverrideEditor)` 因 inline callback 而實際不生效（TD-19，非正確性問題，效能觀察）、`CartItemMetaDisplay` 的 item_data 在 WooCommerce Cart Block / Store API 路徑下顯示 `undefined:`（TD-21，與 Phase 9 無關，人工驗證時發現）
- **一個差點漏掉、已當場修正的文案落差**：`VariationOverrideEditor` 的 R-1 據實揭露 `<Alert>` 文案是規劃階段（`SingleProductPriceHook` 是否要做尚未拍板時）寫的，`SingleProductPriceHook` 後來確定要做且做完之後，那句「單品頁仍顯示原價」就與事實相反了，但沒有人回頭改。真實環境端到端驗證（單品頁區間 `42–45`→`45–888`）才抓到這個落差，team lead 已即時修正文案；已解決的原委與教訓見 `specs/tech-debt.md`「已解決」章節的 TD-20，同型失誤模式已寫進 `profit-shop.rule.md` §7「`SingleProductPriceHook`」章節（往後任何改動 `SingleProductPriceHook` 作用範圍時都要對照檢查相關 UI 文案）
- 不引入新 npm / composer 套件；不動 `PriceCalculator` / `CartPriceOverrideHook` / `ItemValidator` / `V2Api` / `AddToCartHook`；`ProductPickerModal` 的「變體不可勾選」邊界不受影響（變體覆寫走 `ItemsEditor` 展開列，是不同的操作入口）

**驗證數據（自動化）**（實測，2026-07-30；baseline 以**動工前實測**為準，非沿用 Phase 8 文件記載的數字——兩者 failure 數 1 / 21 完全吻合，僅 pass 數有出入，判斷是先前記錄時機差異，不影響回歸判斷）：

| 層級 | Baseline（動工前實測） | 完成後 | 判定 |
|---|---|---|---|
| Unit | 341 tests / 670 assertions / 1 failure | **353 / 694 / 1** | +12 tests，failure 未增加（TD-2 pre-existing） |
| Integration | 173 / 754 / 21 failures | **191 / 850 / 21** | +18 tests，failure 未增加（TD-1 pre-existing），21 條失敗名稱與順序與 baseline 完全相同 |
| `vendor/bin/phpcs inc/` | 136/136 綠 | **138/138 綠** | +2 新檔 |
| `npx tsc --noEmit` | 127 | **127** | 持平 |
| `npx eslint "js/src/**/*.{ts,tsx}"` | 0 errors / 135 warnings | **0 errors / 135 warnings** | 持平 |
| `pnpm build` | 成功 | **✓ 38.70s** | 成功 |

`@group` 已逐檔驗證（`--filter XxxTest --testdox` 筆數 == 檔內 `test_` 方法數），無靜默跳過。

**驗證數據（真實環境人工端到端驗證，2026-07-30，team lead 執行）**——這是唯一直接在瀏覽器 / `wp eval` 操作真實站台的驗證，性質與上面的自動化閘門不同；⚠️ **請勿誤讀為「全部通過」**，第五階段的 admin UI 手動清單仍未執行，見表格最後一列：

**① 第六階段：商品層 `inflated_count` 前台顯示 —— ✅ 已驗證**

| 賣場 | 商品 | 真實銷量 | `inflated_count` | 前台顯示 |
|------|------|----------|-------------------|----------|
| 臨時測試賣場 | Hoodie（商品 63） | 873 | 7 | 「已售 **880** 件」＝ 873 + 7，數字精確吻合 |
| `test-shop-alpha` | `[E2E] Test Product` | — | — | NT$800 劃掉 → NT$600 +「已售 4 件」 |
| `test-shop-alpha` | `銷售方案` | — | — | NT$10,017 → NT$200 +「已售 2 件」 |
| `test-shop-alpha` | `T-Shirt` / `E2E Frontend Test Course` | — | 0 | **未顯示**「已售 0 件」（`> 0` 守門正確） |

`<title>` SEO meta 亦確認正確：「測試賣場 Alpha｜測試夥伴 Alpha 的分潤賣場 - turbo」。

**② 第六-B 階段：`SingleProductPriceHook`（單品頁變體價格）—— ✅ 已驗證**

臨時賣場設定：商品 63 Hoodie，變體 79 覆寫 `999/888`、變體 86 覆寫 `777`（不設特價）、變體 80/81 不覆寫。

- **Repository 讀回**（`wp eval`）：`vid=79 override=999/888 inflated=3 [override class: PriceOverride]`——委派法簽章契約在真實環境成立（回傳的是 `PriceOverride`，不是 `VariationOverride`）
- **單品頁價格區間**：帶 `?profit_shop_id` 從 `NT$42–NT$45` 變為 `NT$45–NT$888`（區間端點位移是 filter 生效的指紋）
- **逐變體 `get_available_variations()`**：79→`888/888`（原/特價 999/888）、86→`777/777`（只設原價，未渲染出「原價劃掉→特價」假象）、80/81→`45/45`（未覆寫，維持原樣）
- **負向驗證（最關鍵）**：**不帶** `?profit_shop_id` 時價格與逐變體價格**全數回到原始值**（`42–45`），零殘留——同時證明 filter 只在帶合法 query string 時才註冊（不常駐），且 `woocommerce_get_variation_prices_hash` 改對了（沒改的話會讀到 `45–888` 的污染快取）
- **三處價格一致性**：賣場卡片 → 單品頁（888）→ 加購變體 79 後 Store API `/wc/store/v1/cart` 的 `price` / `line_total` / `total_price` 皆為 `"888"`

**③ 第五階段：`ItemsEditor` 展開列 admin UI 手動驗收清單（計劃書列的 11 條）—— ⏳ 尚未驗證**

需登入 `wp-admin` 操作才能驗證：`+` icon 位置在商品圖片左側、展開/收合不重查（Network 面板確認）、
拖曳排序（含展開狀態下）、換頁 / 換每頁筆數清空展開狀態、移除已展開商品無 console error、
100 筆商品輸入無明顯延遲、驗證訊息切到出錯 Tab 等。這條清單需要使用者本人操作 wp-admin
（agent 不會代為輸入登入密碼），本輪未執行，**不要當作已通過**。

驗證①②所用的臨時賣場與購物車項目**已全部清理**，站台資料已還原至驗證前狀態（剩餘 powershop
只有既有的 #2225 `123` 與 #1932 `test-shop-alpha`）。

### Phase 5+ 預告（reviewer 順手清單彙整 + 產品決策層）

> 本清單於 Phase 9 文件同步時清過一輪：原第 8、9、13 項（賣場前台 IP-based rate-limit / 賣場前台快取效能 / `ProfitShop::has_item` Domain method）核對程式碼後確認**已分別在 Phase 5-A.1（`PageRateLimitService` + `ProfitShopRenderer::maybe_render` 的 429 處理）與 Phase 5-B（`ProfitShopRenderer` publish 不送 `nocache_headers()` 條件化 + `ProfitShop::has_item()`，後者現由 Phase 9 `SingleProductPriceHook` 直接消費）完工**，只是 commit 之後這份清單沒有跟著移除，予以刪除並重新編號；其餘項目核對後仍未完工，維持原樣。

優先順序：
1. **LoginRateLimiter context-aware message provider**（reviewer 順手：audit log + admin email 在 `pwchange:{id}` pseudo-slug 場景目前語意誤導為「登入失敗」，需抽 message provider 區分 login / pwchange context）
2. **partner-reports/{kpi,trend,settlements} 三 callback 缺 `_partner_term_id <= 0` fail-fast 一致性**（reviewer 順手，與 6-A1 L-4 對齊）
3. **PartnerPasswordTest 補 multibyte / emoji / 中文邊界測試**（reviewer 順手，現有 8 method 已涵蓋 ASCII codepoint 但未顯式驗證 emoji / 中文與英文混排）
4. **密碼強度規則升級**（產品決策層，例如 NIST SP 800-63B 12 字元 / pwned password 黑名單）
5. **6-A2 OBS 順手 3 條**：partnerExceptionMapper context-aware DRY 重構（LOW-7 master 自決跳過：兩函式 80% 相同但語意差異點需保留 caller） / Login `?reason=` query string 多 reason 擴充（目前僅 `password_changed`） / ChangePassword 失敗清欄位策略文件化到 react.rule.md HIGH-RISK 表
6. **'power-shop' dataProvider thin wrapper**（Phase 4-A1 自決踩坑紀錄遺留）：包 `{code, data}` 讓未來頁面能用 useTable / useForm
7. **賣場前台 SEO 強化**：`pre_get_document_title` filter（與 SEO plugin 整合，4-C1 m-3）/ Open Graph / structured data
8. **rate-limit 5/15min 改 3 次**（reviewer L-3，產品決策層）
9. **token 三條讀取路徑收斂**（reviewer M-5，目前已 PHPDoc 化但未刪路徑）
10. **賣場前台 inline style 抽 CSS file**（4-C1 m-10）+ `img loading='lazy'`（m-6）等 NIT 微優化
11. **變體層 `inflated_count` 前台顯示**（Phase 9 R-2 未竟事項，見 `specs/tech-debt.md` TD-15）：賣場前台目前只渲染父商品卡片，變體選擇整段發生在 WC 單品頁；要顯示變體層灌水人數需另外介入單品頁渲染，範圍大於商品層
12. **變體查詢改走分頁載入**（Phase 9 R-4，見 `specs/tech-debt.md` TD-16）：`wc-rest` `per_page` 硬上限 100，變體超過 100 個的商品目前只顯示前 100 個

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

> **已知問題與技術債**：跨領域、暫不處理的項目（測試環境缺口、CI 設定、第三方套件契約落差、既有 UX 限制等）統一登記在 `specs/tech-debt.md`。單一領域內的已知落差（例如列表頁版型尚未對齊）記在對應的 `.claude/rules/*.rule.md`。
