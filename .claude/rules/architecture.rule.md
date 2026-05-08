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

### 賣場前台（Phase 4-C，PHP page template + theme 整合，customer-facing）

```
┌──────────────────────────────────────────────────────────────┐
│             WordPress Frontend（非 admin，customer-facing）   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │  /profit-shop/{slug}/  （RewriteRules + query var       │ │
│  │                          profit_shop_slug，priority top） │ │
│  │  ┌───────────────────────────────────────────────────┐  │ │
│  │  │   ProfitShopRenderer（template_redirect prio 9）   │  │ │
│  │  │   ├─ DI ProfitShopRepositoryInterface             │  │ │
│  │  │   ├─ find_by_slug → status check                  │  │ │
│  │  │   │    publish: 所有人可見                          │  │ │
│  │  │   │    draft : edit_post($id) 才允許預覽            │  │ │
│  │  │   │    trashed/不存在: 404                          │  │ │
│  │  │   ├─ wp_head SEO meta 注入：                        │  │ │
│  │  │   │   <title> / canonical / 防雙 canonical 雙 title │  │ │
│  │  │   └─ get_header() → template → get_footer()        │  │ │
│  │  │      （走 theme，與 partner portal 完全脫離 theme 不同）│
│  │  └───────────────────────────────────────────────────┘  │ │
│  │  ┌───────────────────────────────────────────────────┐  │ │
│  │  │  templates/profit-shop-front.php（純 PHP，無 React） │  │ │
│  │  │   ├─ 商品 grid（responsive 240px auto-fill）        │  │ │
│  │  │   ├─ simple → form action=home_url('/') + hidden    │  │ │
│  │  │   │   <input name="profit_shop_id" value="...">     │  │ │
│  │  │   ├─ variable / grouped → permalink                 │  │ │
│  │  │   │   + add_query_arg('profit_shop_id', ...)        │  │ │
│  │  │   ├─ 原價 <del> + 紅字特價 <ins>（WC 慣例）          │  │ │
│  │  │   └─ esc_html / esc_attr / esc_url 全 escape        │  │ │
│  │  └───────────────────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│           customer 點「加入購物車」                            │
│                           │                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │   WC add-to-cart 流程 + 4 個 hook 串聯（priority 序）    │ │
│  │   ┌──────────────────────────────────────────────────┐ │ │
│  │   │ ① AddToCartHook（4-C2，priority 5）              │ │ │
│  │   │   - 從 $_GET + $_POST 取 profit_shop_id           │ │ │
│  │   │     （排除 $_COOKIE 攻擊面）                      │ │ │
│  │   │   - 三道閘門縱深防偽造：                            │ │ │
│  │   │     (1) shop 存在 (2) status=publish              │ │ │
│  │   │     (3) product_id ∈ shop->items() O(1) lookup    │ │ │
│  │   │   - short-circuit：上游已帶 → return（防 IT regr） │ │ │
│  │   │   - ctype_digit 嚴格純數字檢查                      │ │ │
│  │   │   - 注入 cart_item_data['profit_shop_id']         │ │ │
│  │   ├──────────────────────────────────────────────────┤ │ │
│  │   │ ② CartPriceOverrideHook::add_cart_item_data       │ │ │
│  │   │     （3-D，priority 10）                          │ │ │
│  │   │   - 寫 4 筆 _profit_* meta + HMAC SHA-256 簽章    │ │ │
│  │   │   - status='publish' 限定（draft 不注入）         │ │ │
│  │   ├──────────────────────────────────────────────────┤ │ │
│  │   │ ③ CartItemMetaDisplay（4-C2）                     │ │ │
│  │   │   woocommerce_get_item_data filter               │ │ │
│  │   │   - 一個 filter 同時涵蓋：                         │ │ │
│  │   │     mini-cart / cart page / checkout             │ │ │
│  │   │   - 顯示「來自賣場 XXX」連結（target=_blank        │ │ │
│  │   │     rel=noopener，shop 已刪靜默不顯示）            │ │ │
│  │   ├──────────────────────────────────────────────────┤ │ │
│  │   │ ④ CartPriceOverrideHook::before_calculate_totals  │ │ │
│  │   │     （3-D，priority 999）                         │ │ │
│  │   │   - 結帳前 hash_equals 驗章 → set_price           │ │ │
│  │   │   - 驗證失敗 fallback 原價                          │ │ │
│  │   └──────────────────────────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│            訂單成立 → OrderItemSettlementRepository           │
│                  寫 partner 分潤紀錄                          │
└──────────────────────────────────────────────────────────────┘
```

**賣場前台 vs Partner Portal 對比（兩個前台 URL 路線完全不同）：**

| 維度 | 賣場前台（4-C） | Partner Portal SPA（4-B） |
|------|----------------|---------------------------|
| URL | `/profit-shop/{slug}/`（customer-facing） | `/profit-report/{slug}/`（partner-facing） |
| Query var | `profit_shop_slug` | `profit_partner_report` |
| 對象 | 終端買家（unauthenticated） | partner 本人（cookie auth） |
| 渲染 | PHP page template + `get_header` / `get_footer`（**走 theme**） | 獨立 HTML 骨架（**不走 theme**） |
| 框架 | 純 PHP（無 React、無 Tailwind） | React SPA + axios + react-query |
| SEO | `<title>` / canonical 注入（防 plugin 衝突） | 無 SEO 需求（partner-only） |
| 加購流程 | `?profit_shop_id={id}` query string + WC hook 串聯 | 無加購（純報表） |
| 為何分流 | customer-facing + SEO + 不破壞 WC cart 流程 | partner-only + 完整 SPA 體驗 + 隔離 admin bar / theme CSS |

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
│   ├── ValueObject/PartnerPassword # Phase 6-A1，Partner 自助修密碼複雜度 VO（mb_strlen UTF-8 ≥ 8 + ≥ 1 英文 + ≥ 1 數字）
│   └── Exception/                   # 19 個 final extends \DomainException（PartnerNotFound、SlugConflict、TooManyAttempts、WeakPassword[Phase 6-A1]...）
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
│   └── UseCase/                     # 27 個 UseCase
│       ├── Shop/                    #   9 個（CRUD + publish/unpublish/duplicate + ValidateSlugUseCase[Phase 3-E]）
│       ├── Partner/                 #   5 admin CRUD + 5 Auth（含 ChangePasswordUseCase[Phase 6-A1]）+ 3 Report
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
│   └── Rest/                        # ExceptionMapper（Domain Exception → HTTP，Phase 3-E：+filter test seam；
│   │                                #   Phase 6-A1：+WeakPassword → 422 weak_password + data.reasons[]）+
│   │                                #   V2Api（27 endpoint：Phase 3-E +validate-slug、Phase 6-A1 +partner-auth/change-password）
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
                      ├→ Infrastructure/WordPress/ProfitShopRenderer::instance(...) # Phase 4-C1，賣場前台 PHP renderer，DI ProfitShopRepositoryInterface
                      ├→ Infrastructure/WooCommerce/AddToCartHook::instance(...)   # Phase 4-C2，priority 5（早於 CartPriceOverrideHook 的 10），DI ProfitShopRepositoryInterface
                      ├→ Infrastructure/WooCommerce/CartPriceOverrideHook::instance() # Phase 3-D，priority 10 / 999，admin context guard
                      ├→ Infrastructure/WooCommerce/CartItemMetaDisplay::instance(...) # Phase 4-C2，woocommerce_get_item_data filter，涵蓋 mini-cart / cart / checkout
                      └→ Infrastructure/Rest/V2Api::instance() # 27 個 endpoint（Phase 6-A1 +partner-auth/change-password）
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

### 賣場前台載入流程（Phase 4-C）

```
HTTP request: /profit-shop/{slug}/
  └→ WordPress query parsing → query var profit_shop_slug = $slug
      └→ template_redirect priority 9（與 PartnerPortalRenderer 同層；query var 互斥不衝突）
          └→ ProfitShopRenderer::maybe_render
              ├→ 雙保險 sanitize: query var → (string) cast → sanitize_title()
              ├→ ProfitShopRepositoryInterface::find_by_slug($slug)
              │   └→ CptProfitShopRepository: get_posts ['name'=>$slug,
              │      'post_type'=>'powershop','post_status'=>['publish','draft']]
              │      + 雙保險 trash 過濾
              ├→ 不存在 / trashed → 404 → exit
              ├→ status='draft' → current_user_can('edit_post', $shop->id) 才允許預覽
              │                    （二輪修 HIGH-1：從 edit_posts 收緊到對特定 post id ownership）
              ├→ status='publish' → 對外可見
              └→ render_shop():
                  ├→ remove_action('wp_head', 'rel_canonical')         # 防雙 canonical
                  ├→ remove_action('wp_head', '_wp_render_title_tag', 1) # 防 theme 雙 title
                  ├→ wp_head SEO meta 注入（add_action 'wp_head'）：
                  │   ├→ <title>{shop_name}｜{partner_name} 的分潤賣場 - {site_name}</title>
                  │   └→ <link rel='canonical' href='{site_url}/profit-shop/{slug}/'>
                  ├→ get_header()                                       # 走 theme（與 partner portal 不同！）
                  ├→ require templates/profit-shop-front.php            # 純 PHP 商品 grid
                  │   └→ foreach OverrideItem：
                  │       ├→ simple → form action='/' + add-to-cart + profit_shop_id hidden input
                  │       └→ variable / grouped → permalink + add_query_arg('profit_shop_id', ...)
                  └→ get_footer()
```

### Add-to-Cart 端到端流程（Phase 4-C2 串聯 Phase 3-D）

```
customer 點 simple product 加購（form submit 帶 profit_shop_id）
   或點 variable / grouped 商品（連結帶 ?profit_shop_id={id}）
      │
      ▼
WC add-to-cart 流程
      │
      ▼
filter: woocommerce_add_cart_item_data
   ┌─────────────────────────────────────────────────┐
   │ Priority 5：AddToCartHook（4-C2）                │
   │   ├─ short-circuit：上游已帶 profit_shop_id → return  │
   │   │   （防 Phase 3-D IT 直接呼 hook 的回歸）          │
   │   ├─ 從 $_GET + $_POST 取（排除 $_COOKIE 攻擊面）      │
   │   ├─ ctype_digit 嚴格檢查                          │
   │   ├─ ProfitShopRepositoryInterface::find($shop_id) │
   │   ├─ 三道閘門縱深防偽造：                            │
   │   │   ① $shop !== null                            │
   │   │   ② $shop->status() === 'publish'             │
   │   │   ③ product_id ∈ array_map(items, fn=>id)     │
   │   └─ 注入 cart_item_data['profit_shop_id'] = $id   │
   ├─────────────────────────────────────────────────┤
   │ Priority 10：CartPriceOverrideHook (3-D)          │
   │   ├─ 寫 4 筆 _profit_* meta（shop_id / item_id /  │
   │   │     overridden_price / partner_term_id）       │
   │   └─ HMAC SHA-256 簽章 cart_item_data['_sign']     │
   │     （wp_salt('auth') + cart-signature:v1 prefix）│
   └─────────────────────────────────────────────────┘
      │
      ▼
filter: woocommerce_get_item_data（顯示用，不影響定價）
   ┌─────────────────────────────────────────────────┐
   │ CartItemMetaDisplay（4-C2）                      │
   │   ├─ 一個 filter 同時涵蓋 mini-cart / cart /       │
   │   │     checkout（不需重複 hook）                  │
   │   ├─ shop 已刪 → 靜默不顯示（cart 仍可結帳）        │
   │   └─ 顯示「來自賣場 XXX」連結                       │
   │       ├─ esc_url(home_url('/' . SHOP_REWRITE_PREFIX  │
   │       │       . '/' . $slug . '/'))                │
   │       │   （DRY：用 RewriteRules::SHOP_REWRITE_PREFIX）│
   │       └─ target='_blank' rel='noopener'            │
   └─────────────────────────────────────────────────┘
      │
      ▼
filter: woocommerce_before_calculate_totals
   ┌─────────────────────────────────────────────────┐
   │ Priority 999：CartPriceOverrideHook (3-D)         │
   │   ├─ hash_equals 驗章                              │
   │   ├─ 驗證 OK → $cart_item['data']->set_price(...)  │
   │   └─ 驗證失敗 → 靜默 fallback 原價（商家無風險）     │
   └─────────────────────────────────────────────────┘
      │
      ▼
checkout / 訂單成立
      │
      ▼
OrderItemSettlementRepository::store(...)
   寫 wc_*_order_item_meta：
   _profit_partner_term_id / _profit_shop_id / _profit_overridden_price 等
   （HPOS 雙路徑相容）
```

**4 道攻擊情境驗證（4-C2 security reviewer 雙審 PASS）：**

| 情境 | 防禦點 |
|------|--------|
| 偽造任意 shop_id | `find_by_id` 回 null → AddToCartHook return unchanged |
| 偽造 product_id 不在 shop | 第三閘門 `product_id ∈ shop->items()` 拒絕 |
| 拿正版 publish shop 套未授權商品 | 第三閘門 同上 |
| cart session 後手動改 meta | 兩 filter 同 request 同步串接，priority 999 驗章失敗 fallback 原價 |

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
