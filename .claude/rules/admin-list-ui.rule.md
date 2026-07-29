# Power Shop — 後台列表頁 UI 標準

> 適用於 admin SPA（`admin.php?page=power-shop`）**所有列表頁**。
> 基準範本：`#/products`（`js/src/components/product/ProductTable/index.tsx`）。
> 元件寫法慣例見 `react.rule.md`；Profit Shop 領域規範見 `profit-shop.rule.md`。

---

## 1. 標準版型：篩選卡片 + 實體列表

所有列表頁**一律**由上而下兩張卡片組成，不得只放一張裸 Table：

```
┌─────────────────────────────────────────────┐
│ Card title="篩選"                    mb-4   │
│  ┌───────────────────────────────────────┐  │
│  │ <Filter />                            │  │
│  │  grid 2 / md:3 / 2xl:4 欄位排列        │  │
│  │  [篩選] [重置] 兩顆按鈕                 │  │
│  └───────────────────────────────────────┘  │
│  <FilterTags />   ← 已套用條件的可移除 tag   │
└─────────────────────────────────────────────┘
┌─────────────────────────────────────────────┐
│ Card                                        │
│  <CreateButton className="mb-4" />  ← 新增   │
│  ┌───────────────────────────────────────┐  │
│  │ <Table />                             │  │
│  │  defaultTableProps                    │  │
│  │  + getDefaultPaginationProps({label}) │  │
│  │  + rowSelection（可批次操作時）         │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
      ↓ 有選取項目時才出現（fixed 於底部）
┌─────────────────────────────────────────────┐
│ <ActionArea>  <BulkAction/> <SelectedItem/>  │
└─────────────────────────────────────────────┘
```

**硬性規則：**

| # | 規則 |
|---|------|
| 1 | 篩選卡片與列表卡片**分開兩張 `Card`**，篩選卡片 `title="篩選"` + `className="mb-4"` |
| 2 | `Card` 來自 **`antd-toolkit`**，不是 `antd`（支援 `showCard` 開關，供被當作內嵌篩選器複用） |
| 3 | 新增按鈕放在**列表卡片內、Table 之上**（`className="mb-4"`），**不**放 Refine `<List headerButtons>` |
| 4 | **不使用 Refine `<List>` / `<Show>` shell 當列表頁外框**——版型由兩張 Card 決定，`<List>` 的 title / headerButtons 與此標準衝突 |
| 5 | Table 一律先鋪 `defaultTableProps`，再覆蓋自訂 props |
| 6 | 分頁一律 `...getDefaultPaginationProps({ label: '賣場' })`，label 用該實體的繁中名詞 |
| 7 | 有批次操作時才掛 `rowSelection` + `ActionArea`；沒有就不掛，不留空殼 |
| 8 | 欄位很多時給 `scroll={{ x: <總寬> }}`，操作欄 `fixed: 'right'` |
| 9 | **不開獨立 ID 欄**——主要名稱欄用 `NameId`（`antd-toolkit`）同時呈現名稱與 `#id`，並包 `<Link>` 點擊進編輯頁 |

### 9 的寫法

```tsx
import { Link } from '@refinedev/core'
import { NameId } from 'antd-toolkit'

{
  title: '名稱',
  dataIndex: 'title',
  width: 260,
  render: (title: string, record: TProfitShop) => (
    <Link to={`/profit-shop/edit/${record.id}`}>
      <NameId name={title} id={String(record.id)} />
    </Link>
  ),
}
```

- `NameId` 的 `id` 型別是 `string`，數字 id 要 `String(record.id)`
- 用 `<Link>` 而非 `onClick`，使用者才能中鍵 / Ctrl 點擊開新分頁（`useNavigation().edit()` 做不到）
- `NameId` 自帶 tooltip，名稱欄**不要**再加 `ellipsis: true`
- 既有 `ProductName`（`antd-toolkit/wp`）已內含同等行為，商品列表沿用它即可

---

## 2. 必用元件（不要自己刻）

| 元件 / 工具 | 來源 | 用途 |
|-------------|------|------|
| `Card` | `antd-toolkit` | 兩張卡片容器（**不要用 `antd` 的 Card**） |
| `defaultTableProps` | `antd-toolkit` | Table 基礎 props（size / bordered / rowKey 慣例） |
| `getDefaultPaginationProps({ label })` | `antd-toolkit` | 分頁列（含「共 N 筆」文案與 position） |
| `useRowSelection<T>()` | `antd-toolkit` | 多選狀態（回傳 `rowSelection` / `selectedRowKeys` / `setSelectedRowKeys`） |
| `defaultSelectProps` | `antd-toolkit` | 篩選用 Select 的預設值（multiple / allowClear / showSearch） |
| `FilterTags<T>` | `antd-toolkit/refine` | 已套用條件的 tag 列（吃 `FormInstance`，可移除單一條件） |
| `ActionArea` | `antd-toolkit/refine` | 固定底部的批次操作條 |
| `SelectedItem` | `antd-toolkit/refine` | 已選 N 筆 + 清除 / 只看已選 |
| `objToCrudFilters` | `antd-toolkit/refine` | 表單值 → Refine CrudFilters（**僅 `useTable` 路線用**） |

`FilterTags` 只要求 `FormInstance<T>`，**不限定**該 form 來自 Refine `useTable`——client-side 篩選用一般 `Form.useForm()` 同樣適用。

---

## 3. 資料來源兩條路線

同一個版型，底層資料來源分兩條，**不可混用**。

### 3.1 路線 A：`useTable`（WC / WP REST resource）

適用 `products` / `orders` / `users` 等走 `wc-rest` / `wp-rest` dataProvider 的 resource。伺服器端篩選 + 分頁。

```tsx
const { searchFormProps, tableProps, filters } = useTable<TRecord, HttpError, TFilterValues>({
  resource: 'products',
  onSearch,
  filters: { initial: objToCrudFilters(initialFilteredValues), defaultBehavior: 'replace' },
  pagination: { pageSize: 20 },
})

<Card title="篩選" className="mb-4">
  <Filter searchFormProps={searchFormProps} options={options} />
  <div className="mt-2">
    <FilterTags<TFilterValues>
      form={{ ...searchFormProps?.form } as FormInstance<TFilterValues>}
      keyLabelMapper={keyLabelMapper}
      valueLabelMapper={valueLabelMapper}
    />
  </div>
</Card>
```

參考實作：`components/product/ProductTable/index.tsx`、`components/user/UserTable/index.tsx`。

### 3.2 路線 B：`useCustom` + client-side 篩選（profit-* resource）

`profit-shop` / `profit-partner` / `profit-migration` / `profit-settings` 的後端 V2Api 回 `{code, message, data}`，與 antd-toolkit dataProvider 形狀不相容（見 `react.rule.md` §3「Profit Shop 系列特例」與 CLAUDE.md Pitfall #10），**不能用 `useTable`**。

這條路線的篩選在前端做：

```tsx
// 1. 一般 antd Form（不是 searchFormProps）
const [form] = Form.useForm<TFilterValues>()
const [applied, setApplied] = useState<TFilterValues>({})

// 2. useCustom 一次取回全部（後端目前無 server-side 分頁）
const { data, isLoading, isFetching } = useProfitShopList()
const rows = useMemo(() => (Array.isArray(data?.data?.data) ? data.data.data : []), [data])

// 3. useMemo 做 client-side 過濾
const filtered = useMemo(() => rows.filter(/* 依 applied 過濾 */), [rows, applied])

// 4. 版型與路線 A 完全相同
<Card title="篩選" className="mb-4">
  <Filter form={form} onFinish={setApplied} />
  <div className="mt-2">
    <FilterTags<TFilterValues> form={{ ...form } as FormInstance<TFilterValues>} keyLabelMapper={...} />
  </div>
</Card>
```

**client-side 篩選的三條紀律：**

1. 過濾一律包在 `useMemo`，依賴 `[rows, applied]`——不要每次 render 重算
2. 關鍵字比對統一 `toLowerCase()` + `includes()`，比對欄位（title / slug / id）寫在註解裡
3. 資料量成長到 server-side 分頁上線後，**改走路線 A 或改為送 query param**，並同步更新本檔

參考實作：`pages/admin/ProfitShop/List/index.tsx` + `List/Filter.tsx`。

---

## 4. Filter 元件寫法

每個列表頁的 Filter 是**獨立檔案**（`List/Filter.tsx` 或 `<Table>/Filter/index.tsx`），不要內聯在 List 裡。

```tsx
<Form {...formProps} layout="vertical" initialValues={initialValues}>
  <div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
    <Item name="search" label="關鍵字搜尋">
      <Input placeholder="可輸入 ID / 名稱 / Slug" allowClear />
    </Item>
    {/* 其餘欄位 */}
  </div>
  <div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
    <Button type="primary" className="w-full" icon={<SearchOutlined />} onClick={form.submit}>篩選</Button>
    <Button type="default" className="w-full" icon={<UndoOutlined />} onClick={() => { form.resetFields(); form.submit() }}>重置</Button>
  </div>
</Form>
```

| 規則 | 說明 |
|------|------|
| 欄位排列 | `grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4`（RWD 斷點固定，不自創） |
| Form layout | `layout="vertical"`，label 在上 |
| 按鈕 | 「篩選」`type="primary"` + `SearchOutlined`；「重置」`type="default"` + `UndoOutlined`，兩顆都 `className="w-full"` 佔 grid 一格 |
| 重置行為 | `form.resetFields()` **後必須 `form.submit()`**，否則畫面不會回到未篩選狀態 |
| Select | 一律鋪 `defaultSelectProps`，再覆蓋 `options` |
| 隱藏欄位 | 「包含指定 ID」這類配合 `SelectedItem` 的欄位用 `hidden`（供程式塞值，不給人操作） |
| memo | Filter 元件用 `memo()` 包起來 |

---

## 5. 各列表頁對齊現況

| 頁面 | 路由 | 資料路線 | 篩選卡片 | 新增按鈕位置 | 狀態 |
|------|------|----------|----------|--------------|------|
| 商品 | `#/products` | A（`useTable`） | ✅ | 列表卡片內 | **基準範本** |
| 用戶 | `#/users` | A（`useTable`） | ✅ | 無（不支援新增） | ✅ 已對齊 |
| 訂單 | `#/orders` | A（`useTable`） | ⚠ 註解掉 | 註解掉 | ⚠ 待補篩選卡片 |
| 分潤賣場 | `#/profit-shop` | B（`useCustom`） | ✅ | 列表卡片內 | ✅ 已對齊 |
| 分潤夥伴 | `#/profit-partner` | B（`useCustom`） | ❌ | Refine `<List headerButtons>` | ⚠ 待對齊 |
| 分潤遷移 | `#/profit-migration` | B（`useCustom`） | ❌ | 無 | ⚠ 待對齊 |

新增列表頁、或改動上表任一頁時，**必須**先讓該頁符合本標準再談其他改動。

---

## 6. 新增列表頁檢查清單

- [ ] 兩張 `Card`（`antd-toolkit`），篩選卡片 `title="篩選"` + `mb-4`
- [ ] 獨立 `Filter.tsx`，grid 斷點 `2 / md:3 / 2xl:4`，篩選 + 重置兩顆按鈕
- [ ] `FilterTags` 掛在篩選卡片底部（`div.mt-2` 包住）
- [ ] 新增按鈕在列表卡片內、Table 之上，`className="mb-4"`
- [ ] 無獨立 ID 欄；名稱欄 = `<Link>` + `NameId`（點擊進編輯頁）
- [ ] Table 鋪 `defaultTableProps` + `getDefaultPaginationProps({ label })`
- [ ] 欄位多時設 `scroll.x`，操作欄 `fixed: 'right'`
- [ ] 空狀態 `locale.emptyText` 給 `<Empty>` + 引導新增按鈕
- [ ] 頁面元件 `memo()` 包裝
- [ ] 路線 B 的 client-side 過濾包在 `useMemo`
- [ ] `pnpm lint` + `npx tsc --noEmit` 無新增錯誤
