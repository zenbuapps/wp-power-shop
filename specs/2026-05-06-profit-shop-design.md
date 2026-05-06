# 分潤賣場 (Profit Shop) — 設計規格

> **建立日期**：2026-05-06
> **狀態**：草案 / 待實作規劃
> **目標**：在保留舊版「一頁商店」（`legacy/`）前提下，重構出全新「分潤賣場」系統，整合進 power-shop 後台 SPA。
> **參與決策**：使用者（老大）+ 角頭 Claude

---

## 0. 摘要

「分潤賣場」是一個獨立於舊版 legacy「一頁商店」的全新系統。允許 KOL / 行銷推廣者自行開設賣場、挑選商品、覆寫價格（含原價、特價、訂閱首期費用）、灌水購買數，並透過 KOL 專屬報表 URL 查看銷售與分潤狀況。

**核心特性**：

- 獨立 CPT (`powershop`)、獨立 Custom Taxonomy (`profit_partner`)、獨立 REST API (`power-shop/v2`)
- 兩種模式：頁面模式（自有 URL 頁面）+ 短代碼模式 (`[profit_shop id="..."]`)
- 行銷歸屬粒度為 **WooCommerce cart line item 級**（`cart_item_data` → `wc_order_itemmeta`）
- 分潤比例綁在賣場（同一 KOL 不同賣場可有不同比例）
- 結算 ledger（pending / paid / refunded / cancelled）
- 從舊 `power-shop` CPT 一鍵匯入工具
- 新舊系統 0 共享資料、0 共享 hook，舊版繼續可用

---

## 1. 架構概觀

### 1.1 命名與隔離

| 項目 | 舊版 legacy | 新版「分潤賣場」 |
|------|------------|------------------|
| Namespace | `J7\PowerShopV2` | `J7\PowerShop\Domains\ProfitShop` |
| 載入方式 | `legacy/plugin.php` 直接 require | `Domains\Loader::instance()` 註冊 |
| Internal CPT slug | `power-shop` | **`powershop`**（鎖死） |
| Custom Taxonomy | 無 | `profit_partner`（hierarchical=false） |
| REST namespace | 混用 WC v3 | `power-shop/v2`（沿用本外掛現有） |
| 預設前台 URL | `/power-shop/{slug}/` | `/powershop/{post-name}/`（rewrite slug 可改） |
| 預設報表 URL | `/power-shop/{slug}/report/` | `/profit-report/{partner-slug}/`（slug 可改） |
| 短代碼 | `[power_shop]` (舊) | `[profit_shop id="X" columns="3" show_count="true"]` |
| 後台 UI | WP CPT metabox + 獨立 Vite bundle | 整合進 power-shop React SPA |
| post_meta key prefix | `power_shop_*` | `_profit_*` |

> 註：新版 internal CPT slug `powershop` 與舊版 `power-shop` 字串不同（一個無連字號，一個有），WP 不會撞但人類讀容易搞混。文件嚴格區分：「**新版 = profit shop / `powershop`**」「**舊版 = legacy power-shop / `power-shop`**」。

### 1.2 隔離保證

1. Namespace 不撞（`PowerShop\Domains\ProfitShop` vs `PowerShopV2`）
2. CPT slug 不撞（`powershop` vs `power-shop`）
3. post_meta key 不撞（新版全用 `_profit_*` prefix）
4. Cart hook 共存：兩套各自註冊在自己的 cart_item_data key
5. 報表 rewrite rule 不撞（`/profit-report/` vs `/power-shop/{slug}/report/`）
6. React bundle 共用（power-shop 既有 bundle 加路由，不另開 entry）

### 1.3 與 power-shop 既有系統整合

- **不新增 plugin**：新版是 power-shop 的新 Domain（`inc/classes/Domains/ProfitShop/`）
- **複用基礎設施**：`ApiBase`、`SingletonTrait`、`WP::sanitize_text_field_deep()`、`SimpleEncrypt`、Refine + Ant Design SPA、`'power-shop'` dataProvider
- **`Domains\Loader` 註冊新 V2Api**（依 `.claude/rules/wordpress.rule.md`）
- **新增 React 路由**到 `js/src/App1.tsx`、`js/src/resources/index.tsx`、`js/src/pages/admin/ProfitShop/`

### 1.4 元件圖

```
power-shop React SPA
  /profit-shops                  List + Filter + Pagination
  /profit-shops/create           建立賣場
  /profit-shops/edit/:id         多 Tab：基本/商品/設定/版型/短代碼/預覽
  /profit-partners               KOL Term 管理
  /profit-settlements            結算 ledger
  /profit-settings               全站設定（rewrite slug 等）
        │
        │ dataProvider: 'power-shop'
        ▼
PHP: Domains/ProfitShop/
  ├ Domain/                       純 PHP，無 WP/WC 依賴
  │   ├ Entity/                   ProfitShop, OverrideItem, SettlementRecord
  │   ├ ValueObject/              PriceOverride, ProfitRate, PartnerSlug, InflatedCount, ShopMode
  │   ├ Service/                  PriceCalculator, ProfitCalculator
  │   └ Repository/               (interfaces) ProfitShopRepositoryInterface, SettlementRepositoryInterface
  ├ Application/                  Use Cases
  │   ├ UseCase/                  CreateProfitShop, UpdateShopItems, AddToCartWithAttribution,
  │   │                           PersistOrderAttribution, ImportLegacyShop, SettleProfit,
  │   │                           HandleRefund, AuthenticatePartner, ValidateRewriteSlug
  │   └ DTO/                      ProfitShopDto, OverrideItemDto, SettlementSummaryDto
  ├ Infrastructure/
  │   ├ Persistence/              CptProfitShopRepository, OrderItemSettlementRepository
  │   ├ WordPress/                CptRegistrar, TaxonomyRegistrar, RewriteRules
  │   └ WooCommerce/              CartHooks, OrderHooks, RefundHooks
  └ Presentation/
      ├ Rest/                     V2Api（繼承 ApiBase）+ Controllers
      ├ Frontend/                 PageModeRenderer, ShortcodeRenderer, BlockRegistrar, ReportPageRenderer
      └ Admin/                    ScriptEnqueueExtension（為 SPA 注入 env）
        │
        ▼
WP DB:
  - posts (post_type='powershop') + postmeta
  - terms / term_taxonomy ('profit_partner')
WC DB:
  - woocommerce_order_itemmeta（line item 歸屬 + 結算狀態）
```

### 1.5 DDD 分層原則

- **Domain 層**：完全純 PHP，無 WP/WC 依賴，可單獨 unit test
- **Repository Interface 在 Domain，實作在 Infrastructure**：依賴反轉（DIP）
- **Application 層**：use case 編排，不含業務規則
- **Infrastructure 層**：對接 WP/WC 的具體實作，hook callback 只呼叫 use case 不寫業務邏輯
- **Presentation 層**：HTTP / shortcode / hook 的「翻譯」層

**務實妥協**：不引入完整 DI container（power-shop 沒有），用 `SingletonTrait` 自行建依賴（poor man's DI）。

---

## 2. 資料模型

### 2.1 主要實體關係

```
wp_posts (post_type='powershop')   N : 1   profit_partner term
       │                                          │
       │ 1 : N (post_meta)                        │ term_meta
       ▼                                          ▼
   _profit_shop_items (json)               _partner_password (bcrypt)
   _profit_rate (int 0-100)                _partner_contact_email
   _profit_shop_mode (page|shortcode)      _partner_settled_log (json)
   _profit_partner_term_id (int)
   _profit_shop_settings (json)

當訂單成立：
   wc_order_items   N : 1   wp_posts (shop_order/shop_subscription/HPOS)
        │
        │ 1 : N
        ▼
   wc_order_itemmeta：line-item 級歸屬（凍結快照）
       _profit_shop_id, _profit_partner_term_id, _profit_rate
       _override_regular_price, _override_sale_price, _override_signup_fee
       _actual_price, _profit_amount
       _settlement_status (pending|paid|refunded|cancelled)
       _settled_at, _settled_by
```

### 2.2 CPT `powershop`

| 欄位 | 用途 |
|------|------|
| `post_title` | 賣場名稱（後台 + 前台 H1） |
| `post_name` | URL slug（自動 sanitize，賣場主可改） |
| `post_status` | publish / draft（draft 不對外） |
| `post_content` | 賣場文案（給 Block Editor / Elementor 編輯，**僅頁面模式用**） |

**post_meta**：

| meta_key | 型別 | 說明 |
|----------|------|------|
| `_profit_shop_mode` | string | `page` \| `shortcode`，預設 `page` |
| `_profit_partner_term_id` | int | 對應的 partner term id（1:1） |
| `_profit_rate` | int | 0–100，分潤百分比 |
| `_profit_shop_items` | json string | 商品清單（見 §2.3） |
| `_profit_shop_settings` | json string | banner_url、btn_color、show_stock 等 |

### 2.3 `_profit_shop_items` 結構

```json
[
  {
    "product_id": 123,
    "inflated_count": 100,
    "override": {
      "regular_price": "1000.00",
      "sale_price": "799.00",
      "signup_fee": null
    },
    "variations": {
      "456": {
        "override": {
          "regular_price": "1100.00",
          "sale_price": "899.00",
          "signup_fee": null
        }
      }
    }
  }
]
```

> **儲存策略**：用 json 存單欄而非展開成多筆 row。商品清單通常 < 200 件，整批讀寫，不需 SQL 條件查中間元素。未來商品數膨脹再遷移到自訂表。

**Fallback chain（顯示與結帳價）**：

```
variation.override.{price}
  ?? parent.override.{price}
  ?? variation.original.{price}
  ?? parent.original.{price}
  ?? regular_price
```

### 2.4 Custom Taxonomy `profit_partner`

- `register_taxonomy()` 參數：`public => false`、`show_in_rest => true`、`hierarchical => false`
- `term.slug` = 識別字串（`jerry`, `luke` 等）
- `term.name` = 顯示名稱（`Jerry 老師` 等）

**term_meta**：

| meta_key | 型別 | 說明 |
|----------|------|------|
| `_partner_password` | string | `wp_hash_password()` 雜湊（bcrypt） |
| `_partner_contact_email` | string | 撥款通知收件 email（v2 寄信用） |
| `_partner_settled_log` | json string | 撥款歷史 log（accumulative） |
| `_partner_password_changed_at` | int | 密碼最後變更時間（audit） |

### 2.5 WC Order Item Meta（line-item 級歸屬）

> **HPOS 相容**：`wc_order_itemmeta` 表結構在 HPOS 不變動，line item meta 完全相容。HPOS 只改了訂單主表。

| meta_key | 型別 | 說明 |
|----------|------|------|
| `_profit_shop_id` | int | 賣場 CPT post id |
| `_profit_partner_term_id` | int | partner term id（凍結） |
| `_profit_rate` | int | 凍結當下比例（訂閱續訂時動態抓最新值） |
| `_override_regular_price` | string\|null | |
| `_override_sale_price` | string\|null | |
| `_override_signup_fee` | string\|null | 訂閱專用 |
| `_actual_price` | string | 實際扣款單價 |
| `_profit_amount` | string | = actual × qty × rate / 100 |
| `_settlement_status` | string | `pending` \| `paid` \| `refunded` \| `cancelled` |
| `_settled_at` | int\|null | timestamp |
| `_settled_by` | int\|null | 撥款操作者 user id |

### 2.6 wp_options 全站設定

```json
power_shop_profit_settings = {
  "rewrite_slug": "powershop",
  "report_slug": "profit-report",
  "default_partner_session_ttl": 3600
}
```

### 2.7 DBML 速覽（可加進 specs/entity/erm.dbml）

```dbml
Table powershop_post {
  ID int [pk]
  post_title varchar
  post_name varchar [note: '前台 URL slug']
  post_status varchar
  post_content text [note: '頁面模式的 Block/Elementor 內容']
}

Table powershop_postmeta {
  meta_id int [pk]
  post_id int [ref: > powershop_post.ID]
  meta_key varchar
  meta_value longtext
}

Table profit_partner_term {
  term_id int [pk]
  name varchar
  slug varchar [note: 'jerry / luke']
}

Table profit_partner_termmeta {
  meta_id int [pk]
  term_id int [ref: > profit_partner_term.term_id]
  meta_key varchar
  meta_value longtext
}

Table line_item_attribution {
  meta_id int [pk]
  order_item_id int
  profit_shop_id int [ref: > powershop_post.ID]
  profit_partner_term_id int [ref: > profit_partner_term.term_id]
  profit_rate int
  override_regular_price decimal
  override_sale_price decimal
  override_signup_fee decimal [note: '訂閱專用']
  actual_price decimal
  profit_amount decimal
  settlement_status varchar [note: 'pending|paid|refunded|cancelled']
  settled_at timestamp
  settled_by int
}
```

---

## 3. UI 表面

### 3.1 後台 power-shop SPA 路由新增

| 路由 | 元件 | dataProvider |
|------|------|---------------|
| `/profit-shops` | `ProfitShop/List` | `power-shop` |
| `/profit-shops/create` | `ProfitShop/Create` | `power-shop` |
| `/profit-shops/edit/:id` | `ProfitShop/Edit`（多 Tab） | `power-shop` |
| `/profit-partners` | `ProfitPartner/List` | `power-shop` |
| `/profit-partners/edit/:id` | `ProfitPartner/Edit` | `power-shop` |
| `/profit-settlements` | `Settlement/List`（結算 ledger） | `power-shop` |
| `/profit-settings` | `ProfitSettings`（rewrite slug 等） | `power-shop` |

> **UI 開發參考**：`/zenbu-powers:antd-toolkit` skill；Refine 整合參考 `~/.claude/skills/refine/references/`（antd-crud + data-hooks）。

### 3.2 賣場列表頁

```
篩選：搜尋名稱 / Partner / 模式 / 狀態 / [新增]
Table 欄位：名稱 / Partner / 模式 / 比例 / 銷售筆數 / 操作（編輯、↗ 報表）
批次操作：批次刪除、批次改 partner、批次改比例
```

### 3.3 賣場編輯頁（多 Tab）

| Tab | 內容 |
|-----|------|
| 基本 | 名稱、URL slug、模式（page / shortcode）、Partner 選擇器（autocomplete）、分潤比例（0–100） |
| 商品 | 商品挑選 Table（核心 UX，§3.4） |
| 設定 | Banner URL、按鈕主色、顯示庫存/購買數開關 |
| 頁面版型 | 「編輯頁面內容 ↗」按鈕 → `_blank` 到 `/wp-admin/post.php?post={id}&action=edit`（短代碼模式 hide 此 tab） |
| 短代碼 | 顯示 `[profit_shop id="123"]`、複製按鈕、預覽連結（短代碼模式才顯示） |
| 預覽 | iframe 嵌入 `/powershop/{slug}/`（頁面模式才顯示） |

### 3.4 ⭐ 商品挑選 Table（核心 UX）

**參考既有元件**：`js/src/components/product/ProductEditTable`（power-shop 變體編輯 Table）。

**篩選器**：

- 已加入此賣場（預設勾選）
- 商品名稱 LIKE
- 分類（`product_cat` taxonomy）
- 標籤（`product_tag` taxonomy）
- 商品類型（simple / variable / subscription / variable-subscription）
- 庫存狀態（instock / outofstock / onbackorder）
- SKU LIKE
- 價格範圍（min / max）

**互動行為**：

- 預設篩選「已加入此賣場」，列出已綁商品；切到「全部」可挑新商品打勾加入
- **覆寫價輸入** inline edit；Variation 預設「繼承 parent override」，輸入即覆寫該變體
- **訂閱首期費用欄**：simple/variable 商品 disabled，subscription/variable-subscription 才可輸入
- **同步修改模式**（toggle）：開啟後改一列覆寫原價/特價/灌水數 → 自動套用到所有勾選列；同步粒度 4 種：固定值、加減值（+100/-50）、百分比（×0.8）、清空
- **虛擬列表**：沿用既有 pattern：`Form.getFieldsValue` 不適用虛擬列表 → `handleValuesChange → setVirtualFields` 手動狀態管理
- **儲存策略**：整個 `_profit_shop_items` json 批次替換（單次 PUT），不逐筆 PATCH

### 3.5 結算頁 `/profit-settlements`

```
篩選：Partner / 賣場 / 日期區間 / 狀態（pending/paid/refunded/cancelled）
摘要卡：待撥款金額（筆數） / 已撥款金額（筆數） / 退款金額
Line Item Table 欄位：訂單# / 商品 / Partner / 賣場 / 應分潤 / 狀態 / 操作
批次操作：標記已撥款、標記取消、人工沖回（paid → refunded）、CSV 匯出
```

### 3.6 前台「頁面模式」版型

預設 `single-powershop.php` 由插件提供，theme 可覆寫。

```
[Banner（_profit_shop_settings.banner_url）]
H1 賣場名稱（post_title）
the_content() — Block/Elementor 自由排版
[商品列表（共用 ShortcodeRenderer）]
  3 欄 → 2 欄 → 1 欄 RWD
  商品卡：圖 / 名稱 / 價格（覆寫後） / 已售（含灌水）/ 加購按鈕
```

**前端技術**：Tailwind 自幹（不載 Ant Design 前台 bundle，控制 size < 50KB）。

### 3.7 短代碼模式

```
[profit_shop id="123"]
[profit_shop id="123" columns="2" show_count="false"]
```

- 只渲染商品列表區塊（不含 Banner / 文案）
- 同一頁可多個短代碼共存（不同 shop_id），不同 partner 各自歸屬
- Gutenberg `dynamic block` 包同一個 render callback，`block.json` 提供 `id` attribute

### 3.8 報表前台 SPA `/profit-report/{partner-slug}/`

```
未登入：密碼登入框
已登入：
  KPI 卡：總業績 / 應分潤 / 已撥款
  Filter：賣場（multi）/ 日期區間（query string sync）
  趨勢圖（ECharts 折線圖）
  訂單明細 Table：訂單# / 商品 / 賣場 / 應分潤 / 狀態
```

- **獨立 entry**：`js/src/profit-report.tsx` → 獨立 bundle（不要把 admin SPA 整包載給 KOL）
- bundle size 預估 < 200KB
- Refine `useTable` 自動 sync filter → query string，URL 可分享
- 登出按鈕：清 transient + 清 cookie

### 3.9 後台訂單頁的「歸屬資訊」

power-shop 既有 `/orders/edit/:id` 增加「分潤資訊」面板：列出該訂單每個 line item 的 shop / partner / 應分潤 / 狀態。訂單列表新增「分潤賣場」過濾。

---

## 4. REST API 規格

> 全部端點 namespace = `power-shop/v2`
> Base path = `/wp-json/power-shop/v2/`
> 預設 `permission_callback` = Powerhouse 預設認證（`manage_woocommerce`）

### 4.1 ProfitShops Resource

| Method | Endpoint | Capability |
|--------|----------|------------|
| GET | `profit-shops` | `manage_woocommerce` |
| GET | `profit-shops/(?P<id>\d+)` | `manage_woocommerce` |
| POST | `profit-shops` | `manage_woocommerce` |
| PUT | `profit-shops/(?P<id>\d+)` | `manage_woocommerce` |
| DELETE | `profit-shops/(?P<id>\d+)` | `manage_woocommerce` |
| POST | `profit-shops/(?P<id>\d+)/duplicate` | `manage_woocommerce` |
| POST | `profit-shops/bulk-delete` | `manage_woocommerce` |
| POST | `profit-shops/bulk-update-partner` | `manage_woocommerce` |
| POST | `profit-shops/bulk-update-rate` | `manage_woocommerce` |

**POST/PUT body 範例**：

```json
{
  "title": "夏季活動賣場",
  "slug": "summer-sale",
  "status": "publish",
  "mode": "page",
  "partner_term_id": 5,
  "rate": 10,
  "items": [
    {
      "product_id": 123,
      "inflated_count": 100,
      "override": { "regular_price": "999", "sale_price": "799", "signup_fee": null },
      "variations": {
        "456": { "override": { "regular_price": "1099", "sale_price": "899", "signup_fee": null } }
      }
    }
  ],
  "settings": { "banner_url": "...", "btn_color": "#1677ff", "show_stock": true }
}
```

### 4.2 ProfitPartners Resource

| Method | Endpoint | 備註 |
|--------|----------|------|
| GET | `profit-partners` | List |
| POST | `profit-partners` | 新增 term + 雜湊存密碼 |
| PUT | `profit-partners/(?P<id>\d+)` | 更新名稱 / 密碼 / email |
| DELETE | `profit-partners/(?P<id>\d+)` | 檢查未掛在賣場才允許刪 |
| POST | `profit-partners/(?P<id>\d+)/regenerate-password` | 後台重設密碼 |

> **密碼欄位永不在 GET 回應中回傳**；POST/PUT 接收明文，存入 `wp_hash_password()` bcrypt 雜湊。
> **不開放 partner 自助修改密碼**（防帳號被盜後改密碼鎖死）。

### 4.3 Settlements Resource

| Method | Endpoint |
|--------|----------|
| GET | `profit-settlements` (filter: partner_id / shop_id / status / date range) |
| GET | `profit-settlements/summary` |
| POST | `profit-settlements/mark-paid` |
| POST | `profit-settlements/mark-cancelled` |
| POST | `profit-settlements/reverse-paid`（人工沖回 paid → refunded） |
| POST | `profit-settlements/export` (CSV) |

### 4.4 PartnerAuth（公開端點）

| Method | Endpoint | permission |
|--------|----------|------------|
| POST | `partner-auth/login` | `__return_true` + rate-limit |
| POST | `partner-auth/logout` | `__return_true` |
| GET | `partner-auth/verify` | `__return_true` |

**Login Response**：

```json
{
  "code": "success",
  "data": {
    "token": "...",
    "expires_at": 1746537600,
    "partner": { "id": 5, "name": "Jerry 老師", "slug": "jerry" }
  }
}
```

### 4.5 PartnerReports（partner token）

| Method | Endpoint |
|--------|----------|
| GET | `partner-reports/kpi` |
| GET | `partner-reports/trend` |
| GET | `partner-reports/orders` |

**Custom permission_callback**：

```php
function ($request) {
    $token = $request->get_header('X-Partner-Token')
        ?? $_COOKIE['profit_partner_token'] ?? '';
    return PartnerAuthService::verify_token($token);
}
```

> Token 驗證通過後，將 `partner_id` 注入 request；回傳資料**強制過濾僅該 partner 範圍**，避免越權。

### 4.6 Frontend Cart Action（公開 + nonce）

| Method | Endpoint |
|--------|----------|
| POST | `profit-shops/(?P<id>\d+)/add-to-cart` |

**Server-side 完成**：

1. 重查 DB 拿賣場該商品的 override 價（**不信任前端任何價格**）
2. 驗證 `partner_term_id` 真的有掛在該 shop 上
3. 把 `_profit_shop_id`、`_profit_partner_term_id`、`_profit_rate`、`_override_*` 塞進 `cart_item_data`
4. `WC()->cart->add_to_cart(...)`
5. 回傳 cart fragments

> 短代碼模式同樣呼叫此端點。

### 4.7 Migration Importer

| Method | Endpoint |
|--------|----------|
| GET | `profit-migration/legacy-shops` |
| POST | `profit-migration/import` |

**匯入邏輯**：

1. 讀舊 `power-shop` CPT 的 `power_shop_meta`
2. Map 到新版 `_profit_shop_items` 結構
3. 複製 banner / btn_color 設定到新版 `_profit_shop_settings`
4. 預設 `mode = page`
5. **不刪除舊版**
6. 回傳新建賣場 ID + diff 報告

### 4.8 ProfitSettings

| Method | Endpoint | Capability |
|--------|----------|------------|
| GET | `profit-settings` | `manage_options` |
| PUT | `profit-settings` | `manage_options` |
| GET | `profit-settings/validate-slug?slug=xxx` | `manage_options` |

PUT 後自動 `flush_rewrite_rules()`。

### 4.9 OpenAPI 文件

完整 OpenAPI 3.0 spec 寫到 `specs/api/api.yml`（追加到既有檔），跟現有 dashboard / orders / products 端點同檔。

---

## 5. 核心流程（Sequence）

### 5.1 買家加購

```
買家 → Frontend → POST /profit-shops/{id}/add-to-cart
  ↓
Server-side：
  1. 驗 nonce
  2. 從 DB 讀賣場 _profit_shop_items（重查，不信前端）
  3. PriceCalculator 算實際價（fallback chain）
  4. 組 cart_item_data：_profit_shop_id, partner_term_id, rate, override_*
  5. WC()->cart->add_to_cart(...)
     → Cart hash 計算納入 cart_item_data
     → 同商品 + 不同 partner = 拆兩條 cart line
  6. 回傳 cart fragments
```

### 5.2 結帳價格套用 + 寫入 order_itemmeta

```
WC 標準結帳流程
  ↓ woocommerce_before_calculate_totals (cart 顯示 + 結算)
PriceOverride hook：
  for each cart_item:
    if has _profit_shop_id:
      $cart_item['data']->set_price($actual)

  ↓ woocommerce_checkout_create_order_line_item
LineItemMeta hook：
  從 cart_item 取 _profit_*
  寫入 order_itemmeta：
    _profit_shop_id, _profit_partner_term_id, _profit_rate
    _override_*, _actual_price
    _profit_amount = actual × qty × rate / 100
    _settlement_status = 'pending'
```

### 5.3 退款 / 取消回沖

```
woocommerce_order_refunded hook：
  for each refunded item:
    if status='pending': → 'refunded'
    if status='paid': 發 admin notice，需賣場主處理
                     audit log type='refund_after_paid'

woocommerce_order_status_cancelled hook：
  for each line item:
    if status='pending': → 'cancelled'
```

### 5.4 訂閱續訂

```
wcs_renewal_order_created hook：
  1. 讀 parent order line items 的 _profit_*
  2. 自動複製到 renewal order item meta：
     _profit_shop_id, _profit_partner_term_id（凍結值）
  3. 動態抓最新比例：
     _profit_rate = 重讀賣場 CPT 的 _profit_rate
     fallback：賣場已刪 → 用 parent order 凍結 rate
  4. 重新計算：
     _actual_price = WC 算的續訂價
     _profit_amount = actual × qty × rate / 100
     _settlement_status = 'pending'
```

> **凍結 vs 動態**：`_profit_shop_id`、`_profit_partner_term_id` 凍結（賣場/partner 改名/刪除不變）；`_profit_rate` 動態（賣場主隨時可調，續訂用最新值）。

### 5.5 結算（賣場主撥款）

```
賣場主 → Admin SPA → POST profit-settlements/mark-paid
  ↓ {order_item_ids: [...]}
Settlement Service：
  for each order_item_id:
    1. 確認當下 status='pending'
    2. _settlement_status='paid'
    3. _settled_at=now()
    4. _settled_by=current user id
  將 batch 寫入 partner term meta _partner_settled_log:
    {date, type:'settle', total, item_count, by}
```

### 5.6 Partner 登入查報表

```
KOL → /profit-report/jerry/
  ↓ 載入獨立 SPA bundle
  → POST partner-auth/login {partner_slug, password}
  ↓ wp_check_password vs term meta hash
  ↓ 通過後生 random token
  ↓ set_transient('profit_token_' . hash, partner_id, 3600)
  ↓ Set-Cookie: profit_partner_token (httponly, samesite=Lax)
  ← {token, expires_at, partner}

GET partner-reports/* (帶 X-Partner-Token / cookie)
  ↓ permission_callback verify token → partner_id
  ↓ SQL: SELECT FROM wc_order_itemmeta WHERE partner_term_id={locked_id}
  ← data
```

### 5.7 邊緣情境

| 情境 | 處理 |
|------|------|
| 加購時賣場已被刪除 / unpublish | 端點回 404，前端「賣場已下架」 |
| 加購時商品已從賣場移除 | 端點回 410 Gone |
| 訂單成立後賣場被刪除 | line item meta 凍結快照不受影響；報表 LEFT JOIN 顯示 `[已刪除賣場]` |
| 訂單成立後 partner term 被刪除 | 同上，標 `[已停用 KOL]`，結算照算（金額已凍結） |
| 同一 cart 多個賣場的商品 | cart_item_data 不同自然拆 line |
| 商品從 simple 改成 variable | 已存在賣場的 override 自動失效，前端 410，後台 Edit 顯示警告 |
| Cart 半路移除 | 沒影響，cart_item_data 跟著刪 |
| 訂單 partial refund | 對應 line item 各自處理 |
| Partner term slug 含中文 / Emoji | 編碼安全（urlencode + i18n 不影響） |

---

## 6. 安全模型

### 6.1 認證 / 授權矩陣

| 端點分類 | 認證方式 | Capability |
|---------|---------|------------|
| `profit-shops/*`、`profit-partners/*`、`profit-settlements/*` | nonce + Powerhouse | `manage_woocommerce` |
| `profit-migration/*`、`profit-settings` | nonce + Powerhouse | `manage_options`（更嚴格） |
| `partner-auth/login` | 公開 | rate-limit |
| `partner-reports/*` | Cookie/Header `X-Partner-Token` | token → partner_id 範圍鎖死 |
| `profit-shops/{id}/add-to-cart` | 公開 + nonce | rate-limit |

### 6.2 價格防竄改（最重要）

**絕不信任前端傳來的任何價格**。

```php
// ❌ 嚴禁
$cart_item_data['_actual_price'] = $request->get_param('price');

// ✅ 強制
$shop = ProfitShopRepo::find($shop_id);
$item = $shop->find_item($product_id, $variation_id);
$actual = PriceCalculator::calculate($item, $product);
$cart_item_data['_actual_price'] = $actual;
```

**驗證鏈**：

1. `partner_term_id` 必須真的有掛在這個 `shop_id`
2. `product_id` 必須在 `_profit_shop_items` 內
3. `variation_id` 必須屬於該 product 且在 `variations` 子物件內（或 fallback 到 parent override）
4. 商品狀態必須是 `publish` 且 `instock`（或允許 backorder）

### 6.3 Token 安全（Partner 認證）

- **Token 生成**：`wp_generate_password(64, false)` → 64 字元安全亂數
- **儲存**：`set_transient('profit_token_' . hash('sha256', $token), $partner_id, 3600)`
  - **存 hash 不存原 token**（資料庫被脫不會洩 token）
- **Cookie**：`httponly` ✅、`samesite=Lax` ✅、`secure` ✅（HTTPS）、`path=/wp-json/power-shop/v2/partner-`
- **Token TTL**：1 小時（可在 `profit-settings` 調整）
- **登出**：`delete_transient` + `setcookie('', expires=past)`

### 6.4 防暴破（Login）

```php
$key = 'profit_login_fail_' . $partner_slug;
$count = (int) get_transient($key);

if ($count >= 5) {
    return new WP_Error('too_many_attempts', '登入失敗次數過多，請 15 分鐘後再試');
}

if (! wp_check_password($password, $hashed)) {
    set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
    return new WP_Error('invalid_credentials');
}

delete_transient($key);
```

> 第 5 次失敗時，**寄 admin notice email** 給賣場主。

### 6.5 加購端點 Rate Limit

- 每個 IP 每分鐘最多 60 次加購
- `set_transient('cart_rate_' . $ip, $count, 60)` 計數
- 超過回 429 + admin log

### 6.6 HPOS 相容

| 項目 | 處理 |
|------|------|
| `wc_order_itemmeta` | HPOS 不改此表，line item meta 直接讀寫 ✅ |
| 訂單 meta 讀寫 | 用 `$order->get_meta()` / `update_meta_data()`（HPOS API） |
| 訂單查詢 | `wc_get_orders()`（禁用 `WP_Query` 查訂單） |
| 訂單 ID 取得 | `$order->get_id()`（禁用 `$order->ID`） |
| 退款 / Subscription hook | 全部走 WC 標準 hook，HPOS 自動觸發 |

### 6.7 輸入清理 / 輸出跳脫

| 場景 | 函數 |
|------|------|
| REST request params | `WP::sanitize_text_field_deep()` |
| 價格欄位 | `wc_format_decimal()` |
| URL slug | `sanitize_title()` |
| 密碼 | `wp_hash_password()` |
| HTML 輸出（Banner、文案） | `wp_kses_post()` |
| URL 輸出 | `esc_url()` |
| 屬性輸出 | `esc_attr()` |
| 純文字輸出 | `esc_html()` |

### 6.8 SQL 注入防範

- 禁用 `$wpdb->query("SELECT ... " . $user_input)` 字串拼接
- 所有自訂 SQL 用 `$wpdb->prepare()` 占位符
- 結算頁複雜 join 優先用 `wc_get_order_items()` + 後處理

### 6.9 CSV 匯出公式注入防範

- 任何欄位開頭是 `=`、`+`、`-`、`@` → 前綴單引號 `'`
- 用 `fputcsv()` 標準寫法

### 6.10 短代碼安全

```php
[profit_shop id="123" columns="2"]
```

- `id` 強制 `intval()`
- 其他屬性用 `shortcode_atts()` 帶白名單
- 渲染前驗證該 shop 為 `publish` 且 `mode='shortcode'`（**頁面模式賣場不能用短代碼渲染**）

### 6.11 CPT slug 修改的衝突檢查

衝突來源（按嚴重度）：

1. **WP 保留字**：`wp-admin`、`wp-json`、`wp-content`、`wp-includes`、`feed`、`comments`、`search`、`author`、`category`、`tag`、`page`
2. **WooCommerce 核心 page slugs**：`shop`、`cart`、`checkout`、`my-account`、`product`、`product-category`、`product-tag`（`wc_get_page_id()` 動態抓）
3. **其他已註冊 CPT** rewrite slug
4. **既有 page slugs**（撈所有 publish/draft `page` 的 `post_name`）
5. **其他自訂 rewrite rules**（`$wp_rewrite->rules` prefix）

衝突回 422：

```json
{
  "code": "slug_conflict",
  "message": "URL 前綴衝突",
  "data": {
    "slug": "shop",
    "conflicts_with": [
      {"type": "wc_page", "label": "WooCommerce 商店頁", "url": "/shop/"},
      {"type": "page", "id": 42, "label": "店面介紹"}
    ]
  }
}
```

前端 UX：debounce 呼叫 `profit-settings/validate-slug?slug=xxx` → 即時顯示「✅ 可用」「❌ 已被 WooCommerce 商店頁佔用」。

變更後**自動 `flush_rewrite_rules()`**。

---

## 7. 錯誤處理 + 觀測性

### 7.1 統一 API 錯誤格式

沿用 power-shop 既有 `{ code, message, data }`：

```json
{
  "code": "shop_not_found",
  "message": "找不到指定的分潤賣場",
  "data": { "shop_id": 999, "status": 404 }
}
```

### 7.2 Error Code 清單

| HTTP | Code | 觸發場景 |
|------|------|---------|
| 400 | `invalid_input` | 必填欄位缺失 / 型別錯 |
| 400 | `invalid_slug` | URL slug 含非法字元 |
| 401 | `not_authenticated` | 沒帶 nonce / token 過期 |
| 401 | `invalid_credentials` | Partner 密碼錯 |
| 403 | `forbidden` | capability 不足 / token 對不上 partner |
| 404 | `shop_not_found` | 賣場不存在 / 已 trash |
| 404 | `partner_not_found` | partner term 不存在 |
| 410 | `product_removed_from_shop` | 商品已從賣場移除 |
| 410 | `variation_invalid` | Variation 不存在 / 商品類型已改 |
| 422 | `slug_conflict` | rewrite slug 衝突 |
| 422 | `partner_in_use` | 刪除 partner 但還掛在賣場上 |
| 422 | `paid_cannot_unmark` | 已撥款不能改 pending |
| 429 | `too_many_attempts` | 登入失敗 5+ 次 |
| 429 | `rate_limited` | 加購超過 60 次/分 |
| 500 | `internal_error` | 未預期錯誤（不洩漏細節） |

### 7.3 各層錯誤映射

```
Domain Exception         → Application       → Presentation (REST)
ProfitShopNotFound       → throw             → 404 shop_not_found
InvalidProfitRate        → throw             → 400 invalid_input
PartnerStillInUseException → throw           → 422 partner_in_use
SlugConflictException(conflicts[]) → throw   → 422 slug_conflict + data.conflicts_with

WP/WC Error              → Application       → Presentation
WP_Error from wp_check_password → catch+log → 401 invalid_credentials
WC_Order_Refunded hook   → recordSettlementChange → (背景處理)
PHPMailer fail           → log warning, swallow   → 不影響主流程
```

> Domain 層拋的 Exception 是純 PHP `\DomainException` 子類，不直接含 HTTP status；Presentation 層 controller 統一 catch + map。

### 7.4 Logging

使用 `J7\WpUtils\Classes\WC` logger（power-shop 既有）。

| Level | 使用時機 |
|-------|---------|
| ERROR | API 500、Domain Exception 未預期 |
| WARN | Rate limit 超標、登入暴破嘗試、退款 after paid |
| INFO | 賣場建立、結算成功、匯入完成 |
| DEBUG | 加購流程、價格計算（僅 dev） |

### 7.5 Audit Log（業務必要）

獨立於技術 log。

| 操作 | 記錄位置 |
|------|---------|
| Settle（標記已撥款） | `_partner_settled_log` term meta |
| Refund after paid | 同上，type=`refund_after_paid` |
| Migration import | `wp_options` `_profit_migration_log` |
| Slug 修改 | `_profit_settings_history` option |
| Partner 密碼重設 | `_partner_password_changed_at` term meta |

`_partner_settled_log` 結構：

```json
[
  {"date": "2026-04-30", "type": "settle", "total": 15000, "item_count": 23, "by": 1},
  {"date": "2026-05-01", "type": "refund_after_paid",
   "order_id": 1234, "order_item_id": 5678, "amount": 100, "note": "需賣場主處理"}
]
```

### 7.6 前端錯誤 UX

| 錯誤類型 | UX |
|---------|-----|
| 表單驗證錯（400） | Ant Design `Form.Item.help` 紅字 |
| Slug 衝突（422） | 輸入框下方紅字 + 列出衝突來源 |
| 賣場 404 | Empty state「賣場不存在或已刪除」 |
| 加購失敗 410 | Toast + 自動 refresh 商品列表 |
| Token 過期 | 自動跳回登入頁 + Toast |
| Rate limit 429 | Toast「操作太頻繁」+ 倒數 |
| 500 | Modal「系統錯誤」+ Error ID（用於對 log） |

### 7.7 Error ID 格式

`PS-{timestamp}-{rand4}`，例：`PS-1746480000-A3F2`，用於 support 對 log。

### 7.8 監控指標

- **Settle 成功率**：每次 settle batch 成功/失敗 ratio，記到 `_settlement_metrics` option
- **Rate-limit 觸發次數**：每日總計，admin notice「異常流量」
- **退款 after-paid 警示**：超過 N 筆未處理 → admin dashboard 顯眼通知

### 7.9 退款處理 SOP

1. 後台 `/profit-settlements?status=refund_after_paid` 看清單
2. 對該筆 line item 點「人工沖回」按鈕 → paid → refunded（記 audit + log 操作者）
3. **不**自動扣 KOL 已撥款項（業務決策需人工）

---

## 8. 測試策略

> 主力 **Integration Tests + wp-env**，沿用 `power-course` 專案的測試 pattern。E2E 只做關鍵動線。CI 在 GitHub Actions matrix 跑（含 HPOS on/off 兼容）。

### 8.1 測試金字塔

```
                     E2E (Playwright) — 5-8 條主動線
              Integration (PHPUnit + wp-env) — ~30 條
        Unit (PHPUnit, Domain pure) — ~50 條（Domain 層 100% 純 PHP）
              Frontend (Vitest) — ~20 條
```

### 8.2 wp-env 設定（參考 power-course）

`.wp-env.json`：

```json
{
  "core": "WordPress/WordPress#6.8",
  "phpVersion": "8.2",
  "port": 8896,
  "testsPort": 8894,
  "plugins": [
    "https://downloads.wordpress.org/plugin/woocommerce.zip",
    "https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip",
    "."
  ],
  "themes": [],
  "config": {
    "WP_DEBUG": false,
    "WP_ENVIRONMENT_TYPE": "local"
  },
  "mappings": {
    "wp-content/uploads": "./tests/e2e/.uploads"
  },
  "lifecycleScripts": {
    "afterStart": "npx wp-env run cli -- wp option update blogname ProfitShopE2E && npx wp-env run cli -- wp rewrite structure /%postname%/ && npx wp-env run cli -- wp rewrite flush"
  }
}
```

### 8.3 PHPUnit 設定

`phpunit.xml.dist`（參考 power-course）：

```xml
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">./tests/Unit/</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">./tests/Integration/</directory>
        </testsuite>
    </testsuites>
    <groups>
        <include>
            <group>smoke</group>
            <group>happy</group>
            <group>error</group>
            <group>edge</group>
            <group>security</group>
        </include>
    </groups>
    <php>
        <env name="WP_PHPUNIT__TESTS_CONFIG" value="tests/wp-tests-config.php"/>
        <env name="ALLOW_UPDATE" value="1"/>
        <env name="ALLOW_DELETE" value="1"/>
    </php>
</phpunit>
```

### 8.4 目錄結構

```
tests/
├── bootstrap.php
├── wp-tests-config.php
├── Unit/
│   ├── Domain/
│   │   ├── ValueObject/
│   │   │   ├── PriceOverrideTest.php
│   │   │   ├── ProfitRateTest.php
│   │   │   └── PartnerSlugTest.php
│   │   ├── Service/
│   │   │   ├── PriceCalculatorTest.php
│   │   │   └── ProfitCalculatorTest.php
│   │   └── Entity/
│   │       ├── ProfitShopTest.php
│   │       └── SettlementRecordTest.php
├── Integration/
│   ├── TestCase.php                     # 共用基底
│   ├── ProfitShop/
│   │   ├── ProfitShopCRUDTest.php
│   │   ├── BulkOperationsTest.php
│   │   └── DuplicateTest.php
│   ├── Partner/
│   │   ├── PartnerCRUDTest.php
│   │   └── PartnerInUseTest.php
│   ├── Cart/
│   │   ├── AddToCartTest.php
│   │   ├── PriceOverrideHookTest.php
│   │   ├── PriceTamperTest.php
│   │   └── MultiPartnerCartTest.php
│   ├── Order/
│   │   ├── LineItemMetaTest.php
│   │   ├── RefundHookTest.php
│   │   ├── CancelledHookTest.php
│   │   └── PartialRefundTest.php
│   ├── Subscription/
│   │   ├── RenewalAttributionTest.php
│   │   └── DynamicRateTest.php
│   ├── Auth/
│   │   ├── PartnerAuthTest.php
│   │   ├── BruteForceTest.php
│   │   └── TokenSecurityTest.php
│   ├── Reports/
│   │   ├── PartnerReportsScopeTest.php
│   │   └── KpiCalculationTest.php
│   ├── Settlement/
│   │   ├── MarkPaidTest.php
│   │   ├── ReversePaidTest.php
│   │   └── CsvExportTest.php
│   ├── Migration/
│   │   └── LegacyImporterTest.php
│   ├── Settings/
│   │   ├── SlugConflictTest.php
│   │   └── FlushRewriteTest.php
│   └── HPOS/
│       └── HposCompatTest.php           # 開 HPOS 跑訂單相關測試
├── e2e/
│   ├── playwright.config.ts
│   ├── fixtures/
│   ├── specs/
│   │   ├── create-shop.spec.ts
│   │   ├── page-mode-purchase.spec.ts
│   │   ├── shortcode-purchase.spec.ts
│   │   ├── multi-partner-cart.spec.ts
│   │   ├── partner-report.spec.ts
│   │   ├── settlement-flow.spec.ts
│   │   ├── migration.spec.ts
│   │   └── slug-conflict.spec.ts
│   └── .uploads/
└── Fixtures/
    ├── products/
    │   ├── simple_product.php
    │   ├── variable_product.php
    │   └── subscription_product.php
    └── shops/
        ├── basic_page_shop.php
        └── multi_partner.php
```

### 8.5 Unit Tests（Domain 純邏輯）

| 測試對象 | 覆蓋情境 |
|---------|---------|
| `ValueObject\PriceOverride` | 負數 / 0 / 浮點數 / null fallback |
| `ValueObject\ProfitRate` | <0、>100、邊界 0、100、小數 |
| `ValueObject\PartnerSlug` | 合法字元、UTF-8、空字串、太長、保留字 |
| `Service\PriceCalculator` | **fallback chain 5 層全覆蓋** + 訂閱 signup_fee 路徑 |
| `Service\ProfitCalculator` | actual × qty × rate / 100 各邊界、四捨五入策略 |
| `Entity\ProfitShop` | 加商品、移商品、改 override、partner 變更 |
| `Entity\SettlementRecord` | status transition：pending→paid、paid→refunded、cancelled 不可回 pending |

### 8.6 Integration Tests

| 測試套組 | 覆蓋情境 |
|---------|---------|
| ProfitShopCRUD | POST/PUT/GET/DELETE/duplicate/bulk |
| Partner CRUD | 建/改/刪、密碼雜湊、刪除被使用中的 partner 拒絕 |
| AddToCart | simple/variable/subscription 加購、商品已下架 410、賣場 unpublish 404、價格被竄改不生效 |
| PriceOverride hook | `before_calculate_totals` 套價、無 override 不影響、混 cart |
| OrderLineItem hook | order_itemmeta 寫入正確、`_profit_amount` 計算對 |
| Refund hook | pending→refunded、paid→admin notice |
| Cancelled hook | pending→cancelled、paid 不變動 |
| Subscriptions renewal | renewal order 帶 attribution、rate 重抓最新值、賣場已刪 fallback |
| PartnerAuth | 登入 / 密碼錯計數 / 5 次失敗鎖定 / token 驗證 / 登出 |
| PartnerReports | token 驗證、partner_id 範圍鎖死（不能跨 partner 看資料） |
| Settlements | mark-paid / paid 不能 unmark / CSV export 格式（BOM、中文、`=` 逃逸） |
| Migration | 從舊 power-shop 匯入、items 結構正確 mapping、不刪舊版 |
| SlugConflict | 各種衝突來源（保留字、page、CPT、WC pages） |
| HPOSCompat | 開啟 HPOS 跑全套訂單測試 |

### 8.7 E2E Tests（Playwright）— 關鍵動線

| 主動線 | 步驟 |
|--------|------|
| 建立賣場 | admin 登入 → 進 power-shop → 建 partner → 建賣場 → 挑商品 → 設覆寫價 → 儲存 → 預覽 |
| 頁面模式買家動線 | 開 `/powershop/{slug}/` → 看到覆寫價 + 灌水銷售數 → 加購 → 結帳 → 訂單成立 → 後台確認歸屬 |
| 短代碼模式買家動線 | 在 page 貼 `[profit_shop id]` → 前台看到列表 → 加購 → 結帳 |
| 跨 partner 同 cart | 兩賣場各加一商品 → cart 拆兩 line（後台驗證歸屬） |
| Partner 報表 | 開 `/profit-report/jerry/` → 錯密碼 5 次鎖定 → 改密碼 → 正確登入 → KPI、filter、訂單明細 |
| 結算流程 | 訂單成立 → settlements → mark paid → 退款後變 refund_after_paid → 人工沖回 |
| Migration | 開匯入頁 → 選舊賣場 → 匯入 → 新賣場驗證商品清單 |
| Slug 衝突 | 改 rewrite slug 為 `shop` → 顯示衝突 → 改其他成功 + flush |

### 8.8 Frontend Tests（Vitest）

| 測試對象 | 覆蓋 |
|---------|------|
| `useProfitShop` hook | data 正確 reshape |
| `ProductOverrideTable` 同步模式 | 開啟同步 → 改一列 → 全部勾選列同步、4 種同步模式（固定/+-/×/清空） |
| `PartnerLoginPage` | 表單驗證、錯密碼提示、成功重導 |
| `SettlementBatchAction` | 勾選 mark-paid、樂觀更新 + rollback |

### 8.9 CI/CD（GitHub Actions Matrix）

`.github/workflows/phpunit-matrix.yml`（沿用 power-course 模式）：

```yaml
name: PHPUnit Matrix

on:
  pull_request:
    branches: [master]
    paths:
      - 'inc/**'
      - 'tests/**'
      - 'composer.json'
      - 'composer.lock'
      - 'phpunit.xml.dist'
      - '.github/workflows/phpunit-matrix.yml'
  workflow_dispatch:

concurrency:
  group: phpunit-matrix-${{ github.ref }}
  cancel-in-progress: true

jobs:
  phpunit:
    name: PHPUnit (${{ matrix.scenario }})
    runs-on: ubuntu-latest
    timeout-minutes: 25
    strategy:
      fail-fast: false
      matrix:
        include:
          - scenario: with-wc-classic         # HPOS off
            wc: '1'
            hpos: '0'
            subscriptions: '0'
            phpunit_args: '--group smoke --group happy --group error --group edge --group security --exclude-group hpos'
          - scenario: with-wc-hpos            # HPOS on
            wc: '1'
            hpos: '1'
            subscriptions: '0'
            phpunit_args: '--group smoke --group happy --group error --group edge --group security --exclude-group classic-only'
          - scenario: with-subscriptions      # 訂閱（local-only verify skip）
            wc: '1'
            hpos: '1'
            subscriptions: '1'
            phpunit_args: '--group subscription'

    steps:
      - uses: actions/checkout@v5
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - uses: pnpm/action-setup@v4
      - run: pnpm install --frozen-lockfile
      - run: composer install --no-interaction --prefer-dist
      - run: |
          mkdir -p wp-content/uploads
          chmod -R 777 wp-content/uploads
      - name: Start wp-env
        run: |
          for i in 1 2 3; do
            npx wp-env start && break || sleep 10
          done
      - name: Install WooCommerce
        if: matrix.wc == '1'
        run: npx wp-env run tests-cli -- bash -c "wp plugin install woocommerce --activate --allow-root || true"
      - name: Enable HPOS
        if: matrix.hpos == '1'
        run: npx wp-env run tests-cli -- bash -c "wp option update woocommerce_custom_orders_table_enabled yes"
      - name: Note WC Subscriptions
        if: matrix.subscriptions == '1'
        run: echo "WC Subscriptions 為付費外掛，CI 僅驗證 skipIfSubscriptionsMissing 正確 skip。實際 subscription 測試於有授權環境執行。"
      - name: Run PHPUnit
        run: npx wp-env run tests-cli -- bash -c "cd /var/www/html/wp-content/plugins/power-shop && vendor/bin/phpunit ${{ matrix.phpunit_args }} --testdox --colors=never"
      - name: Stop wp-env
        if: always()
        run: npx wp-env stop || true
```

### 8.10 邊緣案例清單（必測）

- [ ] 同 cart 多 partner，cart hash 確認拆 line
- [ ] 加購時賣場被另一個 admin 同時刪除（race condition）
- [ ] Variation 在賣場已 override 後，商品從 variable 改 simple
- [ ] 訂單退款 partial（部分商品退）→ 對應 line item status 各自處理
- [ ] 訂閱續訂時賣場已被刪除 → fallback 到凍結 rate
- [ ] Partner term slug 含中文 / Emoji（編碼安全）
- [ ] Token 在多 tab 同時登入 / 登出
- [ ] CSV export 大筆數據（10000+ rows）不 timeout
- [ ] 灌水數為 0、為負數（視為 0）、極大值

### 8.11 覆蓋率目標

| 層級 | 目標 |
|------|------|
| Domain Unit | >= 95% |
| Application | >= 80% |
| Infrastructure | >= 60% |
| Overall PHP | >= 75% |
| Frontend critical paths | E2E 覆蓋 |

`phpunit --coverage-html` 產報告，CI 不通過 75% 拒絕 merge。

---

## 8.12 i18n 策略

| 項目 | 規範 |
|------|------|
| Text domain | `power_shop`（沿用既有 plugin text domain，**不另開**） |
| PHP 字串 | `__('...', 'power_shop')`、`esc_html__()`、`_x()`、`_n()` |
| 所有 UI 文字 | 繁體中文（zh_TW），台灣產品 |
| JSDoc / PHPDoc 註解 | 繁體中文 |
| React UI 字串 | 直接寫繁體中文（不引入 i18n 框架，沿用 power-shop 既有作法） |
| Error message | `WP_Error` message 用繁體中文，code 用 snake_case 英文（如 `shop_not_found`） |
| Email 通知（v2） | 繁體中文模板 |
| 翻譯檔 | `languages/power_shop-zh_TW.po`（既有）追加新字串 |

> 所有新增的 PHP 字串先用 `__()` 包裝（即使只有繁體中文）以保留未來 i18n 擴充彈性。

## 8.13 無障礙（Accessibility）

| 範圍 | 規範 |
|------|------|
| 後台 SPA | Ant Design v5 元件天然支援 ARIA 屬性，沿用即可。新增 form 必填欄位用 `Form.Item required` 啟用 `aria-required` |
| 前台頁面模式 / 短代碼 | 商品卡：`<button>` 不用 `<div>` 模擬點擊；圖片必填 `alt`；價格使用語義化 `<del>` `<ins>` 標記特價 |
| 鍵盤操作 | 商品 Table 支援 Tab 順序；同步修改 toggle 可用 Space 切換；批次操作 checkbox 支援 Shift+Click range select |
| Color contrast | 賣場主色（`btn_color`）儲存時不檢查對比度（保留品牌彈性），但預設 `#1677ff` 符合 WCAG AA |
| Screen reader | KPI 卡使用 `aria-label`「總業績 NT$X」而非依賴視覺；Toast 使用 `role="alert"` |
| 報表前台 | 密碼登入 form 標準 `<label for>` 綁定；錯誤訊息 `role="alert"` 立即播報 |

> v1 不追求 WCAG AAA，目標 AA 級。專業 a11y audit 列入 v2。

---

## 9. 與舊版 Legacy 共存策略

| 項目 | 舊版（保留） | 新版（新建） |
|------|------------|------------|
| Plugin loader | `Bootstrap::instance()` 載入 `J7\PowerShopV2` | `Domains\Loader::instance()` 載入 `J7\PowerShop\Domains\ProfitShop` |
| CPT | `power-shop`（kebab） | `powershop`（無連字號） |
| 設定畫面 | WP CPT metabox | power-shop SPA |
| 結帳流程 | `Cart`、`Order` class 自有 hook | 全新 hook，cart_item_data key 不撞 |
| 報表 URL | `/power-shop/{slug}/report/` | `/profit-report/{partner-slug}/` |
| 短代碼 | `[power_shop]` | `[profit_shop id="X"]` |

**不互通版型**；不互通資料；舊版繼續可用，新版獨立運作。提供 Migration Importer 一鍵匯入工具（不刪舊版）。

---

## 10. 範疇外（v1 不做，v2+ 規劃）

- 撥款自動執行（金流 API 整合）— v1 純 ledger，撥款線下處理
- KOL 自助修改密碼 — 防帳號被盜
- 多 partner per shop（M:N） — v1 是 1:1，未來如有需求再開
- IP 白名單限制報表 URL
- Email 自動寄送結算單給 KOL
- Elementor native widget — v1 用 shortcode + Gutenberg block
- Grouped / External / Bundle product 支援
- 訂閱週期 / 期間 / 試用期覆寫（v1 只覆寫 regular_price / sale_price / signup_fee）
- 自動 301 重導舊 rewrite slug

---

## 10.1 Plan 階段補充裁決（2026-05-06）

以下決策於 brainstorming 通過後、進 plan 階段時由 planner 提出 spec 缺口、用戶（老大）裁決：

| # | 議題 | 決議 | 影響章節 |
|---|------|------|----------|
| Q1 | Refine `dataProvider` key | **沿用 `'power-shop'`**，新端點路徑 hardcode `/wp-json/power-shop/v2/...`（與既有 dashboard 端點一致） | §3.1, §4 |
| Q2 | UI 上 parent override 改了，已 override 的 variation 是否連動？ | **不連動**。已覆寫的 variation 維持自己的 override；要重置須點「重置 variation override」按鈕 | §3.4 |
| Q3 | Page 模式 CPT 的 Block Editor 啟用 | **強制 `use_block_editor_for_post_type=true`**。Elementor 由其外掛接管，不另寫 fallback。Classic Editor 環境視同 Block Editor（`gutenberg_can_edit_post_type` 預設行為） | §1, §3 |
| Q4 | CSV export 編碼與分隔符 | **UTF-8 with BOM、逗號分隔**（中文 Excel 開啟標準作法）。`fputcsv()` 第一行寫 `"\xEF\xBB\xBF"` BOM | §6.9, §3.5 |
| Q5 | Partner Token TTL 設 0 行為 | **min clamp 60 秒**（後台設定 UI 限制 ≥ 60，伺服器端再驗一次） | §6.3 |
| Q6 | `_partner_settled_log` 累積膨脹 | **v1 不處理**，列入 v2 範疇（settled log 歸檔機制） | §10 |

> Q6 已加到 §10 v2+ 規劃清單。

## 10.2 v2+ 補項

- `_partner_settled_log` 累積太大時的歸檔機制（按年滾入 `_partner_settled_log_archive_{year}`）

---

## 11. 待 plan 階段細化

- DI / 依賴注入策略（poor man's DI 細節）
- 各 UseCase 的具體 method signature
- 各 Repository 的具體 query 實作
- 報表 SQL 效能評估（join order_itemmeta）
- 商品 Table 虛擬列表的具體實作參考 `ProductEditTable`
- E2E 測試的測試帳號 / fixture 細節
- WC Subscriptions 在 wp-env 的 mock / skip 策略

→ **下一步**：本 spec 經老大複審通過後，交棒 `@zenbu-powers:planner 哥` 進入實作規劃。
