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

## Profit Shop Domain（v1 開發中）

> 全新「分潤賣場」系統，與舊版 legacy 一頁商店共存。
> 設計文件：`specs/2026-05-06-profit-shop-design.md`
> 架構與規範：`.claude/rules/profit-shop.rule.md`

**目前狀態**：Phase 3-C 完工 → 下一步 Phase 3-D（Settlements 真實聚合 + AddToCart Hook + 安全強化）

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

### Phase 3-D 預告（待辦）

優先順序（**高優先**項已 commit message 點名）：
1. **regenerate-password 撤銷舊 token**（password_changed_at 比對機制；順手 #4 降級至 3-D）
2. WpSettlementSummaryProvider 接真實 `wc_order_itemmeta` JOIN 聚合（取代 placeholder）
3. AddToCart Hook（前台價格防竄改）+ Cart price override 機制
4. IP-based rate-limit（reviewer M-2）+ rate-limit 5/15min 改 3 次（reviewer L-3）
5. token 三條讀取路徑收斂（reviewer M-5）
6. regenerate-password 加 admin nonce + `Cache-Control: no-store`（reviewer 紀錄）
7. hash_hmac + `wp_salt` 升級（reviewer L-1，現行 sha256 已安全）

## Specs

Complete specs live in `specs/` using AIBDD Discovery multi-view architecture. See `specs/README.md` for the full index.

| View | Path | Count |
|------|------|-------|
| Activity | `specs/activities/*.activity` | 4 |
| Feature | `specs/features/**/*.feature` | 20 (12 command + 8 query) |
| UI | `specs/ui/*.md` | 9 pages |
| API | `specs/api/api.yml` | OpenAPI 3.0 |
| Entity | `specs/entity/erm.dbml` | 9 tables |
