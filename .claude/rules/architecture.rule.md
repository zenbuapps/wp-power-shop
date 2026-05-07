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
│  │  │  │ (Refine)  │  │ (Plots)   │  │ (placeholder)│  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
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
│   ├── DTO/                         # 12 個 immutable readonly DTO（PartnerInput / ProfitShopInput / KpiReport...）
│   ├── Service/                     # 11 個 Service：4 Port interface + 4 Service 實裝 + 3 Auth/Rate-limit
│   │                                #   - Port: ProductLookup / SlugConflictLookup / TransientStore / Clock /
│   │                                #     EmailNotifier / SettlementSummaryProvider / SettingsRepository / ItemValidator
│   │                                #   - 實裝: ItemValidator / SlugConflictDetector / SettingsRepository /
│   │                                #     ProductSnapshotProvider / PartnerAuthService / PartnerTokenStore / LoginRateLimiter
│   └── UseCase/                     # 25 個 UseCase
│       ├── Shop/                    #   8 個（CRUD + publish/unpublish/duplicate）
│       ├── Partner/                 #   5 admin CRUD + 4 Auth + 3 Report
│       ├── Migration/               #   2 個（list legacy / import）
│       └── Settings/                #   3 個（get / update / reset）
│
├── Infrastructure/                  # 唯一可呼叫 WP / WC API 的層
│   ├── Persistence/                 # CptProfitShopRepository / PartnerTermRepository /
│   │                                #   OrderItemSettlementRepository / LegacyOnePageShopRepository /
│   │                                #   WpSettlementSummaryProvider
│   ├── WordPress/                   # CptRegistrar / TaxonomyRegistrar / RewriteRules /
│   │                                #   RewriteRulesFlusher / WpProductLookup / WpSlugConflictLookup /
│   │                                #   WpTransientStore / SystemClock / WpAdminEmailNotifier
│   └── Rest/                        # ExceptionMapper（Domain Exception → HTTP）+ V2Api（25 endpoint）
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
                      └→ Infrastructure/Rest/V2Api::instance() # 25 個 endpoint
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
