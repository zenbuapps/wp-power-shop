# Power Shop 規格文件

> 系統：Power Shop WordPress Plugin（WooCommerce 擴充後台）
> 版本：3.0.12
> 最後更新：2026-03

本目錄包含 Power Shop 的完整規格文件，採用 AIBDD Discovery 多視圖架構。

---

## 目錄結構

```
specs/
├── activities/              # Activity 視圖：業務流程
│   ├── order-management.activity
│   ├── product-management.activity
│   ├── customer-management.activity
│   ├── dashboard-analytics.activity
│   ├── profit-shop-item-selection.activity
│   ├── profit-shop-management.activity      # 站長建立與維運賣場
│   ├── profit-shop-purchase.activity        # 顧客在賣場購買（含四道分潤閘門）
│   └── partner-authentication.activity      # 夥伴登入與查看報表
├── features/                # Feature 視圖：Gherkin BDD 規格
│   ├── order/               # 訂單領域（6 個 Feature）
│   ├── product/             # 商品領域（5 個 Feature）
│   ├── customer/            # 顧客領域（5 個 Feature）
│   ├── dashboard/           # 儀表板與分析領域（4 個 Feature）
│   └── profit-shop/         # 分潤賣場領域（30 個 Feature）
├── ui/                      # UI 視圖：頁面規格
│   ├── dashboard.md
│   ├── order-list.md
│   ├── order-edit.md
│   ├── product-list.md
│   ├── product-edit.md
│   ├── product-taxonomies.md
│   ├── customer-list.md
│   ├── customer-edit.md
│   ├── analytics.md
│   ├── profit-shop-edit.md
│   ├── profit-shop-front.md                 # 賣場前台（customer-facing）
│   └── partner-portal.md                    # 夥伴自助報表（partner-facing）
├── actors/                  # Actor 定義
│   └── shop-manager.md
├── api/                     # API 視圖：OpenAPI 3.0 規格
│   └── api.yml
├── entity/                  # Entity 視圖：DBML 資料模型
│   └── erm.dbml
├── clarify/                 # 澄清紀錄（每次需求訪談一檔）
│   └── 2026-07-29-profit-shop-product-selector.md
├── plan/                    # 工程計畫（每個需求一檔，命名 YYYY-MM-DD-{slug}-plan.md）
│   ├── 2026-07-29-profit-shop-product-selector-plan.md
│   ├── 2026-07-30-profit-shop-variation-override-plan.md
│   ├── 2026-08-03-cart-regular-price-override-plan.md
│   └── 2026-08-06-profit-settlement-chain-plan.md   # 分潤結算全鏈路 TDD 協調藍圖
└── tech-debt.md             # 已知問題與技術債登記表（跨領域、持續累積）
```

---

## 領域概覽

### 訂單管理 (Order)

| Feature | 類型 | 說明 |
|---------|------|------|
| [create-order](features/order/create-order.feature) | @command | 後台手動建立訂單 |
| [update-order-status](features/order/update-order-status.feature) | @command | 變更訂單狀態 |
| [edit-order-address](features/order/edit-order-address.feature) | @command | 修改帳單/運送地址 |
| [add-order-note](features/order/add-order-note.feature) | @command | 新增訂單備註 |
| [bulk-delete-orders](features/order/bulk-delete-orders.feature) | @command | 批量刪除訂單 |
| [query-order-list](features/order/query-order-list.feature) | @query | 查詢訂單列表 |

### 商品管理 (Product)

| Feature | 類型 | 說明 |
|---------|------|------|
| [create-draft-product](features/product/create-draft-product.feature) | @command | 快速建立草稿商品 |
| [save-product-data](features/product/save-product-data.feature) | @command | 儲存商品完整資料 |
| [generate-product-variations](features/product/generate-product-variations.feature) | @command | 自動生成變體 |
| [create-product-category](features/product/create-product-category.feature) | @command | 新增商品分類 |
| [query-product-list](features/product/query-product-list.feature) | @query | 查詢商品列表 |

### 顧客管理 (Customer)

| Feature | 類型 | 說明 |
|---------|------|------|
| [edit-customer-profile](features/customer/edit-customer-profile.feature) | @command | 編輯顧客基本資料 |
| [edit-customer-user-meta](features/customer/edit-customer-user-meta.feature) | @command | 編輯 UserMeta（高風險） |
| [add-customer-note](features/customer/add-customer-note.feature) | @command | 新增聯繫備註 |
| [query-customer-list](features/customer/query-customer-list.feature) | @query | 查詢顧客列表 |
| [query-customer-detail](features/customer/query-customer-detail.feature) | @query | 查詢顧客詳情 |

### 儀表板與分析 (Dashboard)

| Feature | 類型 | 說明 |
|---------|------|------|
| [query-dashboard-kpi](features/dashboard/query-dashboard-kpi.feature) | @query | KPI 統計（營收、新客、訂單） |
| [query-leaderboard](features/dashboard/query-leaderboard.feature) | @query | 商品/顧客排行榜 |
| [query-revenue-trend](features/dashboard/query-revenue-trend.feature) | @query | 營收趨勢圖表 |
| [query-revenue-analytics](features/dashboard/query-revenue-analytics.feature) | @query | 多維度營收分析 |

### 分潤賣場 (Profit Shop)

> Domain 設計文件：[2026-05-06-profit-shop-design.md](2026-05-06-profit-shop-design.md)
> 架構與紀律：`.claude/rules/profit-shop.rule.md`

> ✅ **分潤結算全鏈路：已完工（2026-08-06，Phase 17）**
>
> 在此之前，分潤結算的**寫入鏈路完全未實作**——`OrderItemSettlementRepository`
> 與 `SettlementRecord` 存在且有整合測試，但從未被註冊或呼叫，購物車上的
> `_profit_*` meta 不會被複製到 `wc_order_itemmeta`，因此夥伴報表的查詢側
> 雖已完整實作，實際上永遠回傳空資料，站長也無法撥款。
>
> 依 [`plan/2026-08-06-profit-settlement-chain-plan.md`](plan/2026-08-06-profit-settlement-chain-plan.md)
> 分階段實作完成，下方「分潤結算」小節的 7 個 Feature 現在描述的是**實際行為**：
>
> | Stage | 內容 | 對應 Feature | 狀態 |
> |---|---|---|---|
> | 1 | 結帳寫入 order itemmeta | attribute-order-line-item | ✅ |
> | 2 | 退款 / 取消回沖 | reverse-settlement-on-refund | ✅ |
> | 3 | 訂閱續訂帶入 | carry-profit-to-renewal-order | ✅（wp-env 未安裝 WooCommerce Subscriptions，`test_real_wcs_renewal_order_resets_paid_status` 恆 skip，只在單元/整合層級以手工 fixture 驗證，未真正呼叫過 `wcs_create_renewal_order()`） |
> | 4 | 結算 Application 層 + 7 個 REST endpoint | query-settlements / mark-settlement-paid / export-settlements-csv | ✅ |
> | 5 | 結算頁 SPA `/profit-settlements` | query-settlements / mark-settlement-paid | ✅ |
> | 6 | 訂單頁分潤資訊面板 + 訂單列表賣場過濾 | view-order-profit-panel | ✅ |
>
> 藍圖另指出三個必須先修的**既有地基缺陷**（結帳當下訂單品項尚無 id、`RoundingStrategy`
> 全專案零實作、結算 SQL 未過濾訂單型別導致訂閱品項雙倍計算），三者皆已修補，正確行為
> 已寫進上述 Feature，完整技術細節見 `.claude/rules/profit-shop.rule.md` §13。
>
> ✅ **真實站台端到端驗證已通過**（2026-08-06，站台 `turbo.local`）：加購 → 結帳
> → 訂單 #2241 line item #428 分潤 meta 完整落地（`_profit_rate=10`、
> `_actual_price=600`、`_profit_amount=60`、`_settlement_status=pending`）→
> 結算查詢確實撈得到該筆。三組 meta 命名對映、`$unique=true` 去重、HMAC 驗章、
> `_profit_rate` 即時讀賣場當下值全部在真實環境確認正確。完整對照表見
> `.claude/CLAUDE.md`「Phase 17」章節；該筆訂單刻意保留供後續 UI 驗證使用。
>
> ✅ **wp-admin UI 已於 2026-08-07 補驗通過**：結算頁（4 張摘要卡 + 列表 + 篩選）、
> 訂單頁分潤資訊面板、標記已撥款的二次確認 Modal、CAS 撥款寫入
> （`_settlement_status` 轉為 `paid`、`_settled_at` / `_settled_by` 寫入、
> partner termmeta `_partner_settled_log` 格式與設計 §5.5 一致）全部實測正常。
> 詳見 `.claude/CLAUDE.md`「Phase 17」章節的驗證表。
>
> 🔴 **尚未驗證的部分**：(1) 站台**未啟用 HPOS**，本次驗證只涵蓋 legacy posts
> 路徑，HPOS 雙路徑的 HPOS 那一半完全沒有任何形式的驗證（自動化測試與真實站台
> 皆未覆蓋，見 `specs/tech-debt.md` TD-88）；(2) 夥伴報表前台
> （`/profit-report/{slug}/`）需夥伴密碼，尚未驗證；
> (3) 訂閱續訂路徑（`test_real_wcs_renewal_order_resets_paid_status`）在 wp-env
> 因未安裝 WooCommerce Subscriptions 恆 skip，只在單元/整合層級以手工 fixture
> 驗證。詳見 `.claude/CLAUDE.md`「Phase 17」章節。

#### 加購與定價（安全與金流核心）

| Feature | 類型 | 說明 |
|---------|------|------|
| [attribute-cart-item-to-shop](features/profit-shop/attribute-cart-item-to-shop.feature) | @command | 加購時的四道分潤歸屬閘門 |
| [apply-cart-override-price](features/profit-shop/apply-cart-override-price.feature) | @command | 覆寫價套用與 HMAC 簽章版本遷移（v1/v2/v3） |
| [authorize-priceless-cart-item](features/profit-shop/authorize-priceless-cart-item.feature) | @command | 無定價商品的 per-cart-item 授權（四道 WooCommerce 閘門） |
| [apply-single-product-override-price](features/profit-shop/apply-single-product-override-price.feature) | @query | 商品單頁的覆寫價一致性與快取隔離 |

#### 賣場管理

| Feature | 類型 | 說明 |
|---------|------|------|
| [save-profit-shop](features/profit-shop/save-profit-shop.feature) | @command | 建立與更新賣場本體 |
| [change-shop-status](features/profit-shop/change-shop-status.feature) | @command | 上架 / 下架 / 複製 / 刪除 |
| [select-shop-products](features/profit-shop/select-shop-products.feature) | @command | 透過商品選擇 Modal 加入賣場商品 |
| [save-shop-item-overrides](features/profit-shop/save-shop-item-overrides.feature) | @command | 儲存賣場商品的價格覆寫設定（商品層） |
| [save-variation-overrides](features/profit-shop/save-variation-overrides.feature) | @command | 設定變體層價格與灌水購買人數 |
| [schedule-shop-availability](features/profit-shop/schedule-shop-availability.feature) | @command | 開啟關閉時間與期間外呈現 |
| [import-legacy-shop](features/profit-shop/import-legacy-shop.feature) | @command | 匯入舊版一頁商店（不可逆） |
| [query-shop-detail](features/profit-shop/query-shop-detail.feature) | @query | 查詢賣場詳情與列表 |
| [validate-shop-slug](features/profit-shop/validate-shop-slug.feature) | @query | 永久連結的格式與七類衝突檢查 |
| [enrich-shop-item-display](features/profit-shop/enrich-shop-item-display.feature) | @query | 補齊賣場商品的顯示資訊（名稱 / SKU） |

#### 分潤夥伴

| Feature | 類型 | 說明 |
|---------|------|------|
| [manage-profit-partner](features/profit-shop/manage-profit-partner.feature) | @command | 夥伴 CRUD、圖片與刪除前置檢查 |
| [manage-partner-credentials](features/profit-shop/manage-partner-credentials.feature) | @command | 站長端的密碼路徑與明文並存 |
| [login-partner](features/profit-shop/login-partner.feature) | @command | 夥伴登入、雙維度速率限制與 token |
| [change-partner-password](features/profit-shop/change-partner-password.feature) | @command | 夥伴自助修改密碼 |
| [query-partner-report](features/profit-shop/query-partner-report.feature) | @query | KPI / 趨勢 / 結算明細與範圍隔離 |

#### 分潤結算（實作中，見上方說明）

| Feature | 類型 | 說明 |
|---------|------|------|
| [attribute-order-line-item](features/profit-shop/attribute-order-line-item.feature) | @command | 結帳寫入 11 筆結算 meta、命名對映與兩條結帳路徑 |
| [reverse-settlement-on-refund](features/profit-shop/reverse-settlement-on-refund.feature) | @command | 退款與取消訂單的回沖規則（含部分退款重算） |
| [carry-profit-to-renewal-order](features/profit-shop/carry-profit-to-renewal-order.feature) | @command | 訂閱續訂單的凍結、動態與強制重置欄位 |
| [mark-settlement-paid](features/profit-shop/mark-settlement-paid.feature) | @command | 撥款 / 取消 / 人工沖回與併發保護 |
| [query-settlements](features/profit-shop/query-settlements.feature) | @query | 結算列表與摘要、篩選與分頁上限 |
| [export-settlements-csv](features/profit-shop/export-settlements-csv.feature) | @query | CSV 匯出與公式注入防範 |
| [view-order-profit-panel](features/profit-shop/view-order-profit-panel.feature) | @query | 訂單頁分潤面板與訂單列表賣場過濾 |

#### 賣場前台

| Feature | 類型 | 說明 |
|---------|------|------|
| [add-to-cart-from-modal](features/profit-shop/add-to-cart-from-modal.feature) | @command | 商品 Modal 的非同步加購與降級路徑 |
| [render-shop-front](features/profit-shop/render-shop-front.feature) | @query | 賣場前台呈現與存取控制 |
| [render-shop-via-shortcode](features/profit-shop/render-shop-via-shortcode.feature) | @query | 短碼嵌入與兩條路徑的 DOM 一致性 |
| [select-product-variation](features/profit-shop/select-product-variation.feature) | @query | 變體比對（任意屬性與具體度計分） |

---

## 統計

| 類別 | 數量 |
|------|------|
| Activity 流程 | 8 |
| Feature 規格 | 50（31 @command + 19 @query） |
| UI 頁面規格 | 12 |
| Actor 定義 | 1 |
| API 端點 | 見 [api.yml](api/api.yml) |
| 資料表 | 9（見 [erm.dbml](entity/erm.dbml)） |

---

## 已知問題與技術債

跨領域、暫不處理的已知問題（測試環境缺口、CI 設定、第三方套件契約落差、既有 UX 限制等）統一登記在 [tech-debt.md](tech-debt.md)，不重複列在各領域 Feature / UI 文件中。單一領域內的已知落差（例如列表頁版型尚未對齊）記在對應的 `.claude/rules/*.rule.md`；兩邊互相交叉索引，不重複維護內容。
