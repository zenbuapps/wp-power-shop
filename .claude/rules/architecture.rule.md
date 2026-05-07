---
applyTo: "**/*.{php,ts,tsx}"
---

# Power Shop — 架構指引

> 系統架構、初始化流程、資料流。元件設計模式見 `react.rule.md`，安全性見 `wordpress.rule.md`。

---

## 系統架構概覽

```
┌──────────────────────────────────────────────────────────────┐
│                      WordPress Admin                          │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │  admin.php?page=power-shop                              │ │
│  │  ┌───────────────────────────────────────────────────┐  │ │
│  │  │             React SPA (#power_shop)                │  │ │
│  │  │  ┌───────────┐  ┌───────────┐  ┌──────────────┐  │  │ │
│  │  │  │ Dashboard  │  │  Orders   │  │   Products   │  │  │ │
│  │  │  │  (echarts) │  │ (Refine)  │  │   (Refine)   │  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
│  │  │  ┌───────────┐  ┌───────────┐  ┌──────────────┐  │  │ │
│  │  │  │   Users   │  │ Analytics │  │  Marketing   │  │  │ │
│  │  │  │ (Refine)  │  │ (Plots)   │  │ (OneShop 雙 │  │  │ │
│  │  │  │           │  │           │  │  入口)       │  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
│  │  │  ── 商家後台 Profit Shop CRUD UI（Phase 4-A）──  │  │ │
│  │  │  ┌───────────┐  ┌───────────┐  ┌──────────────┐  │  │ │
│  │  │  │ProfitShop │  │ ProfitPa- │  │ProfitMigra-  │  │  │ │
│  │  │  │  (Refine) │  │   rtner   │  │     tion     │  │  │ │
│  │  │  │           │  │ (Refine + │  │ (Refine +    │  │  │ │
│  │  │  │           │  │  HIGH-RISK│  │  HIGH-RISK   │  │  │ │
│  │  │  │           │  │  PW Reset)│  │  Import)     │  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
│  │  │  ┌──────────────┐                                 │  │ │
│  │  │  │ ProfitSetti- │                                 │  │ │
│  │  │  │     ngs      │                                 │  │ │
│  │  │  │ (Refine +    │                                 │  │ │
│  │  │  │  HIGH-RISK   │                                 │  │ │
│  │  │  │  Reset)      │                                 │  │ │
│  │  │  └──────────────┘                                 │  │ │
│  │  └───────────────────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│                  HashRouter + Refine                           │
│                           │                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │                   6 Data Providers                       │ │
│  │  ┌─────────┐ ┌────────┐ ┌────────┐ ┌───────────────┐  │ │
│  │  │ default │ │wp-rest │ │wc-rest │ │  power-shop   │  │ │
│  │  │(Powerh.)│ │(WP v2) │ │(WC v3) │ │(自有 REST API)│  │ │
│  │  └─────────┘ └────────┘ └────────┘ └───────────────┘  │ │
│  │  ┌──────────┐ ┌──────────────┐                         │ │
│  │  │wc-store  │ │ bunny-stream │                         │ │
│  │  │(Store v1)│ │ (Bunny CDN)  │                         │ │
│  │  └──────────┘ └──────────────┘                         │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│                     WordPress REST API                        │
│                           │                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │                    PHP Backend                           │ │
│  │  ┌──────────┐  ┌───────────┐  ┌─────────────────────┐ │ │
│  │  │Bootstrap │→ │ Admin     │  │ Domains/Loader      │ │ │
│  │  │          │  │ Entry.php │  │  ├→ Report V2Api    │ │ │
│  │  └──────────┘  └───────────┘  │  └→ ProfitShop\     │ │ │
│  │                                │      Loader (sub)   │ │ │
│  │                                └─────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Partner self-service portal（Phase 4-B，獨立 SPA）

```
┌──────────────────────────────────────────────────────────────┐
│             WordPress Frontend（非 admin）                    │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │  /profit-report/{slug}/   （RewriteRules + query var    │ │
│  │                            profit_partner_report）       │ │
│  │  ┌───────────────────────────────────────────────────┐  │ │
│  │  │   Partner Portal SPA (#profit_partner_portal)      │  │ │
│  │  │  ┌────────┐  ┌──────────┐  ┌────────────────┐   │  │ │
│  │  │  │ Login  │  │Dashboard │  │ TrendChart     │   │  │ │
│  │  │  │ (rate- │  │ (KPI 4   │  │  (echarts,     │   │  │ │
│  │  │  │  limit │  │  cards)  │  │  callback ref) │   │  │ │
│  │  │  │  倒數) │  │          │  │                │   │  │ │
│  │  │  └────────┘  └──────────┘  └────────────────┘   │  │ │
│  │  │  ┌────────────────────────────────────────────┐  │  │ │
│  │  │  │      SettlementsTable                       │  │  │ │
│  │  │  │   (desktop / mobile 響應式)                 │  │  │ │
│  │  │  └────────────────────────────────────────────┘  │  │ │
│  │  └───────────────────────────────────────────────────┘  │ │
│  │       HashRouter + AuthContext + axios apiClient        │ │
│  │  ── 不打包 admin / 不依賴 Refine / 不用 dataProvider ──│ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│                  HTTP-only Cookie auth                        │
│       sessionStorage metadata 只存 partner_id/name/slug       │
│                  （永不存 token，XSS 防護）                   │
│                           │                                   │
│                  /wp-json/power-shop/                         │
│                    partner-auth/* + partner-reports/*         │
│                           │                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │   Phase 3-C V2Api endpoint（partner_token permission）   │ │
│  │   partner_term_id 鎖死於 token，永不從 query string 取  │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

**Admin SPA vs Partner Portal SPA 對比：**

| 維度 | Admin SPA | Partner Portal SPA |
|------|-----------|--------------------|
| URL | `admin.php?page=power-shop` | `/profit-report/{slug}/` |
| Mount 點 | `#power_shop` | `#profit_partner_portal` |
| Entry | `js/src/main.tsx` → `App1.tsx` | `js/src/partner-portal/main.tsx` → `App.tsx` |
| Vite | multi-entry（`input: string[]`） | multi-entry，產出獨立 bundle |
| PHP 渲染 | `Admin\Entry::render_page()` | `PartnerPortalRenderer::maybe_render`（`template_redirect` priority 9） |
| Auth | WP nonce（`X-WP-Nonce`） | HTTP-only cookie + sessionStorage metadata |
| 框架 | Refine + 6 個 dataProvider | 純 axios + react-query（無 dataProvider） |
| Theme | 走 admin chrome | **完全脫離 theme**（不走 `get_header` / `wp_head` / `get_footer`，避免 admin bar / theme CSS / 其他 plugin 注入污染） |
| 隔離驗收 | — | `grep App1 \| Refine \| @refinedev` 0 命中 |

### Profit Shop Domain（4-layer DDD，詳見 `profit-shop.rule.md`）

```
inc/classes/Domains/ProfitShop/
│
├── Domain/                          # 純 PHP，禁依賴 WP / WC
│   ├── ValueObject/                 # PriceOverride / ProfitRate / PartnerSlug / InflatedCount / ShopMode / SlugConflict
│   ├── Entity/                      # OverrideItem / ProfitShop（status invariant）/ SettlementRecord
│   ├── Service/                     # PriceCalculator / ProfitCalculator / RoundingStrategy interface
│   ├── Repository/                  # ProfitShopRepository / PartnerRepository / SettlementRepository（Interface only）
│   ├── Snapshot/, Criteria/         # ProductSnapshot / PartnerSnapshot / FilterCriteria
│   └── Exception/                   # 18 個 final extends \DomainException（PartnerNotFound、SlugConflict、TooManyAttempts...）
│
├── Application/                     # 編排業務流程，依賴 Domain + Port interface
│   ├── DTO/                         # 13 個 immutable readonly DTO（PartnerInput / ProfitShopInput / KpiReport / SlugValidationOutput...）
│   ├── Service/                     # 13 個 Service：5 Port interface + 4 Service 實裝 + 4 Auth/Rate-limit/Crypto
│   │                                #   - Port: ProductLookup / SlugConflictLookup / TransientStore / Clock /
│   │                                #     EmailNotifier / SettlementSummaryProvider / SettingsRepository / ItemValidator /
│   │                                #     SaltProviderInterface（Phase 3-D，wp_salt 注入）
│   │                                #   - 實裝: ItemValidator / SlugConflictDetector / SettingsRepository /
│   │                                #     ProductSnapshotProvider / PartnerAuthService / PartnerTokenStore（Phase 3-D 升級：
│   │                                #     4-interface DI + hash_hmac + password_changed_at 撤銷）/
│   │                                #     LoginRateLimiter（Phase 3-D 升級：per-slug + per-IP 雙維度，IP hash_hmac）/
│   │                                #     CartPriceSignatureService（Phase 3-D 新增：HMAC-SHA256 cart 簽章）
│   └── UseCase/                     # 26 個 UseCase
│       ├── Shop/                    #   9 個（CRUD + publish/unpublish/duplicate + ValidateSlugUseCase[Phase 3-E]）
│       ├── Partner/                 #   5 admin CRUD + 4 Auth + 3 Report
│       ├── Migration/               #   2 個（list legacy / import）
│       └── Settings/                #   3 個（get / update / reset）
│
├── Infrastructure/                  # 唯一可呼叫 WP / WC API 的層
│   ├── Persistence/                 # CptProfitShopRepository / PartnerTermRepository（Phase 3-D：+get_password_changed_at）/
│   │                                #   OrderItemSettlementRepository / LegacyOnePageShopRepository /
│   │                                #   WpSettlementSummaryProvider（Phase 3-D：placeholder → 545 行真實 SQL，
│   │                                #     含 HPOS 雙路徑 / bcadd 精度 / IDOR partner_term_id 鎖死）
│   ├── WordPress/                   # CptRegistrar / TaxonomyRegistrar / RewriteRules /
│   │                                #   RewriteRulesFlusher / WpProductLookup / WpSlugConflictLookup /
│   │                                #   WpTransientStore / SystemClock / WpAdminEmailNotifier /
│   │                                #   WpSaltProvider（Phase 3-D，context 白名單防 silent salt rotation）
│   ├── WooCommerce/                 # Phase 3-D 新層：CartPriceOverrideHook（前台 cart 價格防竄改，
│   │                                #   add_cart_item_data + get_cart_item_from_session +
│   │                                #   before_calculate_totals priority 999 + draft shop fallback）
│   └── Rest/                        # ExceptionMapper（Domain Exception → HTTP，Phase 3-E：+filter test seam）+
│   │                                #   V2Api（26 endpoint：Phase 3-E +validate-slug）
│
└── Loader.php                       # ProfitShop sub-loader，由 Domains\Loader 註冊
```

依賴方向：`Presentation → Application → Domain ← Infrastructure`（Infrastructure 實作 Domain Repository / Application Port interface）。

---

## 插件初始化流程

```
plugin.php
  └→ Plugin::instance()                     # SingletonTrait 取得實例
      └→ PluginTrait::init()                # 設定常數、載入 autoloader
          └→ Bootstrap::instance()
              ├→ legacy/plugin.php           # 載入舊版一頁賣場程式碼（do NOT extend）
              ├→ Admin\Entry::instance()     # 註冊管理頁面
              │   ├→ add_action('admin_menu', ...) # 註冊 admin 頁面
              │   └→ add_action('admin_bar_menu', ...) # Admin bar「電商系統」
              └→ Domains\Loader::instance()  # 載入所有 Domain API
                  ├→ Report\Dashboard\Core\V2Api::instance() # 既有報表 REST API
                  └→ ProfitShop\Loader::instance()           # Profit Shop sub-loader
                      ├→ Infrastructure/WordPress/CptRegistrar / TaxonomyRegistrar / RewriteRules
                      ├→ Infrastructure/WordPress/PartnerPortalRenderer::instance() # Phase 4-B，partner portal mount 點
                      ├→ Infrastructure/WooCommerce/CartPriceOverrideHook::instance() # Phase 3-D，admin context guard
                      └→ Infrastructure/Rest/V2Api::instance() # 26 個 endpoint
```

### Partner Portal 載入流程（Phase 4-B）

```
HTTP request: /profit-report/{slug}/
  └→ WordPress query parsing → query var profit_partner_report = $slug
      └→ template_redirect priority 9（早於 theme 的 template_include）
          └→ PartnerPortalRenderer::maybe_render
              ├→ 雙保險 sanitize: query var → (string) cast → sanitize_title()
              ├→ get_term_by('slug', $slug, 'profit_partner')
              ├→ 不存在 → render_404() + status_header(404) → exit
              ├→ Vite manifest 缺 entry → render_maintenance() 503 + nocache_headers + exit（reviewer L-3 fallback）
              └→ 存在 → render_portal():
                  ├→ Vite\enqueue_asset(plugin_dir/js/dist, 'js/src/partner-portal/main.tsx')
                  ├→ wp_localize_script('power-shop-partner-portal', 'power_shop_partner_data', [...])
                  │   └→ env: SimpleEncrypt::encrypt({ SITE_URL, API_URL, KEBAB, SLUG })
                  │       （**無 nonce**，partner 不用 wp_rest nonce）
                  ├→ 輸出獨立 HTML 骨架（自己印 <!doctype html><html><head>...</head><body>）
                  │   ── 不走 get_header / wp_head / get_footer / wp_footer ──
                  │   ── 完全脫離 theme：避免 admin bar / theme CSS / 其他 plugin 注入污染 ──
                  └→ exit（防後續 hook 覆寫輸出）

partner-portal/main.tsx (DOMContentLoaded)
  └→ ReactDOM.createRoot(#profit_partner_portal)
      └→ <App />
          ├→ <QueryClientProvider>          # react-query（無 Refine）
          ├→ <ConfigProvider>               # Ant Design 主題
          ├→ <HashRouter>                   # 與 admin 一致，避免 BrowserRouter 與 RewriteRules 衝突
          └→ <AuthProvider>                 # status 三態 + 跨 partner 雙保險
              └→ <AuthGate>
                  ├→ guest → /login         # Login 頁（rate-limit 倒數）
                  └→ authenticated → /dashboard # Dashboard（KPI / TrendChart / SettlementsTable）
```

### 前端載入流程

```
Admin\Entry::render_page()
  └→ echo '<div id="power_shop"></div>'     # React mount point

Bootstrap::admin_enqueue_hook()
  └→ 判斷 General::in_url(['page=power-shop'])
      └→ Vite\enqueue_asset()              # 載入 JS/CSS（含 HMR dev 支援）
          └→ wp_localize_script()           # 注入 window.power_shop_data
              └→ env: SimpleEncrypt::encrypt() # 加密環境資訊

main.tsx (DOMContentLoaded)
  └→ ReactDOM.createRoot(#power_shop)
      └→ <App1 />
          ├→ <Refine dataProvider={...}>    # 6 個 Data Provider
          ├→ <HashRouter>                   # 路由系統
          └→ <ConfigProvider>               # Ant Design 主題
```

---

## 資料流架構

### PHP → React 環境變數

```
PHP (Bootstrap):
  PowerhouseUtils::simple_encrypt($env_array)
    → window.power_shop_data.env = '加密字串'

React (env.tsx):
  simpleDecrypt(window.power_shop_data.env)
    → { api_url, nonce, user_id, site_url, ... }

React (useEnv hook):
  const { api_url, nonce } = useEnv()
```

### React → PHP API 呼叫

```
React Component
  → useCustom / useTable / useForm (Refine hook)
    → dataProvider[key].getList / getOne / create / update
      → axios.get('/wp-json/{prefix}/{resource}', { headers: { 'X-WP-Nonce': nonce } })
        → WordPress REST API Router
          → V2Api callback (with sanitization)
            → WooCommerce / WordPress DB
              → WP_REST_Response
```

---

## Vite 配置

```typescript
// vite.config.ts
export default defineConfig({
  server: { port: 5178, cors: { origin: '*' } },
  plugins: [
    alias(), react(), tsconfigPaths(),
    v4wp({ input: 'js/src/main.tsx', outDir: 'js/dist' }),
  ],
  resolve: { alias: { '@': path.resolve(__dirname, 'js/src'), dayjs: 'dayjs' } },
})
```

- **開發**：`pnpm dev` → Vite dev server（HMR，port 5178）
- **正式**：`pnpm build` → `js/dist/` 靜態資源，PHP `Vite\enqueue_asset()` 讀取 manifest
