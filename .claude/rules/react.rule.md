---
applyTo: "**/*.ts,**/*.tsx"
---

# Power Shop — React / TypeScript 開發指引

> 適用於所有 `.ts` / `.tsx` 檔案。架構概覽見 `architecture.rule.md`。

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

## 6.5 Profit Shop 系列前端架構（Phase 4-A）

商家後台 Profit Shop CRUD UI，4 個 resource 對應 4 個 page module。

### 目錄結構

```
js/src/pages/admin/
├── ProfitShop/         # 賣場 CRUD（含 ItemsEditor、ShopActionsButtons）
├── ProfitPartner/      # 分潤夥伴 CRUD（含 RegeneratePasswordButton ⚠ HIGH-RISK、PartnerSelector）
├── ProfitMigration/    # legacy 一頁商店匯入（含 ImportModal ⚠ HIGH-RISK）
└── ProfitSettings/     # 全域設定（含 ResetButton ⚠ HIGH-RISK）
```

### 共用工具

- **`utils/profitShopExceptionMapper.ts`**：12 種 ErrorCode → 友善繁中訊息（對齊 `api.yml` 的 `ProfitShopExceptionError.code` enum），統一處理 `slug_conflict` / `partner_in_use` / `rate_limited` / `internal_error` 等。

### Hook Pattern（取代 useTable / useForm）

由於後端回 `{code, data}` 不相容 antd-toolkit dataProvider（見 Section 3 的「Profit Shop 系列特例」），改用 `useCustom` / `useCustomMutation` 並手動讀 `result.data.data`，仍透過 Refine `useInvalidate` 維持快取失效。

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
├── pages/admin/ProfitPartner/   # Phase 4-A2：夥伴 CRUD + RegeneratePasswordButton（HIGH-RISK）+ PartnerSelector
├── pages/admin/ProfitMigration/ # Phase 4-A3：legacy 一頁商店匯入 + ImportModal（HIGH-RISK）
├── pages/admin/ProfitSettings/  # Phase 4-A3：全域設定 + ResetButton（HIGH-RISK）
├── components/               # 共用元件
│   ├── general/              # CopyButton, Price
│   ├── order/                # InfoTable, OrderNotes
│   ├── post/                 # PostAction, ToggleVisibility
│   ├── product/              # Fields, ProductEditTable, ProductTable, ProductSelector
│   ├── productAttribute/     # EditForm, SortableList (全域屬性)
│   ├── term/                 # EditForm, SortableList, SortableTree, TaxonomyModal
│   └── user/                 # ContactRemarks, OrderCustomerTable, UserTable
├── hooks/                    # 全域 hooks
├── utils/                    # 全域工具函式
│   └── profitShopExceptionMapper.ts  # Phase 4-A1：12 種 ErrorCode → 友善繁中訊息
└── api/resources/            # CRUD helper wrappers
```
