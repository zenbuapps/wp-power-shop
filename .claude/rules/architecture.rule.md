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
│  │  │  │ (Refine)  │  │ (Plots)   │  │ (純選單分組， │  │  │ │
│  │  │  │           │  │           │  │  無自有頁面) │  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
│  │  │  ── 商家後台 Profit Shop CRUD UI（Phase 4-A）──  │  │ │
│  │  │  ┌───────────┐  ┌───────────┐  ┌──────────────┐  │  │ │
│  │  │  │ProfitShop │  │ ProfitPa- │  │ProfitMigra-  │  │  │ │
│  │  │  │  (Refine) │  │   rtner   │  │     tion     │  │  │ │
│  │  │  │           │  │ (Refine + │  │ (Refine +    │  │  │ │
│  │  │  │           │  │  HIGH-RISK│  │  HIGH-RISK   │  │  │ │
│  │  │  │           │  │  PW Reset)│  │  Import)     │  │  │ │
│  │  │  └───────────┘  └───────────┘  └──────────────┘  │  │ │
│  │  │  （ProfitSettings 全域設定頁已整組移除——4 個欄位  │  │ │
│  │  │    不是零消費點，就是「站長改了只會弄壞東西」）    │  │ │
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

### Partner self-service portal（Phase 4-B + 6-A2，獨立 SPA）

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
│  │  │  │  limit │  │  cards + │  │  callback ref) │   │  │ │
│  │  │  │  倒數  │  │ 「修改   │  │                │   │  │ │
│  │  │  │ + ?reas│  │  密碼」  │  │                │   │  │ │
│  │  │  │ on 6-A2│  │  link)   │  │                │   │  │ │
│  │  │  └────────┘  └──────────┘  └────────────────┘   │  │ │
│  │  │  ┌────────────────────────────────────────────┐  │  │ │
│  │  │  │      SettlementsTable                       │  │  │ │
│  │  │  │   (desktop / mobile 響應式)                 │  │  │ │
│  │  │  └────────────────────────────────────────────┘  │  │ │
│  │  │  ┌────────────────────────────────────────────┐  │  │ │
│  │  │  │   ChangePassword (6-A2 ⚠ HIGH-RISK)         │  │  │ │
│  │  │  │   - 三欄位 Form + Form rule + cooldown      │  │  │ │
│  │  │  │   - success state lock + setTimeout cleanup │  │  │ │
│  │  │  │   - 成功 → forceLogoutAndRedirect          │  │  │ │
│  │  │  │     ('password_changed')                    │  │  │ │
│  │  │  └────────────────────────────────────────────┘  │  │ │
│  │  └───────────────────────────────────────────────────┘  │ │
│  │       HashRouter + AuthContext + axios apiClient        │ │
│  │       + forceLogoutAndRedirect helper（6-A2）           │ │
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
│  │  │  templates/profit-shop-front.php（純 PHP + daisyUI +     │  │ │
│  │  │  vanilla JS，Phase 14 起無 React）                  │  │ │
│  │  │   ├─ 商品 grid（responsive 240px auto-fill）        │  │ │
│  │  │   ├─ 每商品一個 <dialog class="pc-modal">           │  │ │
│  │  │   │   （partials/profit-shop-modal.php，PHP 印出）  │  │ │
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
│  │   │ ③（Phase 15 移除）CartItemMetaDisplay             │ │ │
│  │   │   前台不再顯示「來自賣場 XXX」——站長要求。         │ │ │
│  │   │   它是純顯示 filter，移除不影響定價與結算。         │ │ │
│  │   ├──────────────────────────────────────────────────┤ │ │
│  │   │ ④ CartPriceOverrideHook::before_calculate_totals  │ │ │
│  │   │     （3-D，priority 999）                         │ │ │
│  │   │   - 結帳前 hash_equals 驗章 → set_price           │ │ │
│  │   │   - 驗證失敗 fallback 原價                          │ │ │
│  │   └──────────────────────────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│      顧客按下結帳（classic 或 Store API / Block 皆同一路徑）    │
│                           │                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ ⑤ OrderLineItemSettlementHook（Phase 17，priority 20）   │ │
│  │     woocommerce_checkout_create_order_line_item          │ │
│  │   - 獨立重新驗章（委派 CartProfitMetaVerifier，不假設    │ │
│  │     ④ 已經驗過——請求流程順序不是隔離邊界）              │ │
│  │   - 驗章通過 → 讀賣場當下 rate → 算 _profit_amount →     │ │
│  │     OrderItemSettlementRepository::save_to_item()        │ │
│  │     （item id 此刻恆為 0，用 add_meta_data($unique=true)）│ │
│  │   - 寫入 11 筆 order itemmeta，_settlement_status=pending │ │
│  └─────────────────────────────────────────────────────────┘ │
│                           │                                   │
│            訂單成立 → OrderItemSettlementRepository           │
│                  寫 partner 分潤紀錄                          │
│      （完整資料流、meta 命名對映見 profit-shop.rule.md §13）  │
└──────────────────────────────────────────────────────────────┘
```

**賣場前台 vs Partner Portal 對比（兩個前台 URL 路線完全不同）：**

| 維度 | 賣場前台（4-C + Phase 10） | Partner Portal SPA（4-B） |
|------|----------------|---------------------------|
| URL | `/profit-shop/{slug}/`（customer-facing） | `/profit-report/{slug}/`（partner-facing） |
| Query var | `profit_shop_slug` | `profit_partner_report` |
| 對象 | 終端買家（unauthenticated） | partner 本人（cookie auth） |
| 渲染 | PHP page template + `get_header` / `get_footer`（**走 theme**） | 獨立 HTML 骨架（**不走 theme**） |
| 框架 | **PHP + daisyUI + 手寫 vanilla JS**（Phase 14 起）：商品 grid 與商品 Modal（`<dialog>`）全由 PHP 印出，JS 只負責開 Modal / Gallery / 展開收合 / 數量 / 變體選擇；Phase 10-13 曾是「PHP 印內容 + React/antd 掛互動層」的混合式，已整條移除 | React SPA + axios + react-query |
| SEO | `<title>` / canonical 注入（防 plugin 衝突）+ 商品名稱／價格留在 HTML | 無 SEO 需求（partner-only） |
| 加購流程 | Modal → **WooCommerce 原生表單 POST** → WC hook 串聯（JS 停用時降級為 query string） | 無加購（純報表） |
| 為何分流 | customer-facing + SEO + 不破壞 WC cart 流程 | partner-only + 完整 SPA 體驗 + 隔離 admin bar / theme CSS |

### 賣場前台的架構演進（Phase 4-C 純 PHP → Phase 10-13 PHP + React 混合式 → Phase 14 PHP + daisyUI + vanilla JS）

**Phase 4-C 的「純 PHP、無 React」在 Phase 10 演進為混合式**（PHP 印內容 + React/antd 掛互動層），
**Phase 14 又把 React/antd 整條移除**，回到「純 PHP」——但這次連互動層（商品 Modal、Gallery、
變體選擇）也一併由 PHP 印出，只用一支**手寫 vanilla JS**（不進 Vite build）補上跟伺服器渲染
HTML 互動所需的行為。三個階段共同的原則沒有變：Pitfall #12 訂下「賣場前台走 PHP template」
的三個理由（SEO / 不破壞 WC cart / customer-facing）指的是**內容渲染**，不是「禁止任何 JS」；
現行 vanilla JS 與曾經的 React 一樣，只負責互動，不負責產出 SEO 內容。

```
PHP（ProfitShopRenderer + template + partials）      vanilla JS（inc/assets/js/profit-shop-front.js）
├─ 商品 grid（名稱／價格／圖片留在 HTML → SEO）     ├─ 開 Modal（<dialog>.showModal()）
├─ 每商品一個 <dialog class="pc-modal">  ──────────┤   Gallery 縮圖切換 / 描述展開收合
│   （partials/profit-shop-modal.php 印出）         │   數量 -/+ / 變體選擇（重寫 hidden input）
├─ [data-ps-product-id] / [data-ps-modal-for]       │
│   ← DOM 契約（見 CLAUDE.md Pitfall #23）──────────┤
├─ .ps-profit-shop-front[data-ps-shop]  ← 多賣場邊界┤   （JS 查找一律限定在最近的這個祖先內）
├─ <script class="ps-variations-data">（每 Modal 各自一份，取代全域 payload）
└─ 卡片只印一個動作元素（漸進增強，無 JS 即可加購）
```

**職責分界的三條紀律（Phase 14 沿用，只是「互動層」從 React 換成 vanilla JS）：**

| # | 紀律 | 為什麼 |
|---|------|--------|
| 1 | 商品的**名稱／價格／圖片**必須留在 PHP 印出的 HTML 內 | 那是 SEO 的全部價值；Modal 只是同一份資料的互動呈現 |
| 2 | `data-ps-*` 屬性群是**跨語言 DOM 契約**（完整清單見 CLAUDE.md Pitfall #23） | 改 PHP 的 class 或 attribute 會讓 JS 靜默失效（Modal 開不起來、Gallery / 數量 / 變體互動失效），且不會有任何錯誤訊息 |
| 3 | 加購一律經過 `woocommerce_add_cart_item_data` filter 鏈 | `AddToCartHook`(5) 的三道閘門 + `CartPriceOverrideHook`(10/999) 的 HMAC 簽章是分潤價格的唯一守門；自建 endpoint 直接塞 `cart_item_data['profit_shop_id']` 會**跳過全部閘門** |

**加購通道相容性**（`AddToCartHook::extract_shop_id_from_request()` 只讀 `$_GET` / `$_POST`）：

| 通道 | 三閘門 | 說明 |
|------|--------|------|
| GET `?add-to-cart=X&profit_shop_id=Y` | ✅ | Phase 4-C1 原路徑，variable / grouped 商品的卡片連結、JS 停用時的降級路徑皆走這條 |
| **`?wc-ajax=add_to_cart`（form-urlencoded POST）** | ✅ | **Phase 15 起商品 Modal 的預設路徑**：WooCommerce 原生 AJAX 端點，power-shop 不寫任何 handler。form-urlencoded → PHP 正常填 `$_POST`，**不需要橋接**。只送 `product_id` / `quantity` / `profit_shop_id`；variable 商品送的是**選中變體的 ID**，`WC_AJAX::add_to_cart()` 會先換算成父商品 ID 與 `attribute_*`，**換算之後**才觸發 `woocommerce_add_cart_item_data`，因此第三閘門的 `has_item($product_id)` 仍然對得上。無 nonce（`nopriv` 且原始碼明文停用 nonce 檢查），未登入訪客可用 |
| **POST form（hidden input，PHP 直接印出）** | ✅ | **Phase 14 起商品卡片與商品 Modal 都走這條**：`<form method="post">` 由 PHP 直接印在 HTML 內（不是動態建立），交給 `WC_Form_Handler::add_to_cart_action()`。PHP 正常填 `$_POST`，**不需要任何橋接**；vanilla JS 只在使用者選定變體後重寫 `variation_id` / `attribute_*` hidden input 的值 |
| ~~admin-ajax（`FrontAjax`，Phase 10 採用）~~ | ✅ | **Phase 11 已移除**。原本 `$_POST` 分支天然帶 `profit_shop_id`，handler 只呼叫 `WC()->cart->add_to_cart()`、不自帶 `cart_item_data` |
| `fetch` / axios + `Content-Type: application/json`（不橋接） | ❌ | PHP 不填 `$_POST` → shop_id 取不到 → 靜默走原價 |
| ~~自訂 REST（`FrontCartApi`，Phase 11 採用）~~ | ✅ | **Phase 12 已刪檔**。它送 JSON body（PHP 不會填 `$_POST`），靠 `add_to_cart_with_shop_context()` 在呼叫 `add_to_cart()` **前**主動橋接 `$_POST`、呼叫後（`finally`）還原，才通得過三道閘門 |
| WC Store API `cart/add-item` | ❌ | payload 在 JSON body，且未橋接 `$_POST`（Phase 11 起已無消費者） |

⚠ **Phase 12 起前台完全沒有購物車 API**：加購一律走 WooCommerce 原生表單 / query string，
購物車與結帳交給 WooCommerce 自己的頁面。這也讓「JSON body 不會填 `$_POST`」這個坑
自然消失——退回原生表單後不需要任何補償手段。**Phase 14 起前台也完全沒有任何 React / antd
程式碼**，`profit-shop-front.js` 是不經 Vite 打包的手寫 vanilla JS。

若未來又要為前台補上非同步加購，記住三閘門只認 `$_GET` / `$_POST`：新 endpoint 必須**自己**
在呼叫 `add_to_cart()` 前把 `profit_shop_id` 橋接進 `$_POST`（Phase 11 的做法），或走既有的
GET / POST form 通道。**絕不能**改用 `add_to_cart()` 第 5 個參數 `$cart_item_data` 傳值——
`AddToCartHook` 開頭的 short-circuit 會讓三道閘門全部被跳過。

### Profit Shop Domain（4-layer DDD，詳見 `profit-shop.rule.md`）

```
inc/classes/Domains/ProfitShop/
│
├── Domain/                          # 純 PHP，禁依賴 WP / WC
│   ├── ValueObject/                 # PriceOverride / ProfitRate / PartnerSlug / InflatedCount / ShopMode / SlugConflict /
│   │                                #   VariationOverride（Phase 9，PriceOverride + InflatedCount 聚合，OverrideItem::$variations 的 value）
│   ├── Entity/                      # OverrideItem / ProfitShop（status invariant）/ SettlementRecord
│   ├── Service/                     # PriceCalculator / ProfitCalculator / RoundingStrategy interface
│   ├── Repository/                  # ProfitShopRepository / PartnerRepository / SettlementRepository（Interface only）
│   ├── Snapshot/, Criteria/         # ProductSnapshot / PartnerSnapshot / FilterCriteria
│   ├── ValueObject/PartnerPassword # Phase 6-A1，Partner 自助修密碼複雜度 VO（mb_strlen UTF-8 ≥ 8 + ≥ 1 英文 + ≥ 1 數字）
│   └── Exception/                   # 19 個 final extends \DomainException（PartnerNotFound、SlugConflict、TooManyAttempts、WeakPassword[Phase 6-A1]...）
│
├── Application/                     # 編排業務流程，依賴 Domain + Port interface
│   ├── DTO/                         # 13 個 immutable readonly DTO（PartnerInput / ProfitShopInput / KpiReport / SlugValidationOutput...）
│   ├── Service/                     # 11 個 Service：4 Port interface + 3 Service 實裝 + 4 Auth/Rate-limit/Crypto
│   │                                #   - Port: ProductLookup / SlugConflictLookup / TransientStore / Clock /
│   │                                #     EmailNotifier / SettlementSummaryProvider / ItemValidator /
│   │                                #     SaltProviderInterface（Phase 3-D，wp_salt 注入）
│   │                                #   - 實裝: ItemValidator / SlugConflictDetector /
│   │                                #     ProductSnapshotProvider / PartnerAuthService / PartnerTokenStore（Phase 3-D 升級：
│   │                                #     4-interface DI + hash_hmac + password_changed_at 撤銷）/
│   │                                #     LoginRateLimiter（Phase 3-D 升級：per-slug + per-IP 雙維度，IP hash_hmac）/
│   │                                #     CartPriceSignatureService（Phase 3-D 新增：HMAC-SHA256 cart 簽章）
│   └── UseCase/                     # 24 個 UseCase + Settlement/ 7 個（Phase 17）
│       ├── Shop/                    #   9 個（CRUD + publish/unpublish/duplicate + ValidateSlugUseCase[Phase 3-E]）
│       ├── Partner/                 #   5 admin CRUD + 5 Auth（含 ChangePasswordUseCase[Phase 6-A1]）+ 3 Report
│       ├── Migration/               #   2 個（list legacy / import）
│       └── Settlement/              #   （Phase 17）ListSettlementsUseCase / GetSettlementSummaryUseCase /
│                                    #   MarkSettlementsPaidUseCase / MarkSettlementsCancelledUseCase /
│                                    #   ReversePaidSettlementsUseCase / ExportSettlementsUseCase /
│                                    #   ListSettlementOrderIdsUseCase（D-6）+ Support/SettlementBatchGuard
│                                    #   （Settings/ 3 個已整組移除，見 profit-shop.rule.md §7）
│
├── Infrastructure/                  # 唯一可呼叫 WP / WC API 的層
│   ├── Persistence/                 # CptProfitShopRepository / PartnerTermRepository（Phase 3-D：+get_password_changed_at）/
│   │                                #   OrderItemSettlementRepository（Phase 17：+save_to_item/compare_and_set
│   │                                #     系呼叫下放給 WpSettlementStatusMutator/update_profit_amount/
│   │                                #     flag_refund_after_paid，ORDER_POST_TYPE public 常數）/
│   │                                #   LegacyOnePageShopRepository /
│   │                                #   WpSettlementSummaryProvider（Phase 3-D：placeholder → 545 行真實 SQL，
│   │                                #     含 HPOS 雙路徑 / bcadd 精度 / IDOR partner_term_id 鎖死；
│   │                                #     Phase 17：三段 SQL 補訂單型別過濾，見 profit-shop.rule.md §13.3 F-3）/
│   │                                #   WpAdminSettlementQuery（Phase 17，後台結算列表 / 摘要 / 匯出 / order-ids，
│   │                                #     刻意獨立於 WpSettlementSummaryProvider，見 §13.3）/
│   │                                #   WpSettlementStatusMutator（Phase 17，CAS 條件式狀態更新）/
│   │                                #   PartnerSettledLogRepository（Phase 17，撥款 / 沖回 / 退款稽核事件）
│   ├── WordPress/                   # CptRegistrar / TaxonomyRegistrar / RewriteRules（含 REWRITE_VERSION
│   │                                #     版本比對式 flush，掛 init 自動觸發）/
│   │                                #   WpProductLookup / WpSlugConflictLookup /
│   │                                #   WpTransientStore / SystemClock / WpAdminEmailNotifier /
│   │                                #   WpSaltProvider（Phase 3-D，context 白名單防 silent salt rotation）/
│   │                                #   ProfitShopRenderer / ProfitShopShortcode / PartnerPortalRenderer /
│   │                                #   ProfitShopFrontData（賣場前台 payload）/ ProfitShopFrontView
│   │                                #   （Phase 14 新增，呈現層工具：currency / price_range / icon 等，
│   │                                #     見 profit-shop.rule.md §12.8）+ templates/、templates/partials/
│   ├── WooCommerce/                 # AddToCartHook（Phase 4-C2，priority 5，注入 profit_shop_id，
│   │                                #   三道閘門防偽造）/ CartPriceOverrideHook（Phase 3-D，前台 cart
│   │                                #   價格防竄改，add_cart_item_data + get_cart_item_from_session +
│   │                                #   before_calculate_totals priority 999 + draft shop fallback）/
│   │                                #   （CartItemMetaDisplay 已於 Phase 15 刪檔——前台不顯示分潤標記）/
│   │                                #   （Phase 9，WC 單品頁套用賣場覆寫價含變體，template_redirect
│   │                                #   priority 20，改寫 variation prices hash 隔離跨賣場快取污染）/
│   │                                #   CartProfitMetaVerifier + VerifiedCartProfitMeta（Phase 17，
│   │                                #   消滅孿生驗章，cart hook 與結帳 hook 共用同一份簽章驗證）/
│   │                                #   WcDecimalRounding + MoneyFormatter（Phase 17，F-2 補洞：
│   │                                #   RoundingStrategy 第一個 production 實作）/
│   │                                #   OrderLineItemSettlementHook（Phase 17，priority 20，
│   │                                #   woocommerce_checkout_create_order_line_item，結帳寫入 11 筆
│   │                                #   order itemmeta）/ SettlementReversalHook（Phase 17，退款 /
│   │                                #   取消回沖）/ RefundAfterPaidNotice（Phase 17，已撥款後退款的
│   │                                #   後台待辦佇列）/ RenewalProfitHook（Phase 17，priority 20，
│   │                                #   wcs_renewal_order_created **filter**，訂閱續訂帶入，
│   │                                #   WooCommerce Subscriptions 未安裝時整段不註冊）
│   └── Rest/                        # ExceptionMapper（Domain Exception → HTTP，Phase 3-E：+filter test seam；
│   │                                #   Phase 6-A1：+WeakPassword → 422 weak_password + data.reasons[]）+
│   │                                #   V2Api（34 endpoint：Phase 3-E +validate-slug、Phase 6-A1
│   │                                #   +partner-auth/change-password、Phase 17 +profit-settlements
│   │                                #   系 7 個 endpoint，見 wordpress.rule.md）
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
                      ├→ PageRateLimitService + WpClientIpProvider::instance() # Phase 5-A.1，前台頁面 rate-limit 共用基礎設施
                      ├→ Infrastructure/WordPress/PartnerPortalRenderer::instance(...) # Phase 4-B，partner portal mount 點；Phase 5-A.1 起注入 rate-limit + ip provider
                      ├→ Infrastructure/WordPress/ProfitShopRenderer::instance(...) # Phase 4-C1，賣場前台 PHP renderer，DI ProfitShopRepositoryInterface；Phase 5-A.1 起注入 rate-limit + ip provider
                      ├→ Infrastructure/WordPress/ProfitShopShortcode::instance(...) # TD-13（前半），[power_shop_profit_shop id="..."]，與 ProfitShopRenderer 共用同一份 template（Phase 14 起兩條路徑渲染逐字相同的 DOM，外層一律 <div>、不注入 SEO meta）
                      ├→ Infrastructure/Rest/V2Api::instance() # 34 個 endpoint（Phase 6-A1 +partner-auth/change-password、Phase 17 +profit-settlements 系 7 個）
                      │  （Phase 11 的 FrontCartApi 已於 Phase 12 刪檔——前台改走 WooCommerce 原生表單加購，不再有自訂購物車 REST）
                      ├→ Infrastructure/WooCommerce/AddToCartHook::instance(...)   # Phase 4-C2，priority 5（早於 CartPriceOverrideHook 的 10），DI ProfitShopRepositoryInterface
                      ├→ new OverridePriceResolver() / new CartPriceSignatureService(...)         # Phase 13，cart hook 與結帳 hook 共用實例
                      ├→ Infrastructure/WooCommerce/CartPriceOverrideHook::instance() # Phase 3-D，priority 10 / 999，admin context guard
                      ├→ Infrastructure/WooCommerce/SingleProductPriceHook::instance(...) # Phase 9，template_redirect priority 20，WC 單品頁套用賣場覆寫價（含變體），DI ProfitShopRepositoryInterface
                      ├→ new WcDecimalRounding() / new ProfitCalculator($rounding) / new MoneyFormatter($rounding)  # Phase 17，F-2 補洞，供結帳寫入與退款/續訂 hook 共用
                      ├→ Infrastructure/WooCommerce/OrderLineItemSettlementHook::instance(...)     # Phase 17，priority 20，woocommerce_checkout_create_order_line_item，結帳寫入分潤 order itemmeta
                      ├→ Infrastructure/WooCommerce/SettlementReversalHook::instance(...)          # Phase 17，priority 10，woocommerce_order_refunded / woocommerce_order_status_cancelled，退款/取消回沖
                      └→ RenewalProfitHook::is_supported() 為 true 才 instance(...)                # Phase 17，priority 20，wcs_renewal_order_created（filter），WooCommerce Subscriptions 選用相依，未安裝時完全不註冊
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
          └→ <AuthProvider>                 # status 三態 + 跨 partner 雙保險 + forceLogoutAndRedirect helper（6-A2）
              └→ <AuthGate>
                  ├→ guest → /login          # Login 頁（rate-limit 倒數）
                  │                          #   讀 ?reason=password_changed（KNOWN_REASONS 白名單）
                  │                          #   顯示一次性 success notification（6-A2）
                  └→ authenticated → /dashboard # Dashboard（KPI / TrendChart / SettlementsTable + 「修改密碼」link）
                      └→ /change-password   # ChangePassword 頁（6-A2 HIGH-RISK）
                          ├→ 三欄位 antd Form + Form rule（new === current / new !== confirm 阻擋）
                          ├→ submit → useChangePassword（mutationKey + retry: 0）
                          │   → POST /partner-auth/change-password
                          │     （axios + X-Skip-Auth-Redirect header 旁路 401 interceptor）
                          ├→ failure：mapPartnerException(error, 'change-password') → notification.error
                          │            rate-limit → Retry-After cooldown
                          ├→ success：mutation.reset() + form.resetFields + state lock 1.5s
                          │            → forceLogoutAndRedirect('password_changed')
                          │            → /login?reason=password_changed
                          └→ unmount：setTimeout cleanup ref 防 race
```

### 賣場前台載入流程（Phase 4-C，Phase 14 更新）

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
                  ├→ $front_settings = ProfitShopFrontData::settings($shop)   # 主色（Phase 14 起無消費者，見 TD-38）/ 販售狀態；便宜，不組 products
                  ├→ [Phase 14] ProfitShopFrontView::enqueue()：
                  │   ├→ wp_enqueue_script(inc/assets/js/profit-shop-front.js)   # 手寫 vanilla JS，不進 Vite
                  │   └→ wp_localize_script('power_shop_front_i18n', js_i18n())  # 只有文案，無 env/settings/products
                  ├→ get_header()                                       # 走 theme（與 partner portal 不同！）
                  ├→ require templates/profit-shop-front.php            # 把 $shop_for_template / $front_settings 傳入
                  │   ├→ $ps_products = ProfitShopFrontData::build_products($shop)   # 卡片與 Modal 共用同一份 payload
                  │   ├→ post_content（apply_filters('the_content')，站長自訂文案）
                  │   ├→ [Phase 16] 非販售期間依三個獨立旗標分流（任何組合都不印 Modal）：
                  │   │   showCountdown   → 印 [data-ps-schedule] 內的 daisyUI 倒數盤
                  │   │   showDisplayText → 印 [data-ps-schedule] 內的自訂文字（空字串時已收斂為 false）
                  │   │   showProducts    → 照印卡片但按鈕停用（**預設值**＝Phase 16 之前的既有行為）
                  │   │   三者皆 false → 商品區與排程區塊整段不印（連「此賣場暫無商品」也不印）
                  │   ├→ foreach $ps_products → require partials/profit-shop-card.php：
                  │   │   ├→ div[data-ps-product-id][data-ps-disabled]
                  │   │   ├→ aspect-square 圖 + group-hover:scale-125
                  │   │   ├→ del 原價 + text-error 特價（variable 走 min–max 區間邏輯）
                  │   │   └→ 唯一動作元素（漸進增強）：simple → <form method="get">；其他 → <a href>
                  │   └→ $is_shop_open 時 foreach $ps_products → require partials/profit-shop-modal.php：
                  │       └→ 每商品一個 <dialog class="pc-modal" data-ps-modal-for>（含加購 <form method="post">）
                  │           非販售期間**整段不印**（Modal 內的 form 即使 disabled 仍在 DOM 內）
                  └→ get_footer()                                        # wp_footer 印出 <script src=".../profit-shop-front.js">
```

⚠ **`$front_settings` 兩條渲染路徑都必須傳入**：`ProfitShopShortcode::render_template()`
與 `ProfitShopRenderer::render_shop()` 都會計算並傳入同一組 `$front_settings` / 呼叫同一個
`ProfitShopFrontView::enqueue()`——早期只有 renderer 會傳，shortcode 路徑因此拿到空陣列，
`shopStatus` fallback 成 `'published'`，讓已結束販售的賣場照樣可加購（Phase 14 已修，詳見
`profit-shop.rule.md` §7 與 §12.8）。

### 賣場前台 JS 互動層載入流程（Phase 14：vanilla JS，取代 Phase 10-13 的 React bundle）

```
wp_footer → <script src=".../inc/assets/js/profit-shop-front.js">（手寫，不經 Vite / React 打包）
  └→ DOMContentLoaded（或已 loaded 時立即執行）→ init()
      └→ document.querySelectorAll('.ps-profit-shop-front')      # 支援一頁多個 shortcode 賣場
          └→ 對每個賣場容器 initShop(shop)：
              ├→ 對容器內每個 [data-ps-modal-for] Modal 綁定：
              │   ├→ bindGallery()         # 縮圖 click / keydown 切換主圖
              │   ├→ bindToggleContent()   # 描述展開/收合（scrollHeight 只能在 dialog 開啟後量測準）
              │   ├→ bindQuantity()        # 數量 -/+，夾住 [1, max]
              │   └→ bindVariations()      # 讀該 Modal 自己的 <script class="ps-variations-data"> JSON
              │       ├→ 屬性按鈕 click → matchVariation() 比對出對應變體
              │       ├→ 重寫 variation_id / attribute_* hidden input（鍵取自變體自己的 attributes）
              │       ├→ 重繪價格 / 庫存 / 購買人數 / 數量上限 / 加購按鈕 disabled
              │       └→ 有變體圖時呼叫 modal.psSelectImage() 換圖
              └→ 對容器內每個 [data-ps-product-id] 卡片：
                  ├→ data-ps-disabled="1" → 不綁 click（賣場已關閉，卡片本身已是停用狀態）
                  ├→ 查找對應 Modal（限定在同一個 .ps-profit-shop-front 內，多賣場才不會開錯）
                  ├→ <dialog> 不被支援 → 不攔截，卡片原生 form / 連結就是完整加購路徑（漸進增強）
                  └→ click → preventDefault + stopPropagation → psResetVariations() → modal.showModal()
                              → psMeasureContent()（此時 dialog 已開，量測才準）

加入購物車（PHP 直接印出的 WooCommerce 原生表單，JS 只負責在送出前填好變體欄位）：
  └→ Modal 內 <form method="post">（無 action，送回當前網址）
       ├→ profit_shop_id 由 PHP 印死；quantity / variation_id / attribute_* 由 JS 維護
       ├→ WC_Form_Handler::add_to_cart_action()（掛 wp_loaded）處理
       │    → $_POST 天然帶 profit_shop_id → AddToCartHook 三道閘門照常驗證
       ├→ 結果寫進 WC session notices → redirect 回本頁（或購物車頁，看站台 WC 設定）
       └→ template 自印的 wc_print_notices() 呈現結果

Phase 10 的 admin-ajax（FrontAjax）+ WC Store API 雙通道、Phase 11 的自訂 REST（FrontCartApi）、
Phase 12-13 的 React 元件（`AddToCartButton.tsx` 等，`js/src/profit-shop-front/` 整目錄）
皆已整條移除且刪檔，前台不再有任何購物車 API，也不再有任何 React / antd 程式碼。
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
（Phase 15 起前台不再有任何分潤顯示 filter）
   ┌─────────────────────────────────────────────────┐
   │ CartItemMetaDisplay（4-C2）已刪檔                 │
   │   站長要求 /cart 與 /checkout 不顯示「來自賣場」。 │
   │   它唯一的作用是 woocommerce_get_item_data，      │
   │   移除後定價 / 簽章 / 結算完全不受影響。           │
   │   訂單 line item 的 _profit_* meta 因底線前綴      │
   │   本來就被 WooCommerce 隱藏，不需另外處理。        │
   │   復原見 git commit f813e07。                     │
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
