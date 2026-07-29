---
applyTo: "**/*.ts,**/*.tsx"
---

# Power Shop — React / TypeScript 開發指引

> 適用於所有 `.ts` / `.tsx` 檔案。架構概覽見 `architecture.rule.md`。
> **列表頁版型（篩選卡片 + 實體列表 & 新增按鈕）一律遵循 `admin-list-ui.rule.md`。**

---

## 1. 框架堆疊

| 層級 | 技術 | 備註 |
|------|------|------|
| UI 框架 | React 18 | Functional components only |
| Meta-framework | Refine v4 | CRUD hooks + resource 系統 |
| UI 元件庫 | Ant Design v5 | ConfigProvider theme token |
| 狀態管理 | Jotai + React Context | atom 跨分頁選取；Context 區域共享 |
| 資料層 | React Query (via Refine) | 搭配 6 個 Data Provider |
| 路由 | HashRouter (react-router v7) | `#/` 路徑，定義在 App1.tsx |
| 樣式 | Tailwind CSS (`tw-` prefix) | 搭配 Ant Design theme token |
| 打包 | Vite | HMR port 5178 |
| 共用套件 | `antd-toolkit` | 子路徑：`antd-toolkit`、`antd-toolkit/refine`、`antd-toolkit/wp` |

---

## 2. 元件寫法慣例

1. **Functional components only** — 禁止 class components。
2. 所有頁面級和重型元件使用 **`memo()`**。
3. **JSDoc 註解使用繁體中文** — 這是台灣產品。
4. 所有 **UI 文字使用繁體中文**。
5. 使用 **`@/` alias** 做 import 路徑（對應 `js/src/`）。

```tsx
import { memo } from 'react'

/** 我的頁面元件 */
const MyPageComponent = () => {
  return <div>我的頁面</div>
}

export const MyPage = memo(MyPageComponent)
```

### `notification` / `message` 的前提：`main.tsx` 必須有 `<AntdApp>` provider（⚠ Phase 7 踩坑，已修）

Ant Design v5 的 `App.useApp()` 要拿到可用的 `notification` / `message` / `modal` instance，**前提是元件樹被 antd 的 `<App>` context provider 包住**。`main.tsx` 若沒有這層 provider，`App.useApp()` 會回傳空物件 `{}`——`notification.success(...)` 這類呼叫在執行期才會發現 `notification` 是 `undefined`，呼叫當下直接 `throw`，**中斷該處後續所有程式碼**（例如成功訊息呼叫後面接的 `onClose()` / `refetch()` 都不會執行），而且通常沒有清楚的錯誤訊息可看，很難排查。

這個問題曾經影響全專案 10 個檔案，包含三個 HIGH-RISK 元件：`RegeneratePasswordButton`（明文密碼展示）/ `ImportModal`（不可逆匯入）/ `ResetButton`（不可逆重設）——站長按下去之後，不管成功或失敗都看不到任何回饋；`ProfitShop/Edit` 的「已更新賣場」/「更新失敗」提示也從未真正出現過。

**已修**：`main.tsx` 用 `<AntdApp component={false}>` 包住整棵樹（`component={false}` 讓它零 DOM 輸出，純粹提供 context，不影響版面）。

**新元件開發規範**：任何要用 `App.useApp()` 拿 `notification` / `message` / `modal` 的元件，**先確認掛載點的 `main.tsx` 有 `<AntdApp>` provider**（admin SPA 與 partner-portal SPA 是各自獨立的 React root，要分別確認各自的 `main.tsx`）。若新增第三個獨立 SPA bundle，這是**必須**補上的 provider，不是可選項。

---

## 3. Data Provider 與 Refine Hooks

### SKILL 優先參考

進行任何 Refine.dev 相關開發時，**必須**優先參考以下 SKILL 文件（位於 `~/.claude/skills/refine/references/`）：

| SKILL 文件 | 用途 |
|------------|------|
| `references/app-setup.md` | Refine App 初始化、Provider 配置 |
| `references/data-provider.md` | Data Provider 介面與自訂實作 |
| `references/rest-data-provider.md` | REST Data Provider 設定與 API 對接 |
| `references/auth-provider.md` | Auth Provider 認證流程 |
| `references/data-hooks.md` | `useList`, `useOne`, `useCreate`, `useUpdate`, `useDelete`, `useTable`, `useCustom`, `useCustomMutation` 等 Data Hooks |
| `references/antd-crud.md` | Ant Design 整合 CRUD 元件（List, Show, Edit, Create） |

### Data Provider 配置

Power Shop 配置了 **6 個 Data Provider**，每次使用 Refine hook 必須明確指定 `dataProvider`：

| Key | 來源 | 用途 |
|-----|------|------|
| `'default'` | Powerhouse core | posts、media 等 |
| `'wc-rest'` | WooCommerce REST v3 | products、orders、customers |
| `'wc-store'` | WooCommerce Store API | cart、checkout |
| `'power-shop'` | 本外掛自有 REST API | Dashboard、Analytics、顧客備註 |
| `'wp-rest'` | WordPress REST API v2 | users、UserMeta |
| `'bunny-stream'` | Bunny.net | 影片操作 |

**使用規則：**
- **永遠**明確指定 `dataProvider` key。

```tsx
const { data } = useCustom<TDashboardStats>({
  url: `${apiUrl}/reports/dashboard/stats`,
  method: 'get',
  config: { query: { period: 'month' } },
  dataProviderName: 'power-shop',
})
```

### ⚠️ Refine 的 `HttpError` 會吃掉 `response.data`，導致後端錯誤訊息全部失效（Phase 7 踩坑，影響全專案、優先度最高）

**症狀**：後端明明回了詳細錯誤訊息（例如「商品 ID 999777 不存在」），前端 `notification` 卻只顯示通用的「操作失敗，請稍後再試」。

**根因**：Refine 的 dataProvider 會把 axios error 轉成自己的 `HttpError` 型別，**只保留 `{ message, statusCode }`**，原始的 `error.response.data`（後端回的 `{code, message, data}` 或裸 payload）已經不存在。如果錯誤訊息解析函式（例如 `utils/profitShopExceptionMapper.ts` 的 `extractErrorBody()`）是讀 `error.response.data` 取 `code` 欄位，會拿到 `null`，走不到對應的友善訊息，只能 fallback 成通用句。

**額外陷阱：不能靠 `statusCode` 判斷錯誤類型**。實測 `HttpError.statusCode` 回報 **500**，但 network log 顯示真正的 HTTP 狀態是 **404**——Refine 轉換過程中會弄丟正確的狀態碼，`statusCode` 與 `mapped.code` 都不可信。

**影響範圍**：**所有走 Refine dataProvider 的錯誤處理**，不限 Profit Shop。後端辛苦寫的 Domain Exception 訊息，只要走這條路徑就到不了使用者眼前。

**目前的因應**（`pages/admin/ProfitShop/Edit/index.tsx`）：解析不出 `code` 時（代表拿到的是 Refine `HttpError` 而非原始 response body），優先顯示後端錯誤原文（`error.message`，Refine 至少會把這個保留下來），不要退化成無資訊的通用句：

```ts
// 解析不出 code 時（Refine HttpError）優先用後端原文，別退化成無資訊的通用句
description: mapped.code ? mapped.toastMessage : rawMessage || mapped.toastMessage
```

特定情境（例如賣場含已刪除商品）可再用正則比對後端原文，補上可行動的指引文字 + `duration: 0`（不自動消失，讓使用者來得及看到）。

**驗證方式**：在任一 Refine mutation 的 `onError` 印出 `error`，會看到只剩 `message` / `statusCode` 兩個欄位——`error.response` 已經不在了。

**已知延伸問題**：Refine dataProvider 失敗時會**自己再跳一則**內建錯誤通知，與上述自訂通知同時出現、視覺重複，目前尚未處理，見 `specs/tech-debt.md` TD-11。

### Profit Shop 系列特例（Phase 4-A）

Profit Shop 4 個 resource（ProfitShop / ProfitPartner / ProfitMigration / ProfitSettings）的後端 V2Api 統一回 `{code, message, data}` 包裹形狀，與 antd-toolkit 預設 dataProvider 預期的 WordPress REST API 形狀（讀 `x-wp-total` header、payload 直接是 array / object）**不相容**。

**規範：**
- **不**使用 `useTable` / `useForm`（會把物件當 array 渲染，或 form initialValues 全空）
- 改用 `useCustom` / `useCustomMutation` 直接打 endpoint，手動讀 `result.data.data`
- 仍須明確指定 `dataProviderName: 'power-shop'`
- 包含元件層 useCustom（如 `SlugInput.tsx` 的即時驗證）也適用
- Refine 仍提供 `<List>` / `<Edit>` / `<Create>` UI shell + `useInvalidate` 做快取失效

```tsx
// ✅ Profit Shop 系列正確做法
const { data, isLoading } = useCustom<{ data: TProfitShop[]; total: number }>({
  url: `${apiUrl}/profit-shops`,
  method: 'get',
  config: { query: { page, per_page } },
  dataProviderName: 'power-shop',
})
const list = data?.data?.data ?? []  // 注意要剝兩層
```

未來若要改用 `useTable` / `useForm`，需先為 `'power-shop'` dataProvider 寫 thin wrapper 拆解 `{code, data}`。

### ⚠️ 走 `wc-rest` 時 `pagination.pageSize` 完全無效（Phase 7 踩坑）

6 個 dataProvider 共用同一份分頁序列化邏輯，一律把 Refine 的 `pagination` 轉成 Powerhouse 風格的 `paged` / `posts_per_page` query param。但 WooCommerce `wc/v3/products`（以及其他 `wc-rest` resource）**只認 `page` / `per_page`**，不認 `posts_per_page`——`posts_per_page` 會被 WC REST API 直接忽略，`per_page` 沒被送出就靜默退回 WooCommerce 預設**每頁 10 筆**。

症狀：明明設定 `pagination: { pageSize: 100 }`，`useList` / `useTable` 卻只拿回 10 筆；如果下游邏輯拿這份結果去比對「查無的 id」，會把第 11 筆以後的合法商品誤判為「已失效 / 已刪除」。

**修法**：把 `per_page` 當成 **filter** 送，不要只放在 `pagination`：

```tsx
// ❌ 錯誤：pageSize 對 wc-rest 無效，實際仍是每頁 10 筆
useList<TProductRecord>({
  resource: 'products',
  dataProviderName: 'wc-rest',
  pagination: { pageSize: 100 },
})

// ✅ 正確：per_page 當 filter 送（filter 會被序列化成 query string）
useList<TProductRecord>({
  resource: 'products',
  dataProviderName: 'wc-rest',
  filters: [{ field: 'per_page', operator: 'eq', value: 100 }],
  pagination: { pageSize: 100 }, // 可保留同步顯示用，但實際生效的是上面的 filter
})
```

**影響範圍**：全專案任何走 `wc-rest` 的 `useList` / `useTable`，不限 Profit Shop。新增走 `wc-rest` 的查詢時，若預期單頁筆數 > 10，務必用這個寫法驗證。`useShopItemProducts`（`pages/admin/ProfitShop/hooks.ts`，見 §6.5）的 `include=` 補齊查詢就是靠這個寫法才能一次拿到 100 筆。

### 禁止直接 API 呼叫

- **禁止**使用 `fetch`、`axios`、`ky` 或其他 HTTP 客戶端直接呼叫 API
- **所有資料讀取與寫入必須透過 Refine 的 Data Provider + Data Hooks** 進行
- 唯一例外：Refine Data Provider 本身的實作內部可使用 HTTP 客戶端

```tsx
// ❌ 錯誤：直接使用 fetch/axios
const response = await fetch(`${apiUrl}/products`, {
  headers: { 'X-WP-Nonce': nonce },
})

// ✅ 正確：透過 Refine Data Hooks
const { data, isLoading } = useList<TProduct>({
  resource: 'products',
  dataProviderName: 'wc-rest',
  filters: [{ field: 'status', operator: 'eq', value: 'publish' }],
})

// ✅ 正確：寫入操作使用 useCreate / useUpdate
const { mutate } = useCreate<TOrder>()
mutate({
  resource: 'orders',
  values: orderData,
  dataProviderName: 'wc-rest',
})
```

---

## 4. 狀態管理

### Jotai（跨分頁選取狀態）

ProductTable 和 UserTable 使用 Jotai atom 管理跨分頁選取：

```
頁面切換 → useEffect 同步 → Jotai atom 更新 → BulkAction 讀取
```

### React Context（區域共享）

Orders Edit 和 Users Edit 使用雙 Context 模式：

```tsx
<IsEditingContext.Provider value={isEditing}>
  <RecordContext.Provider value={record}>
    <Edit>
      <Form>
        <Detail />
      </Form>
    </Edit>
  </RecordContext.Provider>
</IsEditingContext.Provider>

// 消費層
const record = useRecord()
const isEditing = useIsEditing()
```

---

## 5. 路由架構

使用 **HashRouter**（`#/` 路徑），定義在 `App1.tsx`：

| 路由 | 元件 | Data Provider |
|------|------|---------------|
| `/` | → `/dashboard` | NavigateToResource 重定向 |
| `/dashboard` | `Dashboard/Summary` | `power-shop` |
| `/orders` | `Orders/List` | `wc-rest` |
| `/orders/edit/:id` | `Orders/Edit` | `wc-rest` |
| `/users` | `Users/List` | `wc-rest` |
| `/users/edit/:id` | `Users/Edit` | `wc-rest` + `wp-rest` |
| `/products` | `Product/List` | `wc-rest` |
| `/products/edit/:id` | `Product/Edit` | `wc-rest` |
| `/products/taxonomies` | `Product/Taxonomies` | `wc-rest` |
| `/products/attributes` | `Product/Attributes` | `wc-rest` |
| `/marketing` | `Product/List` | `wc-rest` |
| `/marketing/one-shop` | `Marketing/OneShop` | — (Phase 4-A3：雙入口 placeholder) |
| `/profit-shop` | `ProfitShop/List` | `power-shop`（useCustom）|
| `/profit-shop/create` | `ProfitShop/Create` | `power-shop` |
| `/profit-shop/edit/:id` | `ProfitShop/Edit` | `power-shop` |
| `/profit-partner` | `ProfitPartner/List` | `power-shop` |
| `/profit-partner/create` | `ProfitPartner/Create` | `power-shop` |
| `/profit-partner/edit/:id` | `ProfitPartner/Edit` | `power-shop`（含 RegeneratePasswordButton）|
| `/profit-migration` | `ProfitMigration/List` | `power-shop`（含 ImportModal）|
| `/profit-settings` | `ProfitSettings` | `power-shop`（含 ResetButton）|
| `/analytics` | `Analytics` | `power-shop` |
| `/wp-media-library` | `WPMediaLibraryPage` | `default` |

### 新增頁面 SOP

1. 建立 `js/src/pages/admin/<Section>/index.tsx`（使用 `memo()`）。
2. 從 `js/src/pages/admin/index.tsx` 匯出。
3. 在 `js/src/resources/index.tsx` 註冊 resource。
4. 在 `js/src/App1.tsx` 新增 `<Route>`。

```tsx
// resources/index.tsx
{
  name: 'my-resource',
  list: '/my-path',
  meta: { label: '我的頁面', icon: <SomeIcon /> },
}

// App1.tsx
<Route path="my-path">
  <Route index element={<MyPage />} />
</Route>
```

---

## 6. 商品編輯系統

### 多 Tab 架構

商品編輯頁（`pages/admin/Product/Edit/index.tsx`）使用 **8 個 Tab**：

| Tab | 說明 | 備註 |
|-----|------|------|
| 描述 (basic/detail) | 商品名稱、描述、短描述 | |
| 價格 (price) | 原價、特價、促銷日期 | `grouped` / `variable` 隱藏 |
| 庫存 (stock) | SKU、庫存數量、狀態 | |
| 規格 (attribute) | 商品屬性管理 | 儲存按鈕停用 |
| 變體 (variation) | 變體列表、批次操作 | 僅 `variable` / `subscription_variable` 顯示 |
| 進階 (sales/size/subscription) | 購買備註、排序、尺寸 | |
| 關聯 (taxonomy) | 分類、標籤、交叉銷售 | |
| 分析 (analytics) | 單品銷售分析 | 儲存按鈕停用 |

### Partials 機制

使用 `queryMeta.variables.partials` 請求特定資料切片：

```tsx
const { formProps, saveButtonProps, query, onFinish } = useForm<TProductRecord>({
  action: 'edit',
  resource: 'products',
  id,
  queryMeta: {
    variables: {
      partials: ['basic', 'detail', 'price', 'stock', 'taxonomy'],
      meta_keys: [],
    },
  },
})
```

可用 partials：`basic`、`detail`、`price`、`stock`、`sales`、`size`、`subscription`、`taxonomy`、`attribute`、`variation`。

### 產品類型條件

| 條件 | 檢查方式 |
|------|----------|
| 顯示變體 Tab | `isVariable(watchProductType)` — types `variable`、`subscription_variable` |
| 隱藏價格 Tab | types `grouped` 或 `variable` |
| 停用儲存按鈕 | 目前 Tab 為 Attributes、Variation 或 Analytics |

### 虛擬表格

ProductEditTable 使用虛擬列表渲染，因 `Form.getFieldsValue` 不適用虛擬列表：

```
表格編輯 → handleValuesChange → setVirtualFields → 手動狀態管理
同步模式開啟 → 批次更新所有變體的相同欄位
```

---

## 6.4 ProductTable 共用元件架構（Phase 7：ProductTableBody + useProductTableQuery）

`#/products` 的商品列表元件（`components/product/ProductTable/`）從單一頁面元件拆成三層，供 `#/products` 本身與 `ProductPickerModal`（§6.5）共用：

```
js/src/components/product/ProductTable/
├── index.tsx                 # 外殼：#/products 頁面用。useTable / Jotai atom / useRowSelection /
│                              #   3 個 useEffect（Jotai 同步）/ ActionArea / SelectedItem 全部住這裡
├── ProductTableBody.tsx      # presentational only：篩選 Card + FilterTags + 列表 Card + Table
│                              #   rowSelection 完全由 props 注入，不持有任何狀態
├── useProductTableQuery.ts   # useTable 設定抽出：resource / onSearch / filters / pagination
│                              #   簽名 useProductTableQuery(opts?: { syncWithLocation?: boolean })
├── hooks/useColumns.tsx      # 加 { showActions?: boolean } 參數，預設 true（不傳 = #/products 行為不變）
└── atom.tsx                  # 不動：module-level Jotai atom，只給 #/products 用（見 §6.5 隔離規則）
```

```tsx
// #/products（不傳，showActions 預設 true）
const columns = useColumns()

// ProductPickerModal（不需要操作欄）
const columns = useColumns({ showActions: false })
```

**關鍵設計決策：`useTable` 刻意留在 caller，不下放進 `ProductTableBody`。**

`ProductTableBody` 是純 presentational 元件，props 包含 `searchFormProps` / `tableProps` / `columns` / `rowSelection` / `valueLabelMapper` / `options` / `tableHeader?: ReactNode` / `scrollX?: number` / `showCard?: boolean`（預設 `true`，見下方子節）。`tableHeader` 渲染在**列表 Card 內、Table 之上**（對齊 `admin-list-ui.rule.md` §1 規則 3，例如 `#/products` 傳 `<CreateButton />`、`ProductPickerModal` 傳達到上限時的 `<Alert>`）。

原因：caller（`#/products` 的 `index.tsx` 或 `ProductPickerModal`）需要 `searchFormProps`（`SelectedItem.onSelected` 要重設表單）與 `tableProps.dataSource` / `filters` / `pagination`（Jotai 同步 `useEffect` 要讀）。若把 `useTable` 移進 `ProductTableBody`，caller 反而要開一堆 prop 洞把這些值傳回來。把 `useTable` 留在 caller，讓 `#/products` 的改動是**純 JSX 搬移**（`index.tsx` 兩張 Card 的 JSX 換成 `<ProductTableBody />`），這是零 regression 最強的保證。

**消費者**：`#/products`（`pages/admin/Product/List/index.tsx`）與 `ProductPickerModal`（`pages/admin/ProfitShop/components/ProductPickerModal/`，見 §6.5）。全專案僅這兩處使用 `ProductTable` / `ProductTableBody`。

### `syncWithLocation` 差異：shell 繼承全域、Modal 必須明確關閉

`App1.tsx` 設定 Refine 全域 `syncWithLocation: true`（`useTable` 沒傳此參數時的預設值）。`#/products` 的 shell **不傳** `syncWithLocation`（沿用全域 `true`，網址列同步篩選 / 分頁，使用者可分享網址）；`ProductPickerModal` 內的 `useProductTableQuery({ syncWithLocation: false })` **必須明確傳 `false`**，否則 Modal 內的篩選 / 分頁會寫進當前頁（例如 `#/profit-shop/edit/{id}`）的 URL，污染編輯頁網址、關閉 Modal 後殘留。

### ⚠️ SimpleModal 遮罩會阻斷 Modal 內所有表單送出（Phase 7 踩坑，任何人把 Form 放進 SimpleModal 都會撞上）

`antd-toolkit` 的 `SimpleModal` 背景遮罩事件處理概念上是：

```js
onClick: (e) => {
  e.stopPropagation()
  e.preventDefault()          // ← 對所有冒泡上來的 click 都呼叫，不只是點在遮罩本身
  e.target === e.currentTarget && close()
}
```

`preventDefault()` 對**所有冒泡到遮罩層的 click** 都會執行。如果 Modal 內有 `<Form>` 且送出鈕是 `type="submit"`，這顆 `preventDefault()` 會連帶取消表單送出——症狀是「按下篩選 / 送出按鈕完全沒反應，連 request 都不會發出」，且不會有任何 console error，非常難排查。

`#/products` 不在 Modal 裡所以沒事；既有的 `TaxonomyModal` 是用純 `onClick`（非 `type="submit"`）按鈕，剛好繞過這個坑。`ProductPickerModal` 內嵌 `ProductTableBody` 的篩選 `<Form>` 第一次踩到。

**修法**：`SimpleModal` 的 children 外面包一層 `<div onClick={(e) => e.stopPropagation()}>`，把 click 事件在冒泡到遮罩前攔下。遮罩「點背景關閉」的判斷邏輯是 `e.target === e.currentTarget`（掛在遮罩自己身上，不是靠冒泡收），不受這層 `stopPropagation` 影響。

```tsx
// ProductPickerModal/index.tsx（示意）
<SimpleModal {...modalProps} title="選擇商品" destroyOnHidden>
  <div onClick={(e) => e.stopPropagation()}>
    <ProductTableBody {...bodyProps} />
  </div>
</SimpleModal>
```

**任何未來要把 antd `<Form>`（尤其含 `type="submit"` 按鈕）放進 `SimpleModal` 的元件，都要套用這個包法**，不要等撞到才想起來。這條連同 Modal 情境其他必須關閉的元素，整理成清單見 `admin-list-ui.rule.md` §7。

### `showCard` prop：antd-toolkit `Card` 的 `showCard={false}` 會連 `title` / `className` 一起消失（三輪改造踩坑）

`ProductTableBody` 新增 `showCard?: boolean`（預設 `true`）。`antd-toolkit` 的 `Card` 元件在 `showCard={false}` 時是**直接回傳 `children`，整張 `Card` 不渲染**——這代表 `title` 與 `className`（包含用來留間距的 `mb-4`）會**一併消失**，不是「隱藏外框但保留內距」。

**修法**：不要多包一層 wrapper div 去補間距，改成把間距下放到內層元素的**條件式 className**：

```tsx
<div className={showCard ? 'mt-2' : 'mt-2 mb-4'}>
  {/* FilterTags 或其他需要間距的內容 */}
</div>
```

這樣卡片模式下 DOM 結構零變化，只有非卡片模式（例如被當內嵌篩選器塞進其他版面）才會多墊一段 margin。`admin-list-ui.rule.md` §7 也記了這條，供 Modal 情境查閱。

---

## 6.5 Profit Shop 系列前端架構（Phase 4-A）

商家後台 Profit Shop CRUD UI，4 個 resource 對應 4 個 page module。

### 目錄結構

```
js/src/pages/admin/
├── ProfitShop/         # 賣場 CRUD（含 ItemsEditor、ShopActionsButtons、ProductPickerModal[Phase 7]）
├── ProfitPartner/      # 分潤夥伴 CRUD（含 RegeneratePasswordButton ⚠ HIGH-RISK、PartnerSelector）
├── ProfitMigration/    # legacy 一頁商店匯入（含 ImportModal ⚠ HIGH-RISK）
└── ProfitSettings/     # 全域設定（含 ResetButton ⚠ HIGH-RISK）
```

### 共用工具

- **`utils/profitShopExceptionMapper.ts`**：12 種 ErrorCode → 友善繁中訊息（對齊 `api.yml` 的 `ProfitShopExceptionError.code` enum），統一處理 `slug_conflict` / `partner_in_use` / `rate_limited` / `internal_error` 等。

### Hook Pattern（取代 useTable / useForm）

由於後端回 `{code, data}` 不相容 antd-toolkit dataProvider（見 Section 3 的「Profit Shop 系列特例」），改用 `useCustom` / `useCustomMutation` 並手動讀 `result.data.data`，仍透過 Refine `useInvalidate` 維持快取失效。

### 列表頁版型（與 `#/products` 一致）

不能用 `useTable` **不代表**可以少一張篩選卡片。profit-* 的列表頁版型必須與 `#/products` 相同：篩選卡片 + 實體列表 & 新增按鈕，只是篩選改在前端 `useMemo` 做（`.claude/rules/admin-list-ui.rule.md` §3.2 路線 B）。

參考實作：`pages/admin/ProfitShop/List/index.tsx` + `List/Filter.tsx`。
`ProfitPartner` / `ProfitMigration` 兩頁**尚未對齊**，改動該頁時須一併補上。

### HIGH-RISK 元件的安全紀律

| 元件 | 風險面 | 紀律 |
|------|--------|------|
| `RegeneratePasswordButton` | 明文新密碼僅展示一次 | 僅 `useState`（不進 cache / storage / log / Jotai / Context）+ 5 min auto-clear timer + unmount cleanup + Modal `closable=false` / `maskClosable=false` / `keyboard=false`（強制走確認按鈕）+ `navigator.clipboard.writeText`（不 fallback execCommand）+ Input.Password 預設 mask（`visibilityToggle.visible` 由 state 控制）|
| `ImportModal` | legacy 一頁商店 → profit shop 不可逆 | 4 道閘門：row Import 按鈕 disabled / Modal 開啟後 canSubmit 雙檢 importable / PartnerSelector 必選 / IMPORT 二次確認 |
| `ResetButton` | profit-settings 還原預設 | 自包 mutation（不外洩通用 reset hook）+ RESET 二次確認 + `maskClosable=false` / `keyboard=false` |

### 防 refetch 覆寫使用者編輯 Pattern（Edit 頁面共用）

React Query `refetchOnWindowFocus` 會讓 `record` reference 變化 → `useEffect` 觸發 `form.setFieldsValue` → 使用者打字到一半被覆蓋。對策：

```tsx
const filledIdRef = useRef<number | null>(null)

useEffect(() => {
  if (record && record.id !== filledIdRef.current) {
    form.setFieldsValue(record)
    filledIdRef.current = record.id
  }
}, [record, form])

// 雙保險：對應的 useCustom 加 refetchOnWindowFocus: false
const { data } = useCustom({
  // ...
  queryOptions: { refetchOnWindowFocus: false },
})
```

ProfitSettings 額外加 `lastFilledSettingsRef`（content-diff 比對）：reset 後 server 內容變動時自動重填；單純 refetch 同內容時不覆寫使用者編輯。

### SlugInput 即時驗證 Pattern

`pages/admin/ProfitShop/components/SlugInput.tsx`：

- 400ms `lodash.debounce`
- `useCustom` 的 `enabled` 切換（React Query 自動 cancel pending request）
- `currentSlug` 比對：編輯模式下跳過驗證自己的 slug
- `shouldQuery` 守門：欄位清空時 alert 不殘留（`enabled=false` 仍可能讀到 cache）

### ProductPickerModal（Phase 7，商品選擇器 Modal）

`pages/admin/ProfitShop/components/ProductPickerModal/`（`index.tsx` + `hooks.ts`）：`ItemsEditor` 舊版是 10 萬商品規模下無法用的單選 `useSelect` + `Select`，改成「選擇商品」按鈕開啟 `SimpleModal`，內嵌 §6.4 的 `ProductTableBody`，讓賣場編輯頁擁有和 `#/products` 相同的完整篩選器 + 分頁 + 跨頁勾選。

**與 `#/products` 的差異（`useProductTableQuery` / `useColumns` 參數）**：

| 設定 | `#/products` | `ProductPickerModal` |
|------|--------------|-----------------------|
| `useProductTableQuery` | 不傳 `syncWithLocation`（繼承全域 `true`） | `{ syncWithLocation: false }`（§6.4，防污染編輯頁 URL） |
| `useColumns` | 不傳（`showActions` 預設 `true`） | `{ showActions: false }`（Modal 不需逐列操作欄） |
| 選取狀態 | Jotai `selectedProductsAtom`（跨分頁 / 跨頁面持久） | **元件區域 `useState`**（`useProductPickerModal` hook 持有，Modal 關閉即丟棄） |
| 新增商品按鈕 | 有（`tableHeader` 傳 `<CreateButton />`） | 無 |
| `ActionArea` / `BulkAction` | 有 | 無 |

**選取狀態絕不可接 `selectedProductsAtom`**：`selectedProductsAtom` 是 module-level 全域 atom，`ProductTable` mount 時會無條件 `setSelectedProducts([])`（既有行為，詳見 `specs/tech-debt.md` TD-8）。如果 `ProductPickerModal` 接了同一顆 atom，會與 `#/products` 雙向污染——在 Modal 裡勾選會清掉 `#/products` 的選取，反之亦然。`useProductPickerModal` hook 自帶 `useState<TProductRecord[]>([])`，與 `#/products` 完全隔離。

**三重 disabled**（`getCheckboxProps`）：

```ts
isVariation(record.type)
  || existingIds.has(Number(record.id))        // 已在賣場
  || (selected.length >= 100 && !isSelected)    // 破單次上限
```

**跨頁保留機制**：`onSelect` / `onSelectAll` 以 **`record` 物件累積**（不是 antd `onChange` 給的 `selectedRows`，那只是當前頁範圍內的物件）。切換分頁 / 變更篩選後，之前頁面勾選的 `record` 仍保留在累積集合中。

**上限**：單次批次上限 **100 筆**（硬性，checkbox 直接 disabled + Modal 內 `<Alert>` 提示，未達上限時不顯示）；賣場商品總數軟上限 **500 筆**（超過只警示，不阻擋「加入賣場」）。

**參考範例**：`useSimpleModal` + 自訂 footer 確認鈕的既有 pattern，可對照 `components/term/TaxonomyModal/hooks/index.tsx`。

**SimpleModal 遮罩坑**：見 §6.4「SimpleModal 遮罩會阻斷 Modal 內所有表單送出」——`ProductPickerModal` 內嵌的 `ProductTableBody` 篩選 `<Form>` 就是踩到這個坑的地方。Modal 情境還有哪些元素必須關閉，整理成清單見 `admin-list-ui.rule.md` §7。

### items 巢狀契約 + 商品顯示資訊補齊（Phase 7，`useShopItemProducts`）

`ItemsEditor` 的 `items` 型別（`pages/admin/ProfitShop/types.ts`）Phase 7 改為與後端 `OverrideItemInput` / `OverrideItemOutput` 一致的巢狀契約：

```ts
type TPriceOverride = { regular_price: string | null; sale_price: string | null; signup_fee: string | null }
type TOverrideItem = {
  product_id: number
  inflated_count: number
  override: TPriceOverride
  variations: Record<string, { override: TPriceOverride }>
}
// UI 專用（不送後端，送出前 strip `_` 前綴欄位）：
type TShopItemRow = TOverrideItem & {
  _name?: string
  _sku?: string
  _enrichState?: 'pending' | 'ok' | 'missing' | 'failed'
}
```

價格用 `string | null`、不是 `number | null`——後端 `PriceOverride` VO 是 nullable **string**，用 `bccomp` 走十進位字串比較避免浮點誤差。詳細契約與 BUG-A 修正始末見 `profit-shop.rule.md` §7「OverrideItem items 契約」。

後端 `OverrideItemOutput` **刻意不含** `name` / `sku`（避免顯示 stale 資料），前端用 `useShopItemProducts`（`pages/admin/ProfitShop/hooks.ts`）以 `wc-rest` `include=` 分 chunk 批次補齊：

```ts
// 500 筆軟上限 ÷ 每 chunk 100（wc-rest per_page 上限） = 固定 5 個 chunk
// React hooks 數量不可變動，所以是「固定 5 個 useList」，不是迴圈動態呼叫
useList<TProductRecord>({
  resource: 'products',
  dataProviderName: 'wc-rest',
  filters: [{ field: 'include', operator: 'eq', value: chunk }],  // 見 §3「wc-rest 分頁陷阱」
  pagination: { pageSize: 100 },
  queryOptions: { enabled: chunk.length > 0 },  // ids 為空的 chunk 不發查詢
})
```

`ItemsEditor` 商品欄依 `_enrichState` 四分支降級顯示：`ok`（名稱 + `#id` + SKU）／`missing`（商品已失效 Tag，移除鈕仍可用）／`failed`（僅 `#id`，降級但不擋儲存）／`pending`（`#id` + Skeleton）。補齊查詢失敗**不阻擋**編輯與儲存——即使查詢收到 403（見 `specs/tech-debt.md` TD-7 全域錯誤對話框），儲存路徑仍完全不受影響。

### `ItemsEditor` 表格改版：8 欄 + 複用 `antd-toolkit/wp` 商品欄位元件（三輪改造）

`ItemsEditor` 的商品列表表格從 5 欄擴充為 **8 欄**：`商品名稱 / 商品類型 / 狀態 / 價格 / 庫存 / 覆寫價格 / 灌水購買人數 / 操作`。

- 「商品類型」「狀態」「價格」「庫存」四欄**直接複用 `antd-toolkit/wp` 的 `ProductType` / `ProductPrice` / `ProductStock` 元件**（與 `#/products` 的 `ProductName` 同一批元件），**沒有動 `components/product/ProductTable/hooks/useColumns.tsx`**——`ItemsEditor` 的欄位定義是它自己的一份，不是共用 `useColumns()`
- 上方 §「items 巢狀契約 + 商品顯示資訊補齊」提到的 `useShopItemProducts`，map value 從 `{ name, sku }` 升級為**整筆 `TProductRecord`**：`wc-rest` 查詢本來就回完整商品物件，先前只挑 `name` / `sku` 兩欄是白白丟掉其餘欄位，這次**沒有新增任何查詢**，純粹是把已經拿到的資料用好，才能餵給 `ProductType` / `ProductPrice` / `ProductStock` 這些元件
- 「覆寫價格」欄合併原本「原價覆寫」「特價覆寫」兩欄，同一欄內上下疊兩個 `InputNumber`（`prefix` 標「原價」/「特價」，`placeholder` 顯示「沿用原價」/「沿用特價」）
- `scroll.x` 從 `820` 調整為 `1330`（欄位變多）

**⚠ 語意陷阱：「留空」與「填 0」意義完全不同，不可混淆**：

| 使用者輸入 | 送出值 | 意義 |
|------------|--------|------|
| 留空 | `null` | 沿用商品原價（不覆寫） |
| 填 `0` | `"0"` | 覆寫為 0 元（例如免費贈品） |

這條規則已用「送出 payload → DB `get_post_meta` → 重新載入頁面讀回」三段式驗證，確保 UI 顯示的空白 / `0` 與資料庫實際存的 `null` / `"0"` 一致，不會在某個轉換環節被互相吃掉。

### ⚠️ `TProductRecord.type` 型別陷阱：`antd-toolkit` 的 `Omit` 用在 string union 上會失效

`antd-toolkit` 把 `TProductRecord.type` 宣告成 `Omit<TProductType, …>`——但 `Omit` 是設計給物件型別用的，套在 **string union**（例如 `'simple' | 'variable' | 'grouped' | ...`）上不會排除任何成員，只會讓 TypeScript 推導失準，導致這個型別與 `ProductType` / `ProductPrice` 等元件要求的 `record` 型別**不相容**（上游 `.d.ts` 本身的缺陷，不是本專案寫錯）。

`useColumns.tsx` 為了不被這個型別問題卡住，用 `record as any` 繞過——但這樣會多一條 `no-explicit-any` ESLint warning。更乾淨的做法是**從元件本身反推型別**，而不是自己重新定義一個型別去湊：

```ts
import type { ComponentProps } from 'react'
import { ProductName, ProductType } from 'antd-toolkit/wp'

type TWpDisplayRecord = ComponentProps<typeof ProductName>['record'] & ComponentProps<typeof ProductType>['record']
const toWpRecord = (p: TProductRecord) => p as unknown as TWpDisplayRecord
```

這個寫法只做**一次**型別斷言（`as unknown as`），而不是在每個呼叫點各自 `as any`，往後這些元件的 `record` 型別若調整，只有 `TWpDisplayRecord` 這一處需要跟著改。

---

## 6.6 Partner Portal 前端架構（Phase 4-B）

Partner self-service portal 是與 admin 完全隔離的獨立 SPA。目錄、entry、tooling、安全紀律皆與 admin SPA 不同。

### 目錄結構

```
js/src/partner-portal/
├── main.tsx                    # Entry：QueryClientProvider + ConfigProvider + HashRouter + AuthProvider
├── App.tsx                     # Routes + AuthGate（guest → /login，auth → /dashboard）
├── api/
│   ├── client.ts               # axios instance（withCredentials + 401 interceptor + baseURL 內建）
│   └── reports.ts              # fetchKpi / fetchTrend / fetchSettlements wrappers（partner_term_id 永不出現在 query）
├── auth/
│   ├── api.ts                  # login / logout / fetchMe wrappers
│   ├── session.ts              # sessionStorage helper（只存 metadata，shape 防呆）
│   └── AuthContext.tsx         # status 三態 + 跨 partner 雙保險（從 4-B3 起 useAuth 直接 import）
├── components/
│   ├── DateRangeFilter.tsx     # 5 preset + 自訂 RangePicker（dayjs + Segmented）
│   ├── ErrorBoundary.tsx       # ⚠ 唯一 class component（React framework limit）
│   └── LoadingScreen.tsx       # Spin + minHeight 60vh
├── hooks/
│   ├── usePartnerEnv.ts        # 解密 window.power_shop_partner_data.env
│   ├── useKpi.ts               # typed useQuery<TKpiOutput, AxiosError>
│   ├── useTrend.ts
│   ├── useSettlements.ts
│   └── useChangePassword.ts    # Phase 6-A2：typed useMutation + mutationKey + retry: 0
├── pages/
│   ├── Login.tsx               # rate-limit 倒數 + autoComplete + SLUG 自動帶入 disabled + ?reason=password_changed 一次性 notice（6-A2）
│   ├── Dashboard.tsx           # KPI 4 卡 + TrendChart + SettlementsTable + 「修改密碼」link button（6-A2）
│   ├── TrendChart.tsx          # echarts callback ref pattern（reviewer C-1 教訓）
│   ├── SettlementsTable.tsx    # desktop / mobile 響應式 + status filter
│   └── ChangePassword.tsx      # Phase 6-A2 ⚠ HIGH-RISK：三欄位 Form + Form rule + cooldown + success state lock + setTimeout cleanup
└── utils/
    ├── retryAfter.ts           # 解析 Retry-After header（防呆 NaN / negative / 非數字）
    └── partnerExceptionMapper.ts # partner-specific 訊息 + context-aware 第二參數（'default' \| 'change-password'，6-A2）+ fallback admin profitShopExceptionMapper
```

### Vite multi-entry 配置

```typescript
// vite.config.ts
v4wp({
  input: ['js/src/main.tsx', 'js/src/partner-portal/main.tsx'],
  outDir: 'js/dist',
})
```

採 array 形式（manifest key 保持原始 path，PHP enqueue 不需改動）。否決方案：
- 兩個 v4wp instance：dev server 衝突
- rollup `input` 物件命名：manifest key 變化會破 admin enqueue

### 共用工具：partnerExceptionMapper

```ts
// utils/partnerExceptionMapper.ts
export const mapPartnerException = (
  error: unknown,
  context: 'default' | 'change-password' = 'default', // 6-A2 加入 context-aware
): string => {
  // 1. partner-specific code: too_many_attempts / unauthorized 額外訊息（含 Retry-After 倒數）
  //    - context='change-password' 時 unauthorized 訊息改為「目前密碼錯誤」（語意對齊 ChangePassword UX）
  // 2. weak_password（context='change-password'）：讀 data.reasons[]，KNOWN_WEAK_PASSWORD_REASONS 白名單 + isKnownWeakPasswordReason type guard
  // 3. fallback: admin profitShopExceptionMapper 的 12 種 ErrorCode
  // 4. fallback: error.message
  // 5. fallback: '發生未知錯誤'
}
```

向後相容：既有 `Login.tsx` / `useKpi` / `useTrend` / `useSettlements` 不傳第二參數預設 `'default'`，行為不變。Phase 6-A2 LOW-7 master 自決跳過 DRY 重構（兩 context branch 約 80% 相同，但語意差異點 `unauthorized` / `weak_password` 需保留在 caller 處理）。

### Auth flow 安全紀律

| 項 | 紀律 |
|----|------|
| Token 儲存 | **永不**進 localStorage / sessionStorage / cookie 主動讀寫；HTTP-only cookie 由後端簽發，前端僅 axios `withCredentials: true` |
| sessionStorage | 只存 metadata（`partner_id` / `partner_name` / `partner_slug` / `expires_at`），`session.read()` 帶 shape 防呆（JSON parse / 缺欄位 → null） |
| 跨 partner 偵測 | URL slug ≠ session partner_slug 時：`status` 設 `'loading'`（非 `'authenticated'`，避免短暫渲染舊 partner 資料），`partner` 物件遮蔽為 `null`（雙保險），`useEffect` 觸發 logout + notification |
| 401 interceptor | 在 React tree **之外**，用 `window.location.hash = '#/login'` 跳轉，`isLoginPage` 檢查防迴圈；6-A2 加 `X-Skip-Auth-Redirect` header 旁路（讓 ChangePassword 401 失敗能由元件內部接 mutation.onError 顯示通知，不被 interceptor 強制跳轉） |
| Logout | **永遠 local cleanup**（清 sessionStorage + react-query cache + 跳 login）即使 API 失敗 |
| `forceLogoutAndRedirect(reason?)` | 6-A2 新增於 AuthContext，與既有 `handleLogout` **並存**：前者強制 `?reason=...` 並跳 login；後者依 `isLoginPage` 防迴圈。供 ChangePassword success path 使用（後端 password_changed_at 已撤銷舊 token，必須重新登入） |
| Login form | autoComplete `username` / `current-password`、SLUG 從 URL 自動帶入並 `disabled` 防 typo、雙擊保護（`submitting` state + button `loading` + `disabled`）；讀 `?reason=password_changed`（KNOWN_REASONS 白名單）顯示一次性 success notification（6-A2） |
| rate-limit UX | 解析 `Retry-After` header → cooldown timer 倒數秒數，期間 button disabled |

### echarts callback ref pattern（reviewer C-1 教訓）

❌ 錯誤：`useRef` + `useEffect` init echarts —— 元件 mount 時 ref 還沒 attach DOM，echarts init 失敗或 dispose race。

✅ 正確：callback ref pattern：

```tsx
const chartRef = useCallback((node: HTMLDivElement | null) => {
  if (!node) {
    // unmount：dispose
    chartInstanceRef.current?.dispose()
    chartInstanceRef.current = null
    return
  }
  // mount：init
  chartInstanceRef.current = echarts.init(node)
  chartInstanceRef.current.setOption(option)
}, [option])

return <div ref={chartRef} style={{ width: '100%', height: 300 }} />
```

callback ref 在 DOM mount/unmount 那一瞬被觸發，沒有 useRef + useEffect 的時序間隙，也避免 dispose race。

### apiClient baseURL 內建（4-B3 重構）

```ts
// api/client.ts
import axios from 'axios'
import { readPartnerEnv } from '../hooks/usePartnerEnv'

const env = readPartnerEnv()
export const apiClient = axios.create({
  baseURL: env.API_URL,         // partner endpoint base，已含 /wp-json/power-shop
  withCredentials: true,         // 帶 HTTP-only cookie
  timeout: 30000,
})
```

`readPartnerEnv` 是**純函式**（不是 React hook，不依賴 component context），可在 React tree 之外 init 模組級 axios instance。免每次傳 `apiUrl` 參數。

### HIGH-RISK 元件

| 元件 | 風險 | 紀律 |
|------|------|------|
| `Login.tsx` | rate-limit 倒數錯誤可能讓 partner 看到「永遠 disabled」 | `retryAfter.ts` 防呆 NaN / negative / 非數字 → 預設 cooldown=0 |
| `AuthContext.tsx` | 跨 partner race window | 雙保險：`status='loading'`（避免短暫渲染舊 partner 資料） + `partner` 物件 null masking + `useEffect` 觸發 logout |
| `TrendChart.tsx` | echarts dispose race / SSR ref 為 null | callback ref pattern + 數值 sanitize（`parseFloat` + `Number.isNaN` fallback） |
| `ChangePassword.tsx`（6-A2）| 明文密碼 + cookie 偷取後改密鎖人 + DOM/devtools 殘留 | 8 條紀律（強化版，對照 admin RegeneratePasswordButton）：(1) 明文**只**在 antd Form state，不進 cache / storage / log / Jotai / Context / (2) 不 console.log 印密碼 / (3) submit 後 form.resetFields **分支策略**：weak_password UX 友善只清 current；其他敏感全清 / (4) submit button **三重守門** `disabled = isLoading \|\| isCoolingDown \|\| success` / (5) 成功 → `forceLogoutAndRedirect('password_changed')`（後端 password_changed_at 同秒撤銷舊 token）/ (6) `mutation.reset()` 清 react-query variables（防 React DevTools 窺）/ (7) Form rule 阻擋 new === current / new !== confirm / (8) `setTimeout` cleanup ref + useEffect unmount cleanup（防使用者中途離開後仍觸發 logout）。**關鍵技術決策**：`X-Skip-Auth-Redirect` header 防 401 interceptor 干擾 success notification；success state lock 蓋 1.5s race（連返回按鈕都 disabled，A+ 級品質） |

### 不打包 admin（隔離驗收）

partner bundle 必須完全獨立，build 後 grep 以下關鍵字應**全部 0 命中**：

```bash
# 在 js/dist/ 的 partner main entry chunk
grep -E 'App1|Refine|@refinedev|dataProviderName|wc-rest|wp-rest|X-WP-Nonce' partner-main.js
```

若有命中代表誤 import 了 admin 模組，須拆 import path（不要從 `@/` 跨入 admin tree）。

### Bundle Size 觀察

| Bundle | 4-B3 size | gzip | 備註 |
|--------|-----------|------|------|
| partner main entry | 27 KB | 9.83 KB | 從 4-B1 的 6.4 KB / 3.0 KB 成長（多了 echarts setOption + KPI / Trend / Settlements 邏輯） |
| echarts vendor chunk | 1.25 MB | 414 KB | partner 自家 chunk；admin 也用 echarts 但**目前未 dedup**（reviewer 4-B3 自承），Phase 5 可考慮 vite `manualChunks` |
| admin main entry | 3.5 MB | — | 包含 App1 / Refine / 全 6 個 dataProvider |

---

## 7. Dashboard 開發

### useDashboard Hook Pattern

```tsx
import { useCustom, useApiUrl } from '@refinedev/core'

const apiUrl = useApiUrl('power-shop')
const { data, isLoading } = useCustom<TDashboardStats>({
  url: `${apiUrl}/reports/dashboard/stats`,
  method: 'get',
  config: { query: { period: 'month' } },
})
```

### Dashboard 目錄結構

```
pages/admin/Dashboard/
├── index.tsx           # 總覽頁入口（Summary）
├── DashboardCards.tsx  # KPI 統計卡片
├── LeaderBoard.tsx     # 商品/顧客排行榜
├── IntervalChart.tsx   # ECharts 區間圖表
├── Welcome.tsx         # 歡迎訊息
├── hooks/
│   ├── index.tsx
│   └── useDashboard.tsx
├── types/
│   └── index.ts
└── utils/
    └── index.ts
```

### LeaderBoard 資料結構

```typescript
type LeaderboardRow = {
  name: string
  count: number
  total: number
}
```

---

## 8. Analytics 開發

### useRevenue Hook Pattern

```tsx
const apiUrl = useApiUrl('power-shop')
const { data } = useCustom<TRevenueReport>({
  url: `${apiUrl}/reports/revenue`,
  method: 'get',
  config: {
    query: {
      period: 'month',
      interval: 'day',
      date_start: '2026-01-01',
      date_end: '2026-01-31',
    },
  },
})
```

### Filter 與 ViewType

Analytics 頁面支援：
- **期間篩選：** today / yesterday / week / month / year / custom
- **粒度：** day / week / month
- **訂單狀態篩選：** 多選
- **圖表類型：** 折線圖 / 面積圖

---

## 9. 環境變數存取

使用 **`useEnv()`** hook 讀取解密後的環境變數，**禁止**直接讀取 `window.power_shop_data`：

```tsx
import { useEnv } from '@/hooks'

const { SITE_URL, API_URL, NONCE, KEBAB, ELEMENTOR_ENABLED } = useEnv()
```

---

## 10. CSS 慣例

- **Tailwind CSS** 使用 `tw-` prefix（避免與 Ant Design class 衝突）。
- **Ant Design** 遵循 ConfigProvider theme token：`colorPrimary: '#1677ff'`、`borderRadius: 6`。
- 使用 Ant Design v5 元件。

---

## 11. 元件組織

```
js/src/
├── pages/admin/<Domain>/     # 按領域分頁面
│   ├── index.tsx             # 頁面入口（memo）
│   ├── hooks/                # 頁面專用 hooks
│   ├── types/                # 頁面專用型別
│   └── components/           # 頁面專用元件
├── pages/admin/ProfitShop/      # Phase 4-A1：賣場 CRUD + ItemsEditor + ShopActionsButtons + SlugInput
│                                #   Phase 7：+ components/ProductPickerModal（商品選擇器 Modal，消費 §6.4 ProductTableBody）
├── pages/admin/ProfitPartner/   # Phase 4-A2：夥伴 CRUD + RegeneratePasswordButton（HIGH-RISK）+ PartnerSelector
├── pages/admin/ProfitMigration/ # Phase 4-A3：legacy 一頁商店匯入 + ImportModal（HIGH-RISK）
├── pages/admin/ProfitSettings/  # Phase 4-A3：全域設定 + ResetButton（HIGH-RISK）
├── partner-portal/             # Phase 4-B + 6-A2：獨立 SPA bundle（mount 點 #profit_partner_portal，與 admin 完全隔離）
│   ├── pages/                  #   Login / Dashboard / TrendChart / SettlementsTable / ChangePassword（6-A2 HIGH-RISK）
│   ├── hooks/                  #   useKpi / useTrend / useSettlements / useChangePassword（6-A2）
│   ├── auth/                   #   AuthContext + forceLogoutAndRedirect（6-A2）+ api.ts changePassword wrapper
│   └── utils/                  #   partnerExceptionMapper（6-A2 加 context-aware 第二參數）/ retryAfter
├── components/               # 共用元件
│   ├── general/              # CopyButton, Price
│   ├── order/                # InfoTable, OrderNotes
│   ├── post/                 # PostAction, ToggleVisibility
│   ├── product/              # Fields, ProductEditTable, ProductTable, ProductSelector
│   │                         #   ProductTable/: index.tsx（外殼）+ ProductTableBody.tsx + useProductTableQuery.ts（Phase 7，見 §6.4）
│   ├── productAttribute/     # EditForm, SortableList (全域屬性)
│   ├── term/                 # EditForm, SortableList, SortableTree, TaxonomyModal
│   └── user/                 # ContactRemarks, OrderCustomerTable, UserTable
├── hooks/                    # 全域 hooks
├── utils/                    # 全域工具函式
│   └── profitShopExceptionMapper.ts  # Phase 4-A1：12 種 ErrorCode → 友善繁中訊息
└── api/resources/            # CRUD helper wrappers
```
