# 分潤賣場編輯頁面

> 路由：`#/profit-shop/edit/{id}`
> Data Provider：賣場本體 `power-shop`（`useCustom` / `useCustomMutation`，見 CLAUDE.md Pitfall #10）

## 頁面說明

編輯單一分潤賣場的基本設定與賣場商品。
商品選擇由**單一下拉 selector** 改為**按鈕開啟 Modal + 完整篩選器 + 分頁列表**，
以支撐 10 萬筆商品規模的候選集篩選。

## 功能區塊

### 1. 賣場基本設定（既有，不變更）

| 欄位 | 元件 | 說明 |
|------|------|------|
| 賣場名稱 | Input | 必填，≤ 200 字元 |
| Slug | `SlugInput` | 即時 5 類衝突檢查 |
| 狀態 | Radio.Group | draft / publish |
| 呈現模式 | Radio.Group | page / shortcode |
| 分潤夥伴 | `PartnerSelector` | 必填 |
| 分潤比例 | Slider + InputNumber | 0-100 |

### 2. 賣場商品區塊（本次重構主體）

```
┌──────────────────────────────────────────────┐
│ 賣場商品                                       │
│  [ 選擇商品 ]  ← Button，開啟商品選擇 Modal    │
│  ┌────────────────────────────────────────┐  │
│  │ Table（已加入的商品）                    │  │
│  │  商品 / 原價覆寫 / 特價覆寫 / 浮報數 / 移除│  │
│  └────────────────────────────────────────┘  │
└──────────────────────────────────────────────┘
```

| 元素 | 類型 | 說明 |
|------|------|------|
| 選擇商品 | Button（`type="primary"` + `PlusOutlined`） | 置於 Table 之上，`className="mb-4"` |
| 賣場商品 Table | Table | 見下表欄位；空狀態 `<Empty>` 引導點擊「選擇商品」 |

賣場商品 Table 欄位：

| 欄位 | 寬度 | 說明 |
|------|------|------|
| 商品 | 260px | 名稱 + `#id` + SKU；名稱/SKU 由 `wc-rest` 補齊（見 [enrich-shop-item-display](../features/profit-shop/enrich-shop-item-display.feature)）；商品已刪除時顯示「商品已失效」 |
| 原價覆寫 | 160px | InputNumber，留空 = 沿用原價 |
| 特價覆寫 | 160px | InputNumber，留空 = 沿用原價；> 原價覆寫時前端擋下 |
| 浮報數 | 130px | InputNumber，min 0 |
| 操作 | 90px | 移除（`fixed: 'right'`） |

> `signup_fee`（訂閱型註冊費）與 per-variation 覆寫在契約中保留欄位，本階段 UI 不開放。

### 3. 商品選擇 Modal（`ProductPickerModal`）

| 項目 | 值 |
|------|-----|
| 元件 | `SimpleModal` + `useSimpleModal`（`antd-toolkit`） |
| 開關 | `useSimpleModal` 的 `show()` / `close()`（`SimpleModal` **無 `open` prop**） |
| 既有參考實作 | `js/src/components/term/TaxonomyModal/` |
| 標題 | 「選擇商品」 |
| footer | 「加入賣場 (N)」primary 按鈕；N = 0 時 `disabled` |
| Data Provider | `wc-rest`（`useTable`，server-side 篩選 + 分頁） |

Modal 內容版型（遵循 `.claude/rules/admin-list-ui.rule.md` §1）：

```
┌──────────────────────────────────────────────┐
│ SimpleModal  title="選擇商品"                  │
│ ┌──────────────────────────────────────────┐ │
│ │ Card title="篩選"                  mb-4  │ │
│ │  <ProductFilter />  + <FilterTags />     │ │
│ └──────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────┐ │
│ │ Card                                     │ │
│ │  <Table rowSelection 分頁 />              │ │
│ │  ✗ 無「新增商品」按鈕                      │ │
│ └──────────────────────────────────────────┘ │
│ ────────────────────────────────────────────  │
│ footer:  已選 N 筆   [ 加入賣場 (N) ]         │
└──────────────────────────────────────────────┘
```

Modal 內的行為差異（相對 `#/products` 頁）：

| 差異點 | `#/products` 頁 | Modal 內 |
|--------|----------------|----------|
| 新增商品按鈕 | 有 `<CreateButton>` | **無** |
| 批次操作 | `<ActionArea>` + `<BulkAction>`（fixed 底部） | **無**，改用 Modal `footer` |
| 選取狀態 | Jotai `selectedProductsAtom`（module-level 全域） | **獨立、隔離的選取狀態**，不得共用該 atom |
| 已在賣場的商品 | — | 標記「已加入」Tag，checkbox `disabled` |
| 變體子列 | checkbox `disabled` | 同左（本階段不支援選變體） |
| 操作欄 | 有 `<ProductActions>` | 不需要 |

## dataProvider 分工（勿誤讀 Pitfall #10）

同一頁面內兩條資料路線並存，**互不干涉**：

| 區塊 | dataProvider | Hook | 路線 |
|------|-------------|------|------|
| 賣場本體（讀取 / 儲存賣場） | `power-shop` | `useCustom` / `useCustomMutation` | B（`admin-list-ui.rule.md` §3.2） |
| Modal 內商品列表 | `wc-rest` | `useTable` | A（`admin-list-ui.rule.md` §3.1） |
| 賣場商品顯示資訊補齊 | `wc-rest` | `useList`（`include` 篩選） | A |

> **CLAUDE.md Pitfall #10 的約束對象是 `profit-shop` / `profit-partner` / `profit-migration` /
> `profit-settings` 這 4 個 resource**（其後端 V2Api 回 `{code, message, data}`，與 antd-toolkit
> dataProvider shape 不相容）。`products` resource 本來就走 `wc-rest`，形狀相容，
> **在 Modal 內使用 `useTable` 完全正確，不需要繞路**。

## 元件重用策略（關鍵決策）

**結論：抽共用子元件 `ProductTableBody`，不採「加 props 開關」。**

`ProductTable`（`js/src/components/product/ProductTable/index.tsx`）目前**同時是** `#/products` 的頁面元件
（`pages/admin/Product/List/index.tsx` 就是 `<ProductTable />`），不可直接塞進 Modal：

| # | 阻礙 | 位置 |
|---|------|------|
| 1 | 內建 `<CreateButton>`（新增商品） | `index.tsx:157` |
| 2 | 內建 `<ActionArea>` + `<BulkAction>` + `<SelectedItem>`（fixed 底部條） | `index.tsx:172-192` |
| 3 | 綁死 module-level `selectedProductsAtom`，且 mount 時執行 `setSelectedProducts([])` | `index.tsx:39`、`:128-131` |
| 4 | `cardProps` prop 已宣告但元件內完全未使用（OBS-1，死 prop） | `index.tsx:34-38` |

目標結構：

```
components/product/ProductTable/
├── index.tsx              # #/products 外殼：ProductTableBody + CreateButton + ActionArea + Jotai 接線
├── ProductTableBody.tsx   # 【新增】共用主體：篩選 Card + FilterTags + 列表 Card + Table
│                          #   rowSelection 由 props 注入（選取狀態完全外部控制）
│                          #   不含 CreateButton / ActionArea / Jotai / ProductActions
└── …
```

- `#/products`：`ProductTableBody` + `CreateButton` + `ActionArea` + Jotai atom 接線（行為與現況完全一致）
- `ProductPickerModal`：`ProductTableBody` + Modal `footer` + **自帶的隔離選取狀態**

### Modal 情境必須關閉的元素（硬性清單）

| 元素 | 為何必須關閉 |
|------|-------------|
| `<CreateButton>`（新增商品） | 選商品情境不該能建立商品 |
| `<ActionArea>` / `<BulkAction>` / `<SelectedItem>` | fixed 定位在 Modal 內層級錯亂；批次操作（刪除 / 批次改價）在選商品情境語意錯誤 |
| `<ProductActions>` 操作欄 | 選商品情境不需要逐列操作 |
| **`selectedProductsAtom` 綁定** | 見下方硬性禁止 |
| 任何寫入全域狀態的行為 | Modal 不得產生外溢副作用 |

### 硬性禁止：不得複用 `selectedProductsAtom`

`components/product/ProductTable/atom.tsx` 的 `selectedProductsAtom` 是 **module-level 全域 atom**，
且 `ProductTable` 在 mount 時無條件執行 `setSelectedProducts([])`（`index.tsx:128-131`）。

Modal **必須**自帶隔離的選取狀態（component-local state，或 Modal scope 內的 `Provider`）。

雙向污染後果：

| 方向 | 後果 |
|------|------|
| Modal → `#/products` | 開啟 Modal 即清空使用者在商品列表頁的批次選取 |
| `#/products` → Modal | 商品列表頁的已選商品被誤認為「要加入賣場的商品」 |

> ⚠️ 後續實作者若為了「重用」而把 Modal 的 `rowSelection` 接回 `selectedProductsAtom`，
> 即違反本規格。對應驗收見
> [select-shop-products.feature](../features/profit-shop/select-shop-products.feature)
> 的 Rule「Modal 的勾選狀態不得與商品列表頁的批次選取互相污染」。

### 驗收判準

`#/products` 頁**不得產生任何 regression**：篩選 / 分頁 / 跨頁選取 / 批次操作 / 新增商品行為與現況完全一致。

## 數量上限

| 上限 | 值 | 性質 | 依據 |
|------|-----|------|------|
| 單次批次加入 | **100 筆** | 硬上限（達標後其餘 checkbox `disabled` 並提示） | WC REST `per_page` 上限為 100，顯示資訊補齊查詢一次打得完；`include=` 走 GET query string，ID 過多會撞 URL 長度上限 |
| 賣場商品總數 | **500 筆** | 軟上限（警示但不阻擋儲存） | `ItemsEditor.handleUpdate` 每次按鍵都 `onChange?.(items.map(...))` 重建整個陣列（`ItemsEditor.tsx:106-112`），列數過多時價格欄輸入明顯延遲；`ItemValidator::validate()` 逐筆呼叫 `wc_get_product()`（`Application/Service/ItemValidator.php:53-62`）；賣場前台 `templates/profit-shop-front.php` 一次渲染全部 items 無分頁 |

> 要突破 500 筆軟上限，需先把 `ItemsEditor` 改為虛擬列表 / 分頁並解決 `ItemValidator` 的 N+1，
> 屬後續迭代，**不在本次範圍**。

## 狀態與邊界規則

| 規則 | 說明 |
|------|------|
| Modal 選取隔離 | Modal 的勾選集合與 `#/products` 的批次選取互不影響（見上方硬性禁止） |
| 跨頁/跨篩選保留 | 切換分頁或變更篩選條件時，Modal 內既有勾選不遺失 |
| 取消不生效 | 關閉 / 取消 Modal 不得變更賣場商品列表 |
| 加入為附加 | 確認後新商品附加於列表末端，既有項目的覆寫值與浮報數不被覆寫 |
| 防重複（雙層） | ① Modal 內已加入商品 checkbox `disabled`；② 批次加入時仍對「現有 items」與「批次內部」各做一次 dedupe，重複項**靜默略過不報錯**，並回報實際加入筆數 |
| 批次加入上限 | 單次 100 筆（硬）；賣場總數 500 筆（軟，警示不擋） |
| 商品已刪除 | 顯示 `#id` + 「商品已失效」Tag，「移除」操作維持可用 |
| 補齊查詢失敗 | 降級為顯示 `#id`，**不得**阻擋賣場編輯與儲存 |
| 防 refetch 覆寫 | 沿用既有 `filledIdRef` + `refetchOnWindowFocus: false`（`Edit/index.tsx:37-61`、`hooks.ts:56-62`） |

## 資料來源

| 操作 | 規格 |
|------|------|
| Modal 內查詢商品 | [query-product-list.feature](../features/product/query-product-list.feature)（`wc-rest`） |
| 勾選加入賣場 | [select-shop-products.feature](../features/profit-shop/select-shop-products.feature) |
| 補齊商品名稱/SKU | [enrich-shop-item-display.feature](../features/profit-shop/enrich-shop-item-display.feature) |
| 儲存價格覆寫 | [save-shop-item-overrides.feature](../features/profit-shop/save-shop-item-overrides.feature) |

## 相關流程

[profit-shop-item-selection.activity](../activities/profit-shop-item-selection.activity)
