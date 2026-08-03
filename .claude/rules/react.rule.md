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
| 樣式 | Tailwind CSS（**無 prefix**，見 §10） | 搭配 Ant Design theme token |
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

這個問題曾經影響全專案 10 個檔案，包含當時的三個 HIGH-RISK 元件：`RegeneratePasswordButton`（明文密碼展示）/ `ImportModal`（不可逆匯入）/ `ResetButton`（不可逆重設，已隨設定頁移除）——站長按下去之後，不管成功或失敗都看不到任何回饋；`ProfitShop/Edit` 的「已更新賣場」/「更新失敗」提示也從未真正出現過。

**已修**：`main.tsx` 用 `<AntdApp component={false}>` 包住整棵樹（`component={false}` 讓它零 DOM 輸出，純粹提供 context，不影響版面）。

**新元件開發規範**：任何要用 `App.useApp()` 拿 `notification` / `message` / `modal` 的元件，**先確認掛載點的 `main.tsx` 有 `<AntdApp>` provider**（admin SPA 與 partner-portal SPA 是各自獨立的 React root，要分別確認各自的 `main.tsx`）。若新增第三個獨立 SPA bundle，這是**必須**補上的 provider，不是可選項。

### ⚠️ antd v5 停用按鈕：`<Tooltip>` 包 disabled `<Button>` 不顯示 + `href`/`disabled` 別只信 library 內部行為（`ProfitShop/List`「開啟」按鈕踩坑）

同一顆按鈕、兩個各自獨立、都要記住的坑。

**A. `<Tooltip>` 包 disabled `<Button>` 不會顯示，且沒有任何錯誤訊息**

antd v5 拿掉了 v4 的 disabled 相容包裝（`node_modules/antd/lib/tooltip/index.js` 已找不到任何 `disabled` 處理邏輯），而瀏覽器**不會對 disabled 的 `<button>` 派發滑鼠事件**——`<Tooltip title="原因"><Button disabled /></Tooltip>` 的提示因此永遠不會出現。如果停用原因正是要靠 tooltip 告訴使用者（例如「此賣場尚未設定永久連結，無法組出前台網址」），整個回饋機制就靜默失效：畫面上只有一顆灰掉的按鈕，沒有任何解釋，也不會拋錯，非常難排查。

修法：外層再包一層 `<span>` 承接滑鼠事件，`<Button>` 本身加 `pointer-events-none` 讓事件穿透到外層：

```tsx
<Tooltip title={blockedReason}>
  <span className="inline-block cursor-not-allowed">
    <Button disabled className="pointer-events-none" icon={<ExportOutlined />}>
      開啟
    </Button>
  </span>
</Tooltip>
```

`inline-block` / `cursor-not-allowed` / `pointer-events-none` 都是標準 Tailwind utility（見 §10），未列在 `tailwind.config.cjs` 的 `blocklist` 內，直接可用。

**B. `href` + `disabled` 給同一顆 `<Button>`：能動，但不要只靠這個保證安全**

antd 5.26.1 的 `Button`（`node_modules/antd/lib/button/button.js`）偵測到 `href` 時會渲染成 `<a>`，且**確實**會在 `disabled` 時把 `href` 抽掉（L258：`href: mergedDisabled ? undefined : linkButtonRestProps.href`）；同檔案的 `handleClick`（L188）另外對 disabled / loading 呼叫 `e.preventDefault()`，旁邊有一句註解引用 `github.com/ant-design/ant-design/issues/30207`——兩處是同一個「disabled 時真的不能被觸發」目標下的獨立防線，可見這塊行為過去確實出過包，才需要雙重防禦。單顆 `<Button href disabled>` 在目前版本因此是安全的。

即便如此，`ProfitShop/List` 的「開啟」按鈕仍**刻意拆成停用 / 可用兩條 render 分支**，不把 `href` 與 `disabled` 一起餵給同一顆 `<Button>`：停用分支整顆按鈕**不帶 `href`**，DOM 裡完全沒有網址，左鍵／中鍵／Ctrl 點擊／右鍵「在新分頁開啟」全都無從觸發。理由：這個「停用時不可點」的 invariant 若寄託在 antd 內部實作，未來版本行為一變就是安靜的「停用卻仍可點開」漏洞——不會有型別錯誤（TS 本來就允許 `href` 與 `disabled` 同時給），也不會有測試攔到。拆成兩條分支後，invariant 由自己的程式碼保證，不依賴第三方套件的實作細節。

**新元件開發規範**：
- 停用按鈕要顯示原因時，一律用上面 A 的 `<span>` 包法，不要期待 `<Tooltip>` 直接包 disabled `<Button>` 就會動
- 按鈕「可能是外部連結、也可能被停用」時（例如清單頁連到前台 / 報表的按鈕），一律拆成停用 / 可用兩條 render 分支，不要圖省事把 `href` 和 `disabled` 一起塞給同一顆 `<Button>`

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

Profit Shop 3 個 resource（ProfitShop / ProfitPartner / ProfitMigration）的後端 V2Api 統一回 `{code, message, data}` 包裹形狀，與 antd-toolkit 預設 dataProvider 預期的 WordPress REST API 形狀（讀 `x-wp-total` header、payload 直接是 array / object）**不相容**。

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

### ⚠️ `NameId` 的內建截斷對「純字串 name」無效 —— 長商品名稱會撐破表格

`antd-toolkit` 的 `NameId` 把名稱截斷寫成 **`[&_*]:at-truncate`**：

```jsx
// antd-toolkit dist（反編譯自 bundle）
<div className={cn('at-flex at-items-end at-gap-x-1', className)}>
  <div className="at-name at-min-w-0 at-m-0 [&_*]:at-truncate">{name}</div>
  <div className="at-id ... at-shrink-0">#{id}</div>
</div>
```

`[&_*]:` 是**後代元素**選擇器 —— 只有當 `name` 是 ReactNode（會渲染出子元素）時才生效。
而 `ProductName` 傳進去的是 `record.name` **純字串**，沒有任何後代元素，
**那條 truncate 從來沒有作用過**。名稱一長就會撐破 `width: 300` 的欄位，
橫向蓋住右邊的「商品類型 / 狀態 / 價格」等欄位（`table-layout: fixed` 下欄寬不變，
是 cell 內容溢出）。

**修法**（`useColumns.tsx` 商品名稱欄，勿刪）：

```tsx
<ProductName<TProductRecord>
  record={record}
  className="min-w-0 [&_.at-name]:truncate"
  onClick={() => edit('products', record?.id)}
/>
```

- `[&_.at-name]:truncate` —— 直接對 `.at-name`（antd-toolkit 的穩定 class，非 hash）
  補上 `overflow:hidden` + `text-overflow:ellipsis` + `white-space:nowrap`
- `min-w-0` —— 給「ProductName 被放進 flex 容器」的情境用（見下），放在 `<td>` 裡無副作用

### flex 容器內的截斷：祖先鏈上少一個 `min-w-0` 就全毀

`ProductPickerModal` 為了在名稱後面外掛「已加入」Tag，把 `ProductName` 包了一層 flex：

```tsx
<div className="flex items-center gap-2 min-w-0">   {/* ← min-w-0 缺一不可 */}
  {originalRender?.(value, record, index)}
  <Tag color="green" className="m-0 flex-none">已加入</Tag>   {/* ← flex-none 缺一不可 */}
</div>
```

**flex item 的 `min-width` 預設是 `auto`，不是 `0`** —— 它會拒絕收縮到小於內容寬度。
少了 `min-w-0`，這層 flex 會被長名稱撐破，**內層所有 `min-w-0` 與 truncate 一起失效**
（`ProductName` 自己內部雖有 `at-flex-1 at-min-w-0`，但那是相對於「已經被撐大的根」計算的）。

通則：**要讓某個元素內的文字截斷，從它到「寬度被限制的祖先」之間的每一層 flex item
都必須有 `min-w-0`**。斷一層就整條失效，而且不會有任何錯誤訊息 —— 只會看到版面被撐破。

同理，不希望被壓縮的元素（Tag / 按鈕 / 圖示）要標 `flex-none`，否則它們會被
`flex-shrink: 1`（預設值）壓扁變形。

**驗證方式**：`min-w-0` / `flex-none` / arbitrary variant 都要確認 Tailwind 真的有產出（見 §10）：

```bash
pnpm build
grep -c '\.min-w-0{\|\.flex-none{\|at-name' js/dist/assets/index-*.css   # 0 = 沒產出，class 白寫
```

---

## 6.5 Profit Shop 系列前端架構（Phase 4-A）

商家後台 Profit Shop CRUD UI，4 個 resource 對應 4 個 page module。

### 目錄結構

```
js/src/pages/admin/
├── ProfitShop/         # 賣場 CRUD（含 ItemsEditor、ShopActionsButtons、ProductPickerModal[Phase 7]）
├── ProfitPartner/      # 分潤夥伴 CRUD（含 RegeneratePasswordButton ⚠ HIGH-RISK、PartnerSelector）
├── ProfitMigration/    # legacy 一頁商店匯入（含 ImportModal ⚠ HIGH-RISK）
                        # （ProfitSettings 全域設定頁已整組移除，見 profit-shop.rule.md §7）
```

### 共用工具

- **`utils/profitShopExceptionMapper.ts`**：12 種 ErrorCode → 友善繁中訊息（對齊 `api.yml` 的 `ProfitShopExceptionError.code` enum），統一處理 `slug_conflict` / `partner_in_use` / `rate_limited` / `internal_error` 等。

### Hook Pattern（取代 useTable / useForm）

由於後端回 `{code, data}` 不相容 antd-toolkit dataProvider（見 Section 3 的「Profit Shop 系列特例」），改用 `useCustom` / `useCustomMutation` 並手動讀 `result.data.data`，仍透過 Refine `useInvalidate` 維持快取失效。

### 列表頁版型（與 `#/products` 一致）

不能用 `useTable` **不代表**可以少一張篩選卡片。profit-* 的列表頁版型必須與 `#/products` 相同：篩選卡片 + 實體列表 & 新增按鈕，只是篩選改在前端 `useMemo` 做（`.claude/rules/admin-list-ui.rule.md` §3.2 路線 B）。

參考實作：`pages/admin/ProfitShop/List/index.tsx` + `List/Filter.tsx`、
`pages/admin/ProfitPartner/List/index.tsx` + `List/Filter.tsx`。
`ProfitMigration` **尚未對齊**，改動該頁時須一併補上。

### HIGH-RISK 元件的安全紀律

| 元件 | 風險面 | 紀律 |
|------|--------|------|
| `PasswordSection` | 站長指定密碼（明文進 Form） | **不是 Modal，是表單內嵌區塊**（位於 Edit 頁「聯絡 Email」下方）。兩個密碼欄掛在**主表單的 `Form`** 上（取得一般欄位的 label / 對齊 / 驗證），但**送出獨立**——自帶「更新密碼」按鈕走 `set-password` 端點，絕不隨主表單「儲存」一起送。三條連帶紀律見下方說明 |
| `RegeneratePasswordButton` | 明文新密碼僅展示一次 | 僅 `useState`（不進 cache / storage / log / Jotai / Context）+ 5 min auto-clear timer + unmount cleanup + Modal `closable=false` / `maskClosable=false` / `keyboard=false`（強制走確認按鈕）+ `navigator.clipboard.writeText`（不 fallback execCommand）+ Input.Password 預設 mask（`visibilityToggle.visible` 由 state 控制）|
| `ImportModal` | legacy 一頁商店 → profit shop 不可逆 | 4 道閘門：row Import 按鈕 disabled / Modal 開啟後 canSubmit 雙檢 importable / PartnerSelector 必選 / IMPORT 二次確認 |

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

（曾經有個 ProfitSettings 變體用 `lastFilledSettingsRef` 做 content-diff，處理「單例資源、沒有 id 可比對」的情況；該頁已移除，但那個 pattern 對日後同類頁面仍然適用——見 `profit-shop.rule.md` §12.6。）

### 「欄位長得像表單、送出卻獨立」的區塊：`PasswordSection`

夥伴報表密碼設定放在 Edit 頁「聯絡 Email」下方，視覺上就是一般表單欄位，
但**動作獨立於主表單**（Phase 4-A2 起的安全紀律：站長改名稱 / Email 時不該有任何
機會動到密碼）。這個「同一個 Form、不同送出路徑」的組合有三個必須成套的細節，
少任何一個都會壞：

| # | 做法 | 少了會怎樣 |
|---|------|-----------|
| 1 | 密碼欄的 rules **不可加 `required`** | 主表單存檔會跑 `validateFields()` 驗全部欄位 → 變成「不改密碼就存不了名稱」 |
| 2 | 主表單 submit 用 **`validateFields(['name','slug','contact_email','images'])` 明確列舉**，不可用無參數版 | 站長密碼打到一半（填了新密碼、還沒填確認）就存不了其他欄位——被一個不相干的欄位擋住 |
| 3 | 主表單 payload 維持**明確列舉欄位**（不可改成 `...values` 展開） | 密碼會跟著漏進 `PUT /profit-partners/{id}`，繞過 `set-password` 的強度驗證，也違反「主表單不含 password」的紀律 |

第 2、3 點是同一組的兩端——`Edit/index.tsx` 裡那份欄位清單與 payload 欄位要一起維護，
新增欄位時兩處都要加。

**為什麼不用 Modal**：站長是在編輯這位夥伴的資料，密碼就是其中一個欄位；
先前做成右上角按鈕 + Modal，等於把一個欄位放到頁面另一端還多一層彈窗。
`headerButtons={() => null}` 是刻意蓋掉 Refine 預設 header buttons，不是漏寫。

其餘安全紀律（明文只在 Form state、`resetFields` 與 `mutation.reset()` 並用、
送出鈕三重守門、兩欄確認、失敗時不要判 `mapped.code`）見元件本身的 docblock 與 §3。

### Partner 夥伴圖片（直接複用商品 `Gallery` 元件）

夥伴圖片**直接複用商品編輯頁「商品圖片」的 `Gallery` 元件**
（`components/product/fields/Gallery`），不另建挑選器——同一套 WordPress 媒體庫、
同一套 hover 遮罩（預覽 / 移除）、同一套「+」虛線方塊開窗操作邏輯。後端契約
（只存 `image_id`、`image_url` 為唯讀衍生、孤兒 attachment 行為）見
`profit-shop.rule.md` §7「Partner 夥伴圖片」。

`Gallery` 為此新增 4 個 **optional** props，**全部有預設值，商品頁行為零變化**：

| prop | 預設 | 用途 |
|------|------|------|
| `name?: (string \| number)[]` | `['images']` / `[id, 'images']` | 自訂 form 欄位路徑，讓非商品表單也能用 |
| `shape?: 'square' \| 'circle'` | `'square'` | `circle` 用於人物頭像情境（夥伴圖片） |
| `title?: string` | `'商品圖片'` | 區塊標題（`size="small"` 時不顯示） |
| `allowSetThumbnail?: boolean` | `true` | 是否顯示「設為封面」；`limit === 1` 時自動關閉（單張沒有封面概念） |

順帶修掉一個既有隱患：`Form.useWatch` 在欄位尚未賦值時回 `undefined`，原本的
`watchImages?.length < limit` 會是 `undefined < n` = **false**，導致「+」按鈕不出現。
商品頁因 `images` 永遠是陣列而沒踩到，新表單（如夥伴建立頁）會直接卡死無法選圖。
現已統一收斂成 `const images = watchImages ?? []`。

| 檔案 | 角色 |
|------|------|
| `ProfitPartner/components/PartnerForm.tsx` | `<Gallery name={['images']} limit={1} shape="circle" title="夥伴圖片" />` |
| `ProfitPartner/components/PartnerSelector.tsx` | 下拉選項顯示圓形頭像 + `name（slug）` |
| `ProfitPartner/List/index.tsx` | 列表「夥伴圖片」欄（40px 圓形縮圖，無圖顯示灰色 icon） |
| `ProfitPartner/utils.ts` | `toPartnerFormFieldName`（後端 `image_id` → 表單 `images`）+ `toImageId`（`TImage[]` → `number \| null`） |

**三個容易踩的點：**

1. **`Gallery` 自帶 hidden `Form.Item`，外面不可再包一層 `<Item>`**——包了會變成兩層
   巢狀欄位路徑（`image.images`），表單讀不到值且不會有任何錯誤訊息
2. **表單值是 `TImage[]`（`id` 是字串），payload 送單一數字 `image_id`**：一律走
   `toImageId(values.images)` 降維（`Number()` 轉不出正整數就回 null）。
   **不要**把 url 或整個陣列塞進 payload——`PartnerInput` 契約只有 `image_id`
3. **`PartnerSelector` 的 `label` 變成 ReactNode 後，antd 的 `optionFilterProp="label"`
   會失效**（無法對 ReactNode 做字串比對）。搜尋靠 option 上額外掛的純字串
   `name` / `slug` 欄位 + 自訂 `filterOption`——移掉自訂 `filterOption` 會讓
   夥伴搜尋整個靜默失效，且沒有任何錯誤訊息

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
// Phase 9：變體層也有自己的 inflated_count，型別與商品層一致但各自獨立計數
type TVariationOverride = { override: TPriceOverride; inflated_count: number }
type TOverrideItem = {
  product_id: number
  inflated_count: number
  override: TPriceOverride
  variations: Record<string, TVariationOverride>
}
// UI 專用（不送後端，送出前 strip `_` 前綴欄位）：
type TShopItemRow = TOverrideItem & {
  _name?: string
  _sku?: string
  _enrichState?: 'pending' | 'ok' | 'missing' | 'failed'
}
```

價格用 `string | null`、不是 `number | null`——後端 `PriceOverride` VO 是 nullable **string**，用 `bccomp` 走十進位字串比較避免浮點誤差。詳細契約與 BUG-A 修正始末見 `profit-shop.rule.md` §7「OverrideItem items 契約」；`variations[vid].inflated_count` 是 Phase 9 新增欄位，見同節「變體層覆寫」小節與下方「`ItemsEditor` 變體展開列」。

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

### `ItemsEditor` 商品拖曳排序（dnd-kit + antd Table）

賣場商品表格支援拖曳調整順序，**`items` 陣列的排列順序就是賣場前台的商品顯示順序**
（後端全程保序，契約見 `profit-shop.rule.md` §7）。實作對齊 antd 5.x 官方
「拖曳把手排序」demo，四個必須照做的點：

| # | 做法 | 為什麼 |
|---|------|--------|
| 1 | **只有把手可拖**，`listeners` 綁在 `<DragHandle>` 而非整個 `<tr>` | 列內有 `InputNumber` / 清除 / 移除按鈕；整列綁 listeners 會讓 `PointerSensor` 攔截指標事件，輸入框無法操作 |
| 2 | `listeners` 靠 `RowContext` 從 row 傳給把手欄 | antd 的 `components.body.row` 只能換掉 `<tr>`，**無法把 props 塞進特定 `<td>`** |
| 3 | body row **先判斷有沒有 `data-row-key` 再進 `useSortable`** | 空資料時 antd 會用同一個元件渲染 `ant-table-placeholder` 那列，它**沒有** `data-row-key` → `useSortable({ id: undefined })` 會炸。hooks 不能寫在條件式裡，所以拆成 `BodyRow`（判斷）+ `SortableRow`（真的 sortable）兩層 |
| 4 | `onDragEnd` 的 index **回全域 `items` 陣列查**（`findIndex` by `product_id`） | 表格有分頁，用頁內相對位置得自己換算 offset，第 2 頁以後必錯。product_id 唯一，直接查全域最省事也最不會錯 |

`SortableContext` 的 `items` 只能餵**當前頁**的 key（它只認畫面上實際渲染的列），
所以**拖曳僅限同一頁內**——這點要在 UI 上寫給使用者看，否則會被當成 bug。

**連帶的效能陷阱**：任何「依 `items` 順序」建出來的 react-query queryKey，都會因為
拖曳而變動 → 觸發整批 refetch。`useShopItemProducts` 的 `toChunks` 因此**切塊前先排序
ids**（`hooks.ts`）——`map` 是「id → 商品」查表，結果與排列順序無關，排序後同一組 id
的任意排列都對應同一個 queryKey，拖曳完全不會觸發重新查詢。少了這行，每拖一次就閃一次
Skeleton。

### `ItemsEditor` 變體展開列（Phase 9：`showExpandColumn: false` + 自刻展開鈕）

`variable` / `variable-subscription` 商品在賣場商品表格的「**商品圖片左側**」（不是表格
最左端）有一顆展開鈕，點開後可對每個變體覆寫價格與灌水購買人數（`VariationOverrideEditor`，
展開列內容）。這條路徑牽涉 4 個 rc-table 內部行為，動手前先逐行讀過原始碼驗證，不是憑經驗
猜的——這也是這幾條踩坑知識的價值所在：省下了試錯的時間。

**1. `showExpandColumn: false` 關掉預設展開欄，改在商品名稱欄自刻按鈕**

antd 預設的 `expandable` 會在表格**最左端**插入一顆獨立展開欄；但需求是「圖片左側」，圖片是
`ProductName` 在「商品名稱」欄內畫出來的，是完全不同的位置。`showExpandColumn: false`
（antd 5.7.0+，本專案 5.26.1）關掉那顆獨立欄位：

- `rc-table/lib/utils/legacyUtil.js:28`：`showExpandColumn === false` → `expandIconColumnIndex = -1`
- `rc-table/lib/hooks/useColumns/index.js:138-141`：`-1` 是 truthy，`if (expandColIndex >= 0 …)` 不成立 → `EXPAND_COLUMN` 不插入
- 同檔 `:132` 的 deprecation warning 判斷式是 `>= 0`，所以 `-1` **不會噴 console warning**

自刻的展開鈕**必須用受控 `expandedRowKeys`**（`ItemsEditor.tsx` 的 `expandedKeys` state +
`toggleExpanded`）——非受控的話點了按鈕不會有任何反應，因為 antd 不知道要展開哪一列。

**2. `expandable` 與既有 dnd-kit 拖曳排序（`components.body.row`）完全相容，零改動**

`ItemsEditor` 既有的拖曳排序（見上方「商品拖曳排序」小節）靠 `BodyRow` 檢查
`props['data-row-key']` 是否為 `undefined` 來決定要不要進 `useSortable`——這道守門原本是為
了接住空資料時 antd 渲染的 `ant-table-placeholder` 那一列，沒想到剛好也接住了展開列：

- `rc-table/lib/Body/ExpandedRow.js` 渲染展開列時**只傳 `className` 與 `style` 給自訂 row
  component，不傳 `data-row-key`**
- 展開列因此落進 `BodyRow` 既有的 `if (undefined === props['data-row-key']) return <tr {...props} />`
  分支，不會被丟進 `useSortable({ id: undefined })` 而崩潰
- `SortableContext` 的 `items` 只取 `pagedItems.map(it => it.product_id)`，展開列本來就
  不在其中，也不會被誤算進拖曳序列

`BodyRow` / `SortableRow` / `DndContext` / `SortableContext` 因此**一行都沒有為了展開列
而修改**。這條規則具備一般性：任何自訂 `components.body.row` 元件，只要沿用「先判斷
`data-row-key` 再決定要不要進特殊邏輯」這個 pattern，都能對 `expandable` 免疫，不限本專案。

**3. `expandedRowRender` 天然 lazy，收合不 unmount——查詢可以直接寫在展開列的子元件裡**

`rc-table/lib/Body/BodyRow.js` 的邏輯是 `expandedRef.current || (expandedRef.current = expanded)`
+ `if (rowSupportExpand && (expandedRef.current || expanded))`——沒展開過的列**根本不會
render**，不會發查詢；收合是 `display:none`，**不會 unmount**，重新展開**不會重新查詢**。

這代表 `useProductVariations`（變體查詢 hook）可以直接寫在 `VariationOverrideEditor`（展開
列渲染出來的子元件）裡，不需要自己刻快取或 lazy-load 邏輯，也繞開了 `useShopItemProducts`
因為 Rules of Hooks 而被迫寫死固定數量 `useList` 的限制（見上方「items 巢狀契約」小節）——
每個展開的商品各自是獨立的元件實例，hook 數量不隨賣場商品數變動，展開 / 收合單純對應
該元件的掛載 / 卸載。

**4. 展開狀態的三個交會點**

| 情境 | 處理 | 為什麼 |
|------|------|--------|
| 換頁 / 換每頁筆數 | `onChange` 內 `setExpandedKeys([])` | 分頁後畫面上是另一批商品，殘留展開狀態會讓「哪幾列展開」與使用者認知脫節 |
| 移除商品（`handleRemove`） | 一併把該 `product_id` 從 `expandedKeys` 濾掉 | 否則 `expandedRowKeys` 會留著一個已不存在的 `product_id` |
| 拖曳排序（`handleDragEnd`） | **不需處理**，`expandedKeys` 不受影響 | `expandedKeys` 存的是 `product_id` 本身、不是索引位置；重新排序後同一個商品的 `product_id` 不變，展開狀態自然跟著該商品走，不會跑到別列上 |

**`wc-rest` 的 `per_page` 為何一定要當 filter 送——antd-toolkit 原始碼層級的成因**：`react.rule.md`
§3 已經記過「`pagination.pageSize` 對 `wc-rest` 無效」這個坑，這裡補上從 `antd-toolkit`
dataProvider 原始碼反編譯出的確切成因：

```js
// antd-toolkit dataProvider getList（反編譯自 bundle，變數名經最小化）
C === "server" && (f.paged = s, f.posts_per_page = i)
```

6 個 dataProvider 共用同一份分頁序列化邏輯，`pagination` 一律被轉成 Powerhouse 風格的
`paged` / `posts_per_page` query param；`filters` 走的是另一條 `arrayFormat: 'bracket'`
序列化路徑，只有這條路徑送出去的參數才會是 WooCommerce 認得的 `per_page=100`。
`useProductVariations`（`pages/admin/ProfitShop/hooks.ts`）查詢單一商品的變體清單時同樣
必須把 `per_page` 當 filter 送，否則變體一多於 10 筆就看不到後面的（見 TD-16）。

**截斷判斷用「實際回傳筆數」而非 `total`**：`VariationOverrideEditor` 判斷變體清單是否被
WC REST `per_page` 硬上限（100）截斷時，用 `variations.length >= VARIATION_PAGE_LIMIT`，
**不是** `total >= VARIATION_PAGE_LIMIT`——antd-toolkit 的 dataProvider 在回應缺
`x-wp-total` header 時會把 `total` fallback 成 `1`，拿它當截斷判斷條件會讓「真的被截斷」
的情況因為 `total` 是假的 `1` 而完全不觸發截斷提示，使用者會以為變體清單完整，其實只看到
前 100 筆。

### 多 Tab 表單：驗證失敗**絕不能靜默 return**

`ProfitShopForm` 這類「一張 `<Form>` 切成多個 Tab」的表單有個隱性陷阱：

- 所有 Tab 都 `forceRender: true`（必要，否則沒 mount 的 `Form.Item` 不會參與驗證）
- 但**非 active 的 Tab pane 是 `display: none`** —— 欄位驗證有跑、紅字有渲染，**使用者看不到**
- 父層 `catch` 若只是 `if ('errorFields' in err) return`，畫面就**完全沒有任何回饋**

結果就是使用者回報「按了建立完全沒反應」。實際發生過：站長停在「分潤設定」Tab 按建立，
真正沒填的是「基本設定」Tab 的 slug。

**正解**（`ProfitShopForm.tsx` 的 `isFormValidationError` / `resolveValidationFeedback`）：

```tsx
if (isFormValidationError(err)) {
  const { tabKey, messages } = resolveValidationFeedback(err)
  if (tabKey) setActiveTab(tabKey)        // 主動切到出錯那一頁
  notification.warning({ message: '尚有欄位未填寫', description: messages.join('、') })
  return
}
```

欄位 → Tab 的對映表（`FIELD_TO_TAB`）與 Tabs 定義放同一個檔案，是刻意的單一真相：
新增欄位時兩者必須同步，分開放一定會忘。Tab 受控（`activeTab` / `onTabChange`）由父層
持有 state，Create / Edit 兩頁共用同一套 helper。

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

## 6.7 賣場前台 profit-shop-front 前端架構（Phase 10 → 11 → 12，第三個 bundle）

customer-facing 的分潤賣場前台（`/profit-shop/{slug}/`）。**UI 與行為 1:1 照搬 legacy 一頁商店**
（舊版 `/power-shop/{slug}/`）——原始 TSX 已從 `legacy/js/dist/assets/*.js.map` 的
`sourcesContent` 完整還原（76 個檔案），照搬時是逐檔比對，不是憑印象重寫。

### 混合式架構：PHP 印內容，React 只掛互動層

這是**與另外兩個 bundle 最大的不同**，也是 legacy 原本的設計：

```
PHP（template）                          React（本 bundle）
├─ 商品 grid（名稱/價格/圖 → SEO）      └─ ProductModal（點卡片開啟）
├─ div[data-ps-product-id]  ←─ 契約 ───┐     Gallery / 變體 / 庫存 / 購買人數 / 數量
├─ .ps-btn / .ps-not-ready  ←─ 契約 ───┤     加入購物車 → 動態建隱藏 form POST 回本頁
├─ #profit_shop_front_app   ←─ mount ──┘     （WooCommerce 原生流程，整頁重新載入）
├─ window.power_shop_front_data ────────→ 前端唯一資料來源（env / settings / products）
├─ wc_print_notices()                     加購結果（成功 / 庫存不足 / 缺屬性）
└─ <noscript> 降級：query string 加購
```

**不要把商品資訊搬離 HTML**——那是這個頁面的 SEO 全部價值。React 呈現的是同一份資料的互動版本。

**Phase 12 起本 bundle 不再有購物車**：購物車表格、樂觀更新、購物車 API 全部移除，
購物車與結帳一律走 WooCommerce 自己的頁面。演進脈絡見下方「加入購物車」一節。

### 目錄結構

```
js/src/profit-shop-front/
├── main.tsx                    # entry：ConfigProvider → AntdApp → StyleProvider（無 QueryClientProvider）
├── App.tsx                     # 掃 div[data-ps-product-id]、移除 ps-not-ready、綁 click 開 Modal
├── types.ts / atoms.ts / env.ts / utils.tsx / styles.css / defaultImage.ts
├── components/                 # Gallery / Price / PlusMinusInput / StockInfo / BuyerCount
│                               #   ProductVariationsSelect / ToggleContent
├── hooks/                      # usePlusMinusInput / useProductModalProps
└── ProductModal/               # index.tsx + AddToCartButton.tsx
```

⚠ **本 bundle 沒有 `api.ts`，也不應該有**——所有資料來自 PHP `wp_localize_script`，
唯一的「寫入」是 WooCommerce 原生表單送出。`main.tsx` 因此也**沒有** `QueryClientProvider`
（留一個沒有任何 query 的 provider 會誤導後人以為這裡還有非同步資料流）；但
`<AntdApp component={false}>` **刻意保留**，即使目前沒有任何 notification 呼叫——缺了它
`App.useApp()` 會回空物件、呼叫當下直接 throw 並中斷後續程式碼且沒有清楚的錯誤訊息（見 §2）。

### 與 admin SPA / partner portal 的三處關鍵差異

| 維度 | 本 bundle | 原因 |
|------|-----------|------|
| env 加密 | **不加密**（`window.power_shop_front_data` 是純物件） | payload 含 products 陣列，加密後前端要解一大包；且內容全是本來就公開的商品資訊 |
| env 讀不到時 | **降級不 throw** | customer-facing，任何 env 缺失都不可白屏（對比 partner portal 的 `readPartnerEnv` 會 throw 給 ErrorBoundary） |
| `StrictMode` | **不用** | 本 bundle 對 PHP 印出的**外部 DOM** 綁 listener，dev 下雙跑 effect 會多一輪解綁/重綁噪音 |

### 加入購物車：WooCommerce 原生表單送出（Phase 12，取代 Phase 11 的自訂 REST）

演進脈絡（三個 Phase、三種做法，**現在是第三種**）：

| Phase | 做法 | 為何換掉 |
|-------|------|----------|
| 10 | 寫入走 admin-ajax（`FrontAjax`）、讀取與運費走 WC Store API | 同一台購物車兩套回應形狀、兩套錯誤碼、兩套 nonce 機制，前端各寫一份 |
| 11 | 全部收斂到自訂 REST `FrontCartApi`（`/wp-json/power-shop/profit-shop-cart*`） | 站長要求移除前台購物車 UI、加購改用「原本 WordPress 的方式」，整條通道失去存在理由 |
| **12** | **WooCommerce 原生表單送出**，前台不再有任何購物車 API | — |

`AddToCartButton` 的 `handleClick` 動態建一張隱藏 `<form method="post">`、`action` 設為
`window.location.href`，append 完隱藏欄位後 `form.submit()`：

| 欄位 | 值 |
|------|----|
| `add-to-cart` | **父商品** ID（變體商品也是父 ID，對齊 WC `single-product/add-to-cart/variable.php`） |
| `quantity` | 數量選擇器的值 |
| `variation_id` | 已選變體 ID（只有 variable 商品才送） |
| `attribute_*` | 變體屬性值（只有 variable 商品才送，鍵取自 WC 自己回的 `attributes`，見下方） |
| `profit_shop_id` | 賣場 post ID（`env.shopId`，`> 0` 才送） |

送出後由 `WC_Form_Handler::add_to_cart_action()`（掛 `wp_loaded`）處理，結果寫進 WC session
的 notices，再 redirect 回本頁（或購物車頁，看站台的 `woocommerce_cart_redirect_after_add` 設定）。
notices 由 template 自印的 `wc_print_notices()` 呈現。

**三條紀律：**

1. **`profit_shop_id` 一定要送**：`AddToCartHook`（priority 5）只從 `$_GET` / `$_POST` 讀它，
   漏送就**靜默走商品原價、分潤失效，且沒有任何錯誤訊息**。表單是 `method="post"`，PHP 會正常
   填 `$_POST`，三道閘門（賣場存在 / `status=publish` / 商品屬於該賣場）照常驗證——這條路徑與
   template 的 `<noscript>` 降級加購、Phase 4-C1 的 query string 加購完全同源。
2. **`attribute_*` 的鍵取自選中變體的 `attributes` 物件**（WC `get_available_variations()`
   回的原始鍵，形如 `attribute_pa_color`），**不要**從 `selectedAttributesAtom` 的 `name`
   自行加 `attribute_` 前綴組出來。`selectedAttributes` 的 `name` 沒有前綴（例：`pa_color`），
   而自訂（非 taxonomy）屬性的名稱經 WC `sanitize_title()` 後可能與原始名稱不同（中文屬性會
   變成 percent-encoded），自行組鍵會在那些商品上安靜地對不上、被 WC 判為「缺少屬性」。
   變體本身帶的鍵才是 WC 認得的權威值；只有變體該欄位是空字串（"任意" 變體）時才退回使用者
   實際選的值。
3. **不要為了「不重新載入頁面」改回 `fetch` / axios**：送 JSON body 時 PHP **不會**填 `$_POST`，
   分潤會靜默失效。真要走 REST，就必須在呼叫 `add_to_cart()` **前**自己把 `profit_shop_id`
   橋接進 `$_POST`（Phase 11 的 `FrontCartApi::add_to_cart_with_shop_context()` 就是這麼做的），
   而且**絕不能**改用 `add_to_cart()` 第 5 個參數 `$cart_item_data` 傳值——`AddToCartHook` 開頭
   有 short-circuit（`isset($cart_item_data['profit_shop_id'])` 就 return），那會**跳過全部三道
   閘門**，變成任何人都能對任意商品套任意賣場的分潤價。

**連帶移除的 UX（已知且刻意）**：整頁會重新載入，所以樂觀更新、彩帶（`showConfetti`）、
「加入購物車成功」通知在 reload 前都來不及被看到，一併移除。`StockInfo` 的「，已加入購物車
N 件」與 `useProductModalProps` 的「數量上限扣掉購物車既有數量」同樣失去資料來源——超買改由
WooCommerce 自己在 `add_to_cart()` 時擋下並顯示 notice，那本來就是庫存判斷的唯一真相，
前端那層只是體驗優化。**不要**為了補回這些細節就把購物車讀取通道重新接回來。

### 照搬時修掉的 legacy 缺陷（不要「還原」回去）

⚠ 第 3、4、7 條所修正的程式碼（樂觀更新 rollback、樂觀項目欄位、加購成功通知）已隨
Phase 12 移除購物車而**不再存在於本 bundle**。條目刻意保留：它們記錄的是 legacy 原始碼裡
真實存在的缺陷，任何人日後再從 `legacy/js/dist/assets/*.js.map` 照搬同一批元件時仍會踩到。

| # | legacy 的問題 | 本 bundle 的處理 |
|---|---------------|------------------|
| 1 | `useEffect` 內把 `return () => removeEventListener(...)` 寫在 `els.forEach()` callback 裡——那是 forEach 的回傳值，**等於完全沒有 cleanup** | 蒐集 `{el, handler}[]`，在 `useEffect` 的 return 統一解綁 |
| 2 | `Gallery` 把 `if (images.length === 0) return` 寫在 `useState` / `useEffect` **之前**，違反 hooks 規則 | hooks 全部提到最上方 |
| 3 | `AddToCartButton` 失敗 rollback 用 `variables.id`（product_id）過濾，但樂觀項目對變體商品的 id 是 `variation_id` → **變體加購失敗後樂觀項目殘留** | 把樂觀項目的 id 放進 mutation context，用同一個值刪除 |
| 4 | 樂觀項目解構 `short_description`，但商品型別的欄位名是 `shortDescription` → 永遠 undefined | 顯式對映 `short_description: shortDescription` |
| 5 | variable 卡片價格用 `(int)` 轉型 → `99.5` 顯示成 `99`（PHP 端） | 改 float 比較 + `wc_format_decimal($v, '')` 輸出（整數價輸出完全相同） |
| 6 | `shopStatus` 在前端用 `dayjs()` 重算 | 由 PHP 依**伺服器**時間算好帶入，免除瀏覽器時鐘偏移 |
| 7 | antd `notification` / `message` 的預設 `zIndex` 只有 1010，會被佈景主題的 sticky header 蓋掉（例如「加入購物車成功」提示看不到） | `styles.css` 補 `.ant-message, .ant-notification { z-index: 999999 !important }`（照搬 legacy `custom.scss`；與商品 Modal 的 `zIndex: 1000000` 不衝突——加購時 `onMutate` 已先關閉 Modal） |

### ⚠️ Portal 到 `document.body` 的可互動元素要用 `<div role="button">`，不要用原生 `<button>`（bundle 通則）

本專案 Tailwind `preflight: false`（沒有 `button { font: inherit; padding: 0; ... }` reset），
佈景主題對 `<button>` 設的 `font-weight` / `text-transform` / `letter-spacing` / `padding` /
`min-height` / `min-width` / `display` / `box-shadow` / `:hover` 會**直接蓋進來**，長相會跟舊版
不一樣，嚴重時甚至撐壞版面。凡是本 bundle 裡「本來該用 `<button>` 卻會吃到主題樣式」的可互動
元素，一律改用 `<div role="button" tabIndex={0} onKeyDown={...}>`——算繪結果與 legacy 原本用的
非按鈕元素（`<p>` / `<img>` 包一層）完全相同，且不會被主題污染。已知踩過的三處：

| 元件 | 原本的寫法 | 為什麼會踩雷 |
|------|-----------|--------------|
| ~~`ProductModal/AddToCartButton.tsx`~~（Phase 12 已無此程式碼） | 成功通知裡「前往購物車」的可點文字，legacy 用 `<p onClick>` | antd 通知是 **portal 到 `document.body`**，會吃到佈景主題的 CSS；`jsx-a11y` 也不允許 non-interactive element 帶 interactive role，故不能直接用 `<p onClick>` |
| `components/ToggleContent.tsx` | 展開／收合的漸層遮罩 | 遮罩是 grid/flex 版面裡的一個互動熱區，換成 `<button>` 會被主題的 `padding` / `display` 污染，遮罩形狀跑掉 |
| `components/Gallery.tsx` | 縮圖（`<img>` 外層的點擊熱區） | 縮圖是 `grid-cols-4` 的**直接子元素**，若外層是帶主題樣式的 `<button>`，`aspect-square` 正方形排列會被主題的 `min-height` / `min-width` 撐歪，focus 時還會多一圈瀏覽器預設 outline |

判斷準則：只要該元素會被 portal 到 `document.body`（如 antd 通知／Modal），或是版面對其外層尺寸
有嚴格假設（如 grid item 需要精確的 `aspect-square`），就優先用 `<div role="button" tabIndex={0}
onKeyDown={...}>`，不要為了「語意正確」硬套原生 `<button>`。

### 賣場前台 Bundle Size

| Bundle | size | 備註 |
|--------|------|------|
| profit-shop-front entry | **16.7 kB** | 自家程式碼（Phase 11 為 39.7 kB；Phase 12 刪掉 Cart / api.ts / useCart / 樂觀更新後 −58%） |
| profit-shop-front CSS | **16.2 kB** | `styles.css`（`ps-btn` / `ps-addon` + Tailwind + `.ant-notification` z-index；Phase 12 移除 `ps-cart-table` 全區段後從 19.1 kB 降下來） |
| 共用 chunk | clsx 610 kB + react 91.2 kB + AntdIcon 15.7 kB | 與 admin / partner portal 共用（Vite 自動 dedup） |

### 隔離驗收（新增或改動本 bundle 時必跑）

```bash
pnpm build
# 先從 manifest 取出本 bundle 的 entry chunk 檔名（別直接 glob main-*.js —— admin entry 也叫這個）
F=$(node -e "console.log(JSON.parse(require('fs').readFileSync('js/dist/manifest.json','utf8'))['js/src/profit-shop-front/main.tsx'].file)")

for p in App1 @refinedev Refine dataProviderName wc-rest wc/store admin-ajax X-WP-Nonce profit-shop-cart axios; do
  printf "%-18s %s\n" "$p" "$(grep -o "$p" "js/dist/$F" | wc -l)"
done
# → Phase 12 起應**全部 0 命中**（本 bundle 已不打任何 API）
```

⚠ 這份清單的期望值隨 Phase 變過兩次，照舊清單會誤判：Phase 10 要求 `X-WP-Nonce` 0 命中（當時
不打任何自家 endpoint）→ Phase 11 改成預期命中 1 次（走自訂 REST `FrontCartApi`，nonce 是必要
header）→ **Phase 12 回到 0 命中**（加購改走 WooCommerce 原生表單，前台不再有任何 API 呼叫，
連 `axios` 都不再進 bundle）。

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

- **Tailwind CSS**：class **一律無 prefix**（`mb-2` / `flex` / `text-gray-500`）。只有下面表列的 6 個例外帶 `tw-`。
- **Ant Design** 遵循 ConfigProvider theme token：`colorPrimary: '#1677ff'`、`borderRadius: 6`。
- 使用 Ant Design v5 元件。

### ⚠️ `tw-` prefix：只有 6 個 class 有，其餘全是死 class

**本檔舊版曾寫「Tailwind 使用 `tw-` prefix」，那是錯的**，照著寫的樣式不會生效、也不會有任何 build 錯誤，只是安靜地不見。實際規則：

`tailwind.config.js` **沒有設 `prefix`** → 產出的 utility 一律是標準無前綴名稱。帶 `tw-` 的只有 config `plugins` 區塊手寫的這 6 個：

| 帶 `tw-` 的 class | 為什麼不能用無前綴版 |
|-------------------|---------------------|
| `tw-hidden` / `tw-block` / `tw-inline` / `tw-fixed` / `tw-columns-1` / `tw-columns-2` | 無前綴版（`hidden` / `block` / …）與 WordPress 既有樣式衝突，已寫進 config 的 `blocklist` 擋掉 |

除這 6 個之外，**任何 `tw-` 開頭的 class 都不會生效**。寫 `tw-flex`、`tw-mb-4`、`tw-text-red-500` 等於什麼都沒寫。

### Tailwind entry：`js/src/assets/styles/tailwind.css`

Tailwind 只在 CSS 檔看到 `@tailwind` directive 時才注入 utility。本專案原本雖然 `tailwindcss` 依賴、`postcss.config.cjs`、`tailwind.config.js` 三者齊全，卻**沒有任何 CSS 檔宣告過 directive** —— 整份 config 沒有消費者，本專案產出 0 個 utility。畫面上還能生效的 class 全部是 powerhouse plugin `admin.min.css`（全域 enqueue）順便帶進來的副產品。

這造成一個不可控的外部相依：powerhouse 的 Tailwind build 只掃 powerhouse 自己的原始碼，本專案寫了一個 powerhouse 剛好沒用到的 class 就是死的。實測 `max-w-3xl` / `list-disc` / `pl-5` / `border-b` 都中招。

**已修**：新增 `js/src/assets/styles/tailwind.css`（`@tailwind base` + `@tailwind utilities`），由 `App1.tsx`（admin）與 `partner-portal/main.tsx`（前台，拿不到 admin CSS）各自 import。本專案現在自行產出所有實際用到的 utility。

- 沒有 `@tailwind components` —— 那是給 `@apply` 用的，本專案無此用法
- `@tailwind base` 在 `corePlugins.preflight: false` 下**不輸出 CSS reset**，只留 `--tw-*` 變數宣告（shadow / ring / transform 這類 composite utility 的運作前提）
- 產出約 12.7 kB（gzip 3.1 kB）獨立 chunk

### ⚠️ 新增 bundle 時**不可只 import 共用的 tailwind entry**（Phase 10 實測推翻的舊說法）

本節舊版曾寫「新增 SPA bundle 時記得也 import 這個 entry」——**實測證明那樣做不夠**。

`@/assets/styles/tailwind.css` 同時被 `App1.tsx`（admin）與 `partner-portal/main.tsx` import，
Vite 因此把它提取成「共用 CSS chunk」並掛到 **admin 的 chunk**（實測是 `App1-*.css`）。
新 bundle 若只是照樣 import 它，manifest 中該 entry 的 `css` 陣列裡**根本沒有 Tailwind**：

```bash
# Phase 10 實測（修正前）
node -e "const m=require('./js/dist/manifest.json');
  console.log(m['js/src/profit-shop-front/main.tsx'].css)"
# → ['assets/main-Dhj9En9w.css']   ← 只有自家 styles.css，Tailwind 不見了
```

`Vite\enqueue_asset()` 確實會**遞迴**走 `imports` 收集 CSS（`vite-for-wp.php:300`），
但新 bundle 不 import admin 的 chunk，所以遞迴也抓不到。結果是**所有 utility 靜默失效**：
grid 變單欄、`aspect-square` / `text-red-500` / `group-hover:*` 全部不生效，
而 `pnpm build` 不會有任何警告，`tailwindcss` CLI 單獨跑也完全正常（21 kB，class 全在）。

**正確做法**：把 `@tailwind` directive 寫在該 bundle **自己的** CSS 檔裡：

```css
/* js/src/profit-shop-front/styles.css */
@tailwind base;
@tailwind utilities;

.power-shop-products .ps-btn { /* bundle 專屬樣式 */ }
```

```tsx
// main.tsx —— 不要 import 共用的 tailwind.css
import './styles.css'
```

產出的 utility 就會併入該 entry 專屬的 `main-*.css`，掛在 manifest 的 `css` 欄位上。
代價是每個 bundle 各帶一份 utility（約 15–20 kB，gzip 後 ~4 kB）——那正是 bundle 隔離
該有的行為，且共用 chunk 的做法本來就沒讓它們共用成功。

**驗收方式**（新增 bundle 後必跑）：

```bash
pnpm build
node -e "const fs=require('fs');const m=JSON.parse(fs.readFileSync('js/dist/manifest.json','utf8'));
  const e=m['js/src/<你的 bundle>/main.tsx'];
  (e.css||[]).forEach(f=>{const c=fs.readFileSync('js/dist/'+f,'utf8');
    console.log(f, (c.length/1024).toFixed(1)+'kB', c.includes('.grid-cols-2')?'✓ tailwind':'✗ 缺 tailwind')})"
```

**驗證某個 class 到底有沒有生效**：

```bash
pnpm build
grep -c '\.mb-2{' js/dist/assets/index-*.css   # 1 = 有產出；0 = 沒有（class 名稱寫錯或沒被掃到）
```

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
│                             # （Phase 4-A3 的 ProfitSettings 全域設定頁已整組移除）
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
