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
│   └── profit-shop-item-selection.activity
├── features/                # Feature 視圖：Gherkin BDD 規格
│   ├── order/               # 訂單領域（6 個 Feature）
│   ├── product/             # 商品領域（5 個 Feature）
│   ├── customer/            # 顧客領域（5 個 Feature）
│   ├── dashboard/           # 儀表板與分析領域（4 個 Feature）
│   └── profit-shop/         # 分潤賣場領域（3 個 Feature）
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
│   └── profit-shop-edit.md
├── actors/                  # Actor 定義
│   └── shop-manager.md
├── api/                     # API 視圖：OpenAPI 3.0 規格
│   └── api.yml
├── entity/                  # Entity 視圖：DBML 資料模型
│   └── erm.dbml
├── clarify/                 # 澄清紀錄（每次需求訪談一檔）
│   └── 2026-07-29-profit-shop-product-selector.md
├── plan/                    # 工程計畫（每個需求一檔，命名 YYYY-MM-DD-{slug}-plan.md）
│   └── 2026-07-29-profit-shop-product-selector-plan.md
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

| Feature | 類型 | 說明 |
|---------|------|------|
| [select-shop-products](features/profit-shop/select-shop-products.feature) | @command | 透過商品選擇 Modal 加入賣場商品 |
| [save-shop-item-overrides](features/profit-shop/save-shop-item-overrides.feature) | @command | 儲存賣場商品的價格覆寫設定 |
| [enrich-shop-item-display](features/profit-shop/enrich-shop-item-display.feature) | @query | 補齊賣場商品的顯示資訊（名稱 / SKU） |

---

## 統計

| 類別 | 數量 |
|------|------|
| Activity 流程 | 5 |
| Feature 規格 | 23（14 @command + 9 @query） |
| UI 頁面規格 | 10 |
| Actor 定義 | 1 |
| API 端點 | 見 [api.yml](api/api.yml) |
| 資料表 | 9（見 [erm.dbml](entity/erm.dbml)） |

---

## 已知問題與技術債

跨領域、暫不處理的已知問題（測試環境缺口、CI 設定、第三方套件契約落差、既有 UX 限制等）統一登記在 [tech-debt.md](tech-debt.md)，不重複列在各領域 Feature / UI 文件中。單一領域內的已知落差（例如列表頁版型尚未對齊）記在對應的 `.claude/rules/*.rule.md`；兩邊互相交叉索引，不重複維護內容。
