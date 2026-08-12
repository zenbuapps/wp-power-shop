# 實作計劃：賣場編輯頁商品選擇器重構

> 產出日期：2026-07-29
> 產出者：`@zenbu-powers:planner`（session team `planner-product-selector`）
> 上游規格：`specs/clarify/2026-07-29-profit-shop-product-selector.md`（clarifier Phase 01）
> 下游接手：`@zenbu-powers:tdd-coordinator`
> 分支：`refactor/one-page`

---

## 概述

把 `#/profit-shop/edit/{id}` 的單選 `Select` 商品選擇器，換成「按鈕開啟 `SimpleModal` + 完整篩選器 + 分頁列表 + 跨頁勾選」，以支撐 10 萬筆商品規模；同時修正既有 BUG-A（前端扁平 items 契約導致價格覆寫被靜默丟棄）。

---

## 0. 對 Brief 前提的修正 —— BUG-A 是純前端問題，PHP 產品程式碼變更 = 0

原始 brief 寫「PHP：`ProfitShopHydrator` 讀寫路徑 + 對應 Unit / IT」。**實際讀碼結果與此不同**，且方向對本次有利。

### 事實佐證

| 層 | 檔案:行 | 是否已讀巢狀 `override` / `variations` |
|----|---------|-----------------------------------|
| Application Hydrator | `inc/classes/Domains/ProfitShop/Application/UseCase/Shop/Support/ProfitShopHydrator.php:113-128` | ✅ 已讀 |
| Persistence 反序列化 | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/CptProfitShopRepository.php:346-370` | ✅ 已讀 |
| Persistence 序列化 | `CptProfitShopRepository.php:284-295`（呼叫 `OverrideItem::to_array()`） | ✅ 已寫巢狀 |
| Item 驗證 | `inc/classes/Domains/ProfitShop/Application/Service/ItemValidator.php:62-76` | ✅ 已讀 `variations` |
| 例外對映 | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/ExceptionMapper.php:99-116` | ✅ 已就位 |
| 賣場前台 | `inc/classes/Domains/ProfitShop/Infrastructure/WordPress/templates/profit-shop-front.php:84-89` 讀 `$override->sale_price` / `regular_price` | ✅ 已讀 |

`ExceptionMapper` 現況對映：

- `ProductNotFound` → 404 `not_found`
- `InvalidPriceOverride` / `InvalidVariation` → 400 `validation_failed`

### 決定性驗證

```bash
grep -rn "price_override" inc/
```

→ 只命中 `build_price_override()` 方法名（Hydrator / Repository）與 Cart hook 的 `_profit_price_override` meta（**不同語意**，屬 Phase 3-D HMAC 簽章）。

**PHP 端沒有任何一處讀取扁平的 `price_override` / `variation_id`。**

### 結論

BUG-A 的成因 **100% 在前端**：5 個 TS 檔送出後端從未定義的扁平欄位。

- PHP **不需要任何產品程式碼修改**
- 自動滿足 Q1「不得動 Domain 層」的硬性約束（`PriceCalculator` / `CartPriceOverrideHook` / `OverrideItem` / `PriceOverride` 皆不需碰）
- **不需要 orchestrator 裁決 Domain 邊界**

PHP 端仍有實質工作，但性質是**補測試鎖契約**：

```bash
grep -rln "Hydrator" tests/    # → 0 命中
```

`ProfitShopHydrator` 目前**零測試覆蓋**，這正是 BUG-A 潛伏至今的根本原因。

> **本節結論已於 2026-07-29 由 team-lead 確認採納。**

---

## 需求重述

1. 「選擇商品」按鈕 → `SimpleModal` 內嵌完整商品篩選器 + 分頁列表，跨頁 / 跨篩選保留勾選，批次加入賣場
2. Modal 內：已加入商品標 Tag 且禁選、變體禁選、無新增商品鈕、無 `ActionArea`、選取狀態與 `#/products` 完全隔離
3. 修正 BUG-A：前端改送 Domain 巢狀 items，價格覆寫能存入並讀回
4. 賣場商品列表的名稱 / SKU 由 `wc-rest` `include=` 批次補齊；商品已刪 / 查詢失敗皆降級不阻擋
5. 抽 `ProductTableBody` 共用子元件，`#/products` 零 regression

---

## 已知風險（來自研究）

| # | 風險 | 證據 | 緩解 |
|---|------|------|------|
| **R-1** | **`syncWithLocation` 全域為 `true`**（`js/src/App1.tsx:62-63`）。Modal 內的 `useTable` 會把 filters / pagination 寫進 `#/profit-shop/edit/{id}` 的 URL，污染編輯頁網址、關閉 Modal 後殘留 | 已確認 `useTableProps.syncWithLocation?: boolean` 存在於 `@refinedev/core@4.57.9`（`dist/hooks/useTable/index.d.ts:112`）與 `@refinedev/antd@5.46.1`（`dist/hooks/table/useTable/useTable.d.ts:23`） | Modal 的 `useTable` **明確傳 `syncWithLocation: false`**；`#/products` **不傳**（維持繼承全域 `true`） |
| **R-2** | **`TProductRecord.id` 是 `string`**（`antd-toolkit/dist/wp/types/product.d.ts:19-20`），但 `TOverrideItem.product_id` 是 `number`。直接塞會產生字串 product_id，後端 `(int)` cast 後看似正常，但前端 dedupe（`Set` / `includes`）會靜默失效 → 同一商品可重複加入 | 已讀型別定義 | Modal → ItemsEditor 邊界統一 `Number(record.id)` + `Number.isFinite` 防呆；dedupe 一律以 number 為鍵 |
| **R-3** | **WC REST `per_page` 上限 100**，但賣場商品軟上限 500 → 補齊查詢需分塊；React hooks 數量不可變動 | `specs/ui/profit-shop-edit.md:176` | 固定 5 個 `useList` chunk（`enabled: chunk.length > 0`），涵蓋 500 筆；> 500 的尾段顯示 `#id`（已在軟上限語意內） |
| **R-4** | `useColumns()` 硬編 `ProductActions` 欄（`js/src/components/product/ProductTable/hooks/useColumns.tsx:89-98`），Modal 不該有 | 已讀 | `useColumns({ showActions = true })` 加參數，**預設 `true`** 保 `#/products` 不變 |
| **R-5** | `ProductTable` mount 時無條件 `setSelectedProducts([])`（`index.tsx:128-131`）+ module-level atom → 雙向污染 | 已讀 | 硬性禁止複用 atom（見限制條件 #4） |
| **R-6** | 專案**無前端單元測試框架**（package.json 無 vitest / jest / testing-library）；`tests/e2e` 的 Playwright 是 **API-level**（`03-products/product-list.spec.ts:22-40` 用 `request.get` 打 REST，非 UI 驅動） | 已讀 `tests/e2e/package.json`、`product-list.spec.ts` | 前端行為以「型別 + lint 閘門 + 手動驗收清單」把關；BUG-A 契約回歸改用**既有 API-level e2e pattern** 補 spec |
| **R-7** | `pnpm lint` / `composer lint` 在 CLAUDE.md 有記載，但 package.json 實際腳本是 `lint:ts` / `lint:php` | 已讀 `package.json:6-25` | 驗收指令以 package.json 實際名稱為準；文件不一致另記於「文件待修正」節 |

> 未發現其他已知第三方套件相容性問題。

---

## 架構變更

### 前端

```
js/src/components/product/ProductTable/
├── index.tsx                 # 改：#/products 外殼（保留 useTable / Jotai / ActionArea / CreateButton）
├── ProductTableBody.tsx      # 【新增】presentational 主體：篩選 Card + FilterTags + 列表 Card + Table
├── useProductTableQuery.ts   # 【新增】共用 useTable 設定（resource / onSearch / filters / pagination）
├── hooks/useColumns.tsx      # 改：加 { showActions?: boolean } 參數，預設 true
└── atom.tsx                  # 不動

js/src/pages/admin/ProfitShop/
├── types.ts                  # 改：TOverrideItem* 改巢狀 + 新增 TPriceOverride / TShopItemRow
├── hooks.ts                  # 改：追加 useShopItemProducts（wc-rest include= 分塊補齊）
├── Edit/index.tsx            # 改：填表 / 送出兩條路徑對齊巢狀
└── components/
    ├── ItemsEditor.tsx       # 改：移除 useSelect+Select；改「選擇商品」按鈕 + 2 欄價格 + 補齊顯示
    ├── ProfitShopForm.tsx    # 改：Item extra 文案（price_override → 原價 / 特價覆寫）
    └── ProductPickerModal/   # 【新增】
        ├── index.tsx         #   SimpleModal + ProductTableBody + 隔離選取狀態
        └── hooks.ts          #   useProductPickerModal（useSimpleModal + footer + 選取集合）
```

### PHP（僅測試，無產品程式碼變更）

```
tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php   # 【新增】
tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php  # 【新增】
tests/e2e/06-profit-shop/profit-shop-items.spec.ts                      # 【新增，API-level】
```

---

## 資料流分析

### 寫入路徑（Modal 勾選 → 落庫）

```
ProductPickerModal (local selectedRecords: TProductRecord[]，id 為 string!)
      │  Number(record.id)                       [NaN? → 跳過並警示]
      │  批次 ≤ 100                               [超過? → checkbox disabled]
      ▼
ItemsEditor.handleBatchAdd
      │  dedupe ① vs 現有 items ② 批次內部        [重複? → 靜默略過，回報實際加入 N 筆]
      │  預設 override={null,null,null} inflated=0
      ▼
Form value: items (TShopItemRow[])                [總數 >500? → 警示不阻擋]
      ▼
Edit/index.tsx handleSubmit  → strip `_name/_sku/_enrichState`
      ▼  PUT /wp-json/power-shop/profit-shops/{id}
V2Api::put_profit_shops_with_id_callback
      │  read_json_body → WP::sanitize_text_field_deep
      ▼
ProfitShopInput::from_array   (items 原樣帶過)
      ▼
UpdateShop::execute
      ├─ shopRepo->find()           [null? → ProfitShopNotFound 404]
      ├─ partnerRepo->find_by_id()  [null? → PartnerNotFound 404]
      ├─ itemValidator->validate()  [商品不存在? → ProductNotFound 404]
      │                             [variation 不屬該商品? → InvalidVariation 400]
      ├─ slugDetector->detect()     [衝突? → SlugConflictException 409]
      ▼
ProfitShopHydrator::from_input
      └─ build_override_item()      [product_id<=0? → 靜默跳過 ⚠ E-7]
           └─ new PriceOverride()   [負數/非數字? → InvalidPriceOverride 400]
                                    [sale > regular? → InvalidPriceOverride 400]
      ▼
ProfitShop Entity
      ▼
CptProfitShopRepository::save → OverrideItem::to_array() → wp_json_encode
      → update_post_meta '_profit_shop_items'   [寫入失敗? → PersistenceFailure 500]
```

### 讀取路徑（載入 → 顯示）

```
GET /profit-shops/{id}
      ▼
decode_items(JSON)  [JSON 損毀? → 回 []，靜默]  → OverrideItem[]
      ▼
ProfitShopHydrator::to_output → items[] = {product_id, inflated_count, override{}, variations{}}
                                            ↑ 無 name / sku（Q2 決策）
      ▼
useProfitShopOne (useCustom, refetchOnWindowFocus:false)
      ▼
Edit useEffect (filledIdRef 守門)  [同 id? → 不覆寫使用者編輯]
      ▼  form.setFieldsValue({ items })
ItemsEditor 首次渲染：商品欄僅 #id
      │
      └─▶ 併行 useShopItemProducts(productIds)  [ids 空? → enabled:false，不發查詢]
              wc-rest useList × 5 chunk (include=…, per_page=100)
                   ├─ 成功    → Map<number,{name,sku}> 合併
                   ├─ 查無 id → 「商品已失效」，移除鈕仍可用
                   └─ 失敗    → 降級只顯示 #id，儲存鈕維持可用
```

---

## 錯誤處理登記表

| # | 方法 / 路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|---|---|---|---|---|---|
| E-1 | `useShopItemProducts` | `wc-rest` 查詢失敗（網路 / 權限 / 500） | `HttpError` | 降級：商品欄只顯示 `#id`；**不**阻擋編輯與儲存 | 是（列內灰字提示，不彈錯誤 modal） |
| E-2 | `useShopItemProducts` | 某 `product_id` 查無資料（商品已刪） | 資料缺漏（非例外） | 標記「商品已失效」Tag，「移除」維持可用 | 是（Tag） |
| E-3 | `handleBatchAdd` | 批次含已在賣場的商品 | 業務規則 | **靜默略過不報錯**，`notification.success` 回報實際加入 N 筆 | 是（成功訊息含筆數） |
| E-4 | `handleBatchAdd` | 批次內部同一商品重複 | 業務規則 | 以 `Set<number>` dedupe，只加一次 | 否 |
| E-5 | Modal 勾選 | 已達 100 筆硬上限 | 前端守門 | 未選項 checkbox `disabled` + Alert「單次最多加入 100 筆」 | 是 |
| E-6 | 加入後總數 | 超過 500 筆軟上限 | 前端警示 | `notification.warning` 效能警示；**儲存維持可用** | 是 |
| E-7 | `build_override_item` | `product_id <= 0` | 靜默跳過（既有行為） | **維持現況**（`ItemValidator` 已在更前面攔截；此處是縱深防禦）。以測試鎖定此行為，避免日後誤改 | 否 |
| E-8 | `PriceOverride` ctor | 負數 / 非數字 / sale > regular | `InvalidPriceOverride` | ExceptionMapper → 400 `validation_failed`；前端 `mapProfitShopException` 轉繁中 | 是 |
| E-9 | `ItemValidator` | `product_id` 不存在 | `ProductNotFound` | → 404 `not_found` | 是 |
| E-10 | 前端價格輸入 | 特價 > 原價覆寫 | antd Form rule | **前端先擋**（避免整張表送出才被拒），後端 VO 為第二道 | 是（欄位紅字） |
| E-11 | `Number(record.id)` | 非數字 id（理論上不會） | 型別轉換 | `Number.isFinite` 過濾，跳過該筆並 `notification.warning` | 是 |
| E-12 | Modal 開啟中賣場 items 被外部變更 | 競態 | 業務規則 | 加入時以**當下 `value`** 重新 dedupe（不信任 Modal 開啟時的快照） | 否 |

> 全表無「處理方式 = 無 且 使用者可見 = 靜默」的 CRITICAL GAP。E-7 為刻意保留的既有行為，已用測試鎖定。

---

## 失敗模式登記表

| # | 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|---|---|---|---|---|---|---|
| F-1 | `ProductTableBody` 抽取 | `#/products` 跨頁選取失效（Jotai 接線斷） | 是：`useTable` + Jotai 全留在 shell，body 純展示 | 手動 P2-4 | 是 | `git revert` 該檔 |
| F-2 | `ProductTableBody` 抽取 | 批次操作條消失 / `CreateButton` 位置跑掉 | 是：`tableHeader` slot 保持在列表 Card 內 Table 之上 | 手動 P2-3 / P2-5 | 是 | 同上 |
| F-3 | Modal `useTable` | **未關 `syncWithLocation` → 污染編輯頁 URL** | 是：明確傳 `false`（R-1） | 手動 P3-7 | 是 | 補 prop |
| F-4 | Modal 選取 | 誤接 `selectedProductsAtom` → 雙向污染 | 是：硬性禁止 + grep 閘門 | P3-9（grep 0 命中） | 是 | 改回 local state |
| F-5 | **契約並存期** | 前端已改巢狀、PHP 未動 → 舊分支 / 快取的前端送扁平 | **低風險**：前後端同一 plugin bundle 同時發版；PHP 本來就忽略扁平欄位，行為 = 現況，不會更糟 | `ProfitShopHydratorTest` 鎖定「扁平欄位被忽略」 | 否 | 無需回滾 |
| F-6 | **契約並存期** | DB 既有 items 是否需 migration | **不需要**：舊前端送的扁平值本來就沒寫進 DB，DB 內一直是巢狀 + null，**無髒資料** | IT round-trip 驗證既有資料可讀 | 否 | — |
| F-7 | `id` 型別 | `string` product_id 混入 → dedupe 失效、重複加入 | 是：`Number()` + `Number.isFinite`（R-2） | Hydrator 測試涵蓋 `'101'` 字串輸入 | 是 | — |
| F-8 | 補齊查詢 | 500 筆觸發 5 個併發 `useList` → 短暫卡頓 | 是：chunk `enabled` 守門 + 與 Modal 共用 react-query 快取 | 手動 P5-6 | 是（loading） | 降級到只補前 100 |
| F-9 | `ItemsEditor` | 500 列 × 3 受控 `InputNumber`，打字延遲 | **已知限制**，軟上限警示；虛擬列表屬後續迭代 | — | 是（警示） | 減少商品數 |
| F-10 | Modal 未 destroy | 關閉後仍持有大量選取 state | 是：`SimpleModal destroyOnHidden` + 關閉時 reset 選取集合 | 手動 P3-8 | 否 | — |

---

## 實作步驟

### 第一階段：BUG-A 契約對齊（前端巢狀化 + PHP 契約測試）

- **依賴**：無
- **排序理由**：brief 明列「`types.ts` 契約對齊須先於 `ItemsEditor` 改價格欄位」。先把契約打直，後續階段才不會在中間狀態型別全紅
- **可獨立驗證的產出**：設原價 50000 / 特價 49900 → 儲存 → F5 → 值仍在（今天做不到）

#### 1-1. `types.ts` 契約巢狀化

- **檔案**：`js/src/pages/admin/ProfitShop/types.ts`
- **行動**：
  - 新增 `TPriceOverride = { regular_price: string | null; sale_price: string | null; signup_fee: string | null }`
  - `TOverrideItem` / `TOverrideItemInput` 改為 `{ product_id, inflated_count, override, variations }`
  - **移除** `variation_id` / `price_override` / `sku` / `name`
  - 新增 UI 專用 `TShopItemRow = TOverrideItem & { _name?: string; _sku?: string; _enrichState?: 'pending' | 'ok' | 'missing' | 'failed' }`
- **原因**：與 `specs/api/api.yml:905-952`（clarifier 已改）及 `OverrideItem::to_array()` 對齊；`_` 前綴顯式標示「不送後端」
- **依賴**：無　**風險：中**（會讓 1-2、1-3 暫時型別紅燈，同階段內修完）

> **型別選擇說明**：價格用 `string | null` 而非 `number | null` —— `PriceOverride` VO 是 nullable **string**（`PriceOverride.php:34-48`），且用 `bccomp` 走十進位字串比較避免浮點誤差（`PriceOverride.php:66`）。

#### 1-2. `Edit/index.tsx` 兩條路徑對齊

- **檔案**：`js/src/pages/admin/ProfitShop/Edit/index.tsx:52-59`、`:70-75`
- **行動**：填表 map 改帶 `override` / `variations`；送出 map 改只取 `{ product_id, inflated_count, override, variations }`，並 strip `_` 前綴欄位
- **原因**：這兩處就是 BUG-A 的注入點
- **依賴**：1-1　**風險：低**

#### 1-3. `ItemsEditor` 價格欄改 2 欄

- **檔案**：`js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx:114-204`
- **行動**：
  - 「價格覆寫（元）」單欄 → 「原價覆寫」+「特價覆寫」兩欄（各 160px，`InputNumber` + 清除鈕）
  - 移除「變體」欄（`:129-136`）
  - `handleUpdate` 改寫 `override.regular_price` / `override.sale_price`（number → `String(v)`，清空 → `null`）
  - 加前端 rule：特價 > 原價覆寫時擋下（E-10）
- **原因**：巢狀契約下 `signup_fee` 保留不開放 UI（`specs/ui/profit-shop-edit.md:53`）
- **依賴**：1-1　**風險：中**（`handleUpdate` 是 500 筆效能瓶頸點，本階段不改架構，僅換欄位）

#### 1-4. `ProfitShopForm` 文案

- **檔案**：`js/src/pages/admin/ProfitShop/components/ProfitShopForm.tsx:173`
- **行動**：`extra` 由「price_override 留空代表沿用原價」改為「原價／特價覆寫留空代表沿用商品原價」
- **依賴**：無　**風險：低**

#### 1-5. 【新增】`ProfitShopHydratorTest`

- **檔案**：`tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php`
- **行動**：純 Unit（不啟 WP），涵蓋：
  - 巢狀 `override` 正確寫入
  - 缺欄位為 null
  - `variations` 數字鍵
  - **扁平 `price_override` 被忽略（回歸鎖）**
  - `product_id <= 0` 靜默跳過（E-7）
  - 字串 `'101'` product_id 轉 int（F-7）
  - 非法價格拋 `InvalidPriceOverride`
- **原因**：Hydrator 目前零測試覆蓋，正是 BUG-A 潛伏原因
- **依賴**：無（可與 1-1 ~ 1-4 並行）　**風險：低**

#### 1-6. 【新增】items round-trip IT

- **檔案**：`tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php`
- **行動**：PUT 巢狀 items → GET → 斷言 `override.regular_price` / `sale_price` / `inflated_count` 原樣還原；`sale > regular` → 400 `validation_failed`；不存在 product → 404 `not_found`；未包含的商品被移除（整體覆寫語意）
- **對應**：`save-shop-item-overrides.feature` 全部 7 Rule
- **依賴**：無　**風險：低**

**階段成功標準**：`composer test` 全綠；手動：設原價 50000 / 特價 49900 → 儲存 → F5 → 值仍在。

---

### 第二階段：抽 `ProductTableBody`（純重構）

- **依賴**：無（可與第一階段並行）
- **排序理由**：brief 明列「須先於 `ProductPickerModal`」
- **關鍵前提**：`grep -rn "ProductTable" js/src` 確認**全專案僅一處使用**（`js/src/pages/admin/Product/List/index.tsx:4`）→ 抽取風險低
- **可獨立驗證的產出**：`#/products` 行為與現況完全一致（P2 驗收 8 條）

#### 2-1. 【新增】`useProductTableQuery.ts`

- **檔案**：`js/src/components/product/ProductTable/useProductTableQuery.ts`
- **行動**：把 `index.tsx:42-59` 的 `useTable` 設定（`resource: 'products'`、`onSearch`、`initial` / `permanent` filters、`pageSize: 20`）原封抽成 hook，簽名 `useProductTableQuery(opts?: { syncWithLocation?: boolean })`
- **原因**：讓 shell 與 Modal 共用同一份查詢設定，同時保留 `syncWithLocation` 差異（R-1）
- **註**：shell **不傳** `syncWithLocation`（`undefined` = 繼承全域 `true`，Refine 的 `syncWithLocationProp ?? syncWithLocationContext` 語意）
- **依賴**：無　**風險：低**

#### 2-2. 【新增】`ProductTableBody.tsx`

- **檔案**：`js/src/components/product/ProductTable/ProductTableBody.tsx`
- **行動**：**presentational only**。props：
  - `searchFormProps` / `tableProps` / `columns` / `rowSelection` / `valueLabelMapper` / `options` / `tableHeader?: ReactNode` / `scrollX?: number`
  - 內容 = `index.tsx:138-171` 的兩張 `antd-toolkit` `Card` + `ProductFilter` + `FilterTags` + `Table`（鋪 `defaultTableProps` + `getDefaultPaginationProps({ label: '商品' })`）
  - `tableHeader` 渲染在**列表 Card 內、Table 之上**（`.claude/rules/admin-list-ui.rule.md` §1 規則 3）
- **原因（關鍵設計決策）**：`useTable` **留在 caller** 是刻意決策 —— shell 需要 `searchFormProps`（`SelectedItem.onSelected` 重設表單）、`tableProps.dataSource` / `filters` / `pagination`（Jotai 同步 effect）。若把 `useTable` 移進 body，shell 拿不到這些，反而要開洞。留在 caller 讓 `#/products` 的 diff 是**純 JSX 搬移**，這是零 regression 最強的保證
- **依賴**：2-1　**風險：中**（F-1 / F-2）

#### 2-3. `useColumns` 加 `showActions` 參數

- **檔案**：`js/src/components/product/ProductTable/hooks/useColumns.tsx:19`
- **行動**：`useColumns({ showActions = true } = {})`；`showActions === false` 時不 push「操作」欄（`:89-98`）
- **原因**：Modal 不需逐列操作（`specs/ui/profit-shop-edit.md:94`）；預設 `true` 保 `#/products` 不變
- **依賴**：無　**風險：低**

#### 2-4. `index.tsx` 改為外殼

- **檔案**：`js/src/components/product/ProductTable/index.tsx`
- **行動**：
  - `useTable` 換成 `useProductTableQuery()`
  - Jotai / `useRowSelection` / 3 個 `useEffect`（`:109-131`）/ `ActionArea` / `SelectedItem` **全部原樣保留**
  - 兩張 Card 的 JSX 換成 `<ProductTableBody tableHeader={<CreateButton className="mb-4" />} … />`
  - **順手清 OBS-1**：刪除未使用的 `cardProps` prop（`:34-38` 死 prop），同時移除 `CardProps` import
- **依賴**：2-1、2-2、2-3　**風險：中**

**階段成功標準**：`npx tsc --noEmit` 無新增錯誤、`pnpm lint:ts` 綠；手動逐條走 P2 驗收清單。

---

### 第三階段：`ProductPickerModal`

- **依賴**：第二階段（`ProductTableBody`）
- **可獨立驗證的產出**：Modal 內完整篩選 + 分頁 + 跨頁勾選 + 三重 disabled + 100 上限

#### 3-1. 【新增】`useProductPickerModal` hook

- **檔案**：`js/src/pages/admin/ProfitShop/components/ProductPickerModal/hooks.ts`
- **行動**：
  - 包 `useSimpleModal()`（`show` / `close` / `modalProps`）
  - 持有**元件區域**選取狀態 `selectedRecords: TProductRecord[]`（`useState`，**非 Jotai**）
  - 組 `footer`：`已選 N 筆` + `加入賣場 (N)` primary 按鈕，`N === 0` 時 `disabled`
  - `onCancel` 時 reset 選取集合
- **參考**：`js/src/components/term/TaxonomyModal/hooks/index.tsx`（`useSimpleModal` + 自訂 footer 確認鈕既有 pattern）
- **依賴**：2-2　**風險：中**（F-4）

#### 3-2. 【新增】`ProductPickerModal/index.tsx`

- **檔案**：`js/src/pages/admin/ProfitShop/components/ProductPickerModal/index.tsx`
- **行動**：`<SimpleModal {...modalProps} title="選擇商品" destroyOnHidden>` 內放 `<ProductTableBody>`，其中：
  - `useProductTableQuery({ syncWithLocation: false })` ← **R-1 必要**
  - `columns={useColumns({ showActions: false })}`，並在名稱欄 render 外層對已加入商品附「已加入」`<Tag>`
  - `rowSelection` 自建（**不接 atom**）
  - `tableHeader`：達 100 上限時顯示 `<Alert>`「單次最多加入 100 筆」；未達不顯示（feature Rule 明列「未達上限時不應出現任何限制提示」）
- **`getCheckboxProps` 三重 disabled**：

  ```
  isVariation(record.type)
    ∥ existingIds.has(Number(record.id))
    ∥ (selected.length >= 100 && !isSelected)
  ```

- **跨頁保留**：`onChange` 以 **`record` 物件累積**，不依賴當前頁 `dataSource`
- **對應**：`select-shop-products.feature` Rule 1-6、9-11
- **依賴**：3-1　**風險：高**（本次最複雜元件；跨頁保留 + 三重 disabled + 上限互動）

**階段成功標準**：Modal 開啟顯示 8 個篩選條件、分頁、無新增鈕；跨頁勾選累加；已加入禁選；變體禁選；100 上限；`#/products` 選取不受影響。

---

### 第四階段：`ItemsEditor` 接上 Modal

- **依賴**：第一階段（型別）+ 第三階段（Modal）
- **可獨立驗證的產出**：勾 2 筆 → 加入 → 末端出現、預設值正確、既有項目不變

#### 4-1. 換掉舊 selector

- **檔案**：`js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx:206-233`
- **行動**：刪除 `useSelect` + `Select` + `Space.Compact` + `pendingProductId` state + `handleAdd` + `findProduct` + `TWcProductOption`；改為
  `<Button type="primary" icon={<PlusOutlined/>} className="mb-4" onClick={show}>選擇商品</Button>` + `<ProductPickerModal … />`
- **依賴**：1-3、3-2　**風險：中**

#### 4-2. `handleBatchAdd` 雙層 dedupe + 上限

- **行動**：

  ```
  Number(record.id) → Number.isFinite 過濾              (E-11 / F-7)
  Set<number> 批次內部 dedupe                            (E-4)
  過濾掉已在「當下 value」的 product_id                   (E-3 / E-12 — 用當下 value 不用快照)
  預設 { inflated_count: 0, override: {null,null,null}, variations: {} }
  附加於末端（既有項目完全不動）                          (feature Rule 8)
  onChange([...items, ...toAdd])
  notification.success(`已加入 ${toAdd.length} 筆`)      (E-3)
  若 items.length + toAdd.length > 500 → notification.warning (E-6)
  ```

- **對應**：`select-shop-products.feature` Rule 7、8、10、12
- **依賴**：4-1　**風險：中**

#### 4-3. 加入時攜帶顯示欄位

- **行動**：從 Modal 的 `TProductRecord` 直接帶 `_name` / `_sku`（`_enrichState: 'ok'`），免等第五階段補齊查詢
- **依賴**：4-2　**風險：低**

**階段成功標準**：勾 2 筆 → 加入 → 出現在列表末端、預設值正確、既有 101 的價格 / 浮報數不變；取消 Modal 列表不變。

---

### 第五階段：商品顯示資訊補齊 + 失效 / 降級

- **依賴**：第一階段（型別）+ 第四階段（UI 落點）
- **可獨立驗證的產出**：重整後名稱 / SKU 顯示；商品刪除顯示「商品已失效」；斷網仍可儲存

#### 5-1. 【新增】`useShopItemProducts`

- **檔案**：`js/src/pages/admin/ProfitShop/hooks.ts`（追加）
- **行動**：輸入 `productIds: number[]` → 切 5 個 chunk（每 100）→ 5 個

  ```ts
  useList<TProductRecord>({
    resource: 'products',
    dataProviderName: 'wc-rest',
    filters: [{ field: 'include', operator: 'eq', value: chunk }],
    pagination: { pageSize: 100 },
    queryOptions: { enabled: chunk.length > 0 },
  })
  ```

  → 回傳 `{ map: Map<number, { name, sku }>, isLoading, isError }`
- **原因**：R-3（`per_page` 上限 100 × 軟上限 500）；hook 數量固定不違反 Rules of Hooks
- **註**：`ids` 為空時全部 `enabled: false` → **不發任何查詢**（`enrich-shop-item-display.feature` Rule 1）
- **依賴**：1-1　**風險：中**

#### 5-2. `ItemsEditor` 商品欄整合補齊結果

- **行動**：商品欄 render 依 `_enrichState` 分支：
  - `ok` → 名稱 + `#id` + SKU
  - `missing` → `#id` + 「商品已失效」Tag（移除鈕仍可用）
  - `failed` → 僅 `#id`（降級，不擋儲存）
  - `pending` → `#id` + Skeleton
- **對應**：`enrich-shop-item-display.feature` Rule 3、4、5
- **依賴**：5-1　**風險：低**

**階段成功標準**：重整後名稱 / SKU 顯示；刪掉一個商品後該列顯示「商品已失效」且可移除；斷網情境仍可編輯儲存。

---

## 測試策略

### PHP

| 測試 | 類型 | 檔案 | 對應 spec Rule |
|---|---|---|---|
| `ProfitShopHydratorTest`【新增】 | Unit（不啟 WP） | `tests/Unit/Application/UseCase/Shop/Support/` | `save-shop-item-overrides.feature` 全 7 Rule；**回歸鎖**：扁平 `price_override` 被忽略 |
| `ProfitShopItemsRoundTripTest`【新增】 | Integration（啟 WP） | `tests/Integration/Infrastructure/Rest/` | 同上 Rule 4「價格覆寫完整寫入可原樣讀回」／Rule 5「未提供欄位保存為 null」／Rule 6「整體覆寫語意」／Rule 7「items 完整欄位」 |
| 既有 `UpdateShopTest` / `CreateShopTest` | Unit | `tests/Unit/Application/UseCase/Shop/` | **回歸**：PHP 端無變更，應維持全綠 |

**執行**：`composer test`（= `vendor/bin/phpunit --colors=always --testdox`）

> ⚠️ `phpunit.xml.dist` 的 `<groups><include>` 只跑 `smoke` / `happy` / `error` / `edge` / `security` group
> → **新測試必須標 `@group`**，否則不會被執行。

**必測邊界情況**：

- `override` 完全缺席 → 三欄皆 null
- `override: { regular_price: "", sale_price: null }` → 空字串視為 null（`nullable_string`，`ProfitShopHydrator.php:159-168`）
- `variations` 鍵為字串 `"1031"` → 轉 int 1031
- `variations` 鍵為 `"0"` / 負數 → 略過
- `product_id: "101"`（字串）→ 轉 int 101
- `sale_price "60000" > regular_price "50000"` → `InvalidPriceOverride`
- `regular_price "-100"` / `"abc"` → `InvalidPriceOverride`

### 前端

**專案無 JS 單元測試框架**（R-6）。`tests/e2e` 的 Playwright 是 **API-level**（用 `request.get` / `request.put` 打 REST，非 UI 驅動）。策略：

| 層 | 手段 |
|---|---|
| 型別契約 | `npx tsc --noEmit`（admin baseline 129 errors，**不得新增**） |
| 程式碼規範 | `pnpm lint:ts`（0 新增 error / warning） |
| 建置 | `pnpm build` 成功 |
| BUG-A 契約回歸 | **【新增】`tests/e2e/06-profit-shop/profit-shop-items.spec.ts`** —— 沿用既有 API-level pattern（`request.put` + `X-WP-Nonce`，見 `tests/e2e/03-products/product-list.spec.ts:22-40`）：PUT 巢狀 items → GET 斷言還原；PUT 扁平 items → 斷言價格**不**被寫入（鎖死舊契約已死） |
| UI 行為 | **手動驗收清單**（P2 / P3 / P4 / P5），無自動化 |
| 隔離驗收 | `grep -rn "selectedProductsAtom" js/src/pages/admin/ProfitShop/` → **0 命中** |

> ⚠️ **誠實標註**：`select-shop-products.feature` 的 12 條 Rule 中，**跨頁保留 / 隔離 / 上限 / dedupe** 這 4 類**只能手動驗收**。
> 若要自動化需先導入 vitest + testing-library，屬本次範圍外。
> **team-lead 已裁決：不為了「有測試」硬導入 vitest，維持手動驗收 + 登記為後續迭代。**

---

## 依賴項目

無新增 npm / composer 套件。全部複用既有：

- `antd-toolkit`：`SimpleModal` / `useSimpleModal` / `Card` / `defaultTableProps` / `getDefaultPaginationProps` / `useRowSelection`
- `@refinedev/antd`：`useTable`
- `@refinedev/core`：`useList`
- `antd`、`jotai`（僅 `#/products` shell 沿用）

---

## 風險與緩解措施

- **高**：`ProductPickerModal` 跨頁保留 + 三重 disabled + 100 上限的互動複雜度 —— 拆成獨立階段、以 `selectedRecords: TProductRecord[]` 物件累積（不依賴當前頁 `dataSource`）、逐條對 feature Rule 驗收
- **高**：`#/products` regression —— `useTable` / Jotai / `ActionArea` 全留在 shell，diff 為純 JSX 搬移；`ProductTable` 全專案僅一處使用；驗收清單 8 條逐項勾
- **中**：`syncWithLocation` 污染編輯頁 URL —— Modal 明確傳 `false`（R-1）
- **中**：`id` string / number 混用導致 dedupe 失效 —— 邊界統一 `Number()` + `Number.isFinite`（R-2）
- **中**：500 筆補齊查詢 —— 固定 5 chunk + `enabled` 守門（R-3）
- **低**：契約並存期相容性 —— 前後端同 bundle 發版；PHP 本就忽略扁平欄位，行為不會比現況更差；DB 無髒資料需 migration（F-5 / F-6）

---

## 錯誤處理策略

三層分工，各司其職：

1. **前端守門（防止無效請求出門）**：數量上限 disabled、dedupe、特價 > 原價的 Form rule、`Number.isFinite` 過濾
2. **後端 Domain 驗證（唯一真相）**：`ItemValidator`（商品 / 變體存在性）+ `PriceOverride` VO invariant（非負十進位、sale ≤ regular）。**不因前端已擋就放寬**
3. **降級不阻斷（顯示層失敗不影響業務）**：補齊查詢失敗 / 商品已刪 → 只影響顯示，編輯與儲存路徑完全不受影響

錯誤呈現統一走既有 `js/src/utils/profitShopExceptionMapper.ts`（12 種 code → 繁中），**不新增映射表**。

---

## 限制條件（此計劃不會做的事）

1. **不動 Domain 層**：`PriceCalculator`、`CartPriceOverrideHook`、`OverrideItem` Entity、`PriceOverride` VO 一律不改（Q1 範圍邊界）。研究已確認**確實不需要動**
2. **不支援選變體**：variation checkbox 維持 disabled（Q3）；`variations` 契約結構保留但 UI 不開放
3. **Modal 不做移除**：只能新增；移除仍在下方賣場商品列表操作（Q4）
4. **不複用 `selectedProductsAtom`**：module-level 全域 atom（`atom.tsx`）+ mount 時 `setSelectedProducts([])`（`index.tsx:128-131`）會雙向污染。Modal 必須自帶隔離 local state
5. **不把 Modal 內商品列表改成 `useCustom`**：CLAUDE.md Pitfall #10 約束對象是 4 個 profit-\* resource；`products` 走 `wc-rest`，形狀相容，`useTable` 正確
6. **不改 `ItemsEditor` 為虛擬列表 / 分頁**：500 筆軟上限的效能瓶頸（`handleUpdate` 每次按鍵重建整個陣列，`ItemsEditor.tsx:106-112`）留待後續迭代
7. **不修 `ItemValidator` 的 N+1**（逐筆 `wc_get_product()`，`ItemValidator.php:53-62`）：屬後續迭代
8. **不引入前端測試框架**：vitest / testing-library 導入屬獨立議題（team-lead 已裁決）
9. **不改後端 `OverrideItemOutput` 夾帶 name / sku**：Q2 決議由前端 `wc-rest` 補齊
10. **不動 `SlugInput` / `PartnerSelector` / Create 頁**（Create 仍 `items: []`）
11. **不直接改 CLAUDE.md**：文件不一致另記於「文件待修正」節，屬另一條工作線

---

## 驗收清單

### P1 — BUG-A 契約

- [ ] P1-1 `composer test` 全綠（含新增 `ProfitShopHydratorTest` / `ProfitShopItemsRoundTripTest`）
- [ ] P1-2 `composer lint` 綠
- [ ] P1-3 `vendor/bin/phpstan analyse inc --memory-limit=1G` 綠
- [ ] P1-4 設原價 50000 / 特價 49900 → 儲存 → F5 → 兩值皆還原
- [ ] P1-5 只設特價 → 重查 `regular_price` 為 `null`
- [ ] P1-6 兩欄留空 → 重查三欄皆 `null`
- [ ] P1-7 特價 60000 > 原價 50000 → 前端擋下
- [ ] P1-8 繞過前端直打 API（特價 > 原價）→ 400 `validation_failed`
- [ ] P1-9 送不存在的 product_id → 404 `not_found`
- [ ] P1-10 DevTools Network 確認送出 payload **不含** `variation_id` / `price_override` / `sku` / `name`

### P2 — `#/products` 零 regression（逐條手動）

- [ ] P2-1 篩選卡片 8 個條件皆可用，`FilterTags` 正常顯示 / 移除
- [ ] P2-2 分頁切換正常，「共 N 筆」文案正常
- [ ] P2-3 「新增商品」按鈕仍在列表 Card 內、Table 之上
- [ ] P2-4 跨頁選取：第 1 頁選 2 筆 → 第 2 頁選 1 筆 → 底部顯示已選 3 筆
- [ ] P2-5 底部 `ActionArea` + `BulkAction` + `SelectedItem` 正常出現 / 消失
- [ ] P2-6 `SelectedItem`「只看已選」可正確重設表單並帶 `include`
- [ ] P2-7 變體子列 checkbox 仍為 disabled + hidden
- [ ] P2-8 URL 仍隨篩選 / 分頁同步（`syncWithLocation` 未被誤關）

### P3 — `ProductPickerModal`

- [ ] P3-1 Modal 呈現 8 個篩選條件 + 分頁列表
- [ ] P3-2 Modal 內**無**「新增商品」按鈕、**無** `ActionArea`、**無**「操作」欄
- [ ] P3-3 已在賣場的商品標「已加入」Tag 且 checkbox disabled
- [ ] P3-4 變體子列 checkbox disabled
- [ ] P3-5 切換分頁後既有勾選不遺失
- [ ] P3-6 變更篩選條件後既有勾選不遺失
- [ ] P3-7 **開關 Modal 後編輯頁 URL 不出現 filters / current / pageSize 參數**
- [ ] P3-8 關閉 Modal 再開啟，選取集合已清空
- [ ] P3-9 `grep -rn "selectedProductsAtom" js/src/pages/admin/ProfitShop/` → 0 命中
- [ ] P3-10 在 `#/products` 選 1 筆 → 進賣場編輯頁開 Modal 選另 1 筆 → 關閉 → 回 `#/products`，原選取仍為那 1 筆
- [ ] P3-11 未勾選時「加入賣場」disabled
- [ ] P3-12 勾滿 100 筆 → 其餘 disabled + 顯示「單次最多加入 100 筆」
- [ ] P3-13 勾 99 筆時 checkbox 皆可用且**無**上限提示

### P4 — 批次加入

- [ ] P4-1 加入後新商品附加於**末端**，預設 `override` 三欄空、`inflated_count` 0
- [ ] P4-2 既有商品的價格覆寫與浮報數**完全不變**
- [ ] P4-3 取消 Modal → 賣場商品列表維持原狀
- [ ] P4-4 批次含已在賣場商品 → 不報錯、靜默略過、提示實際加入筆數
- [ ] P4-5 批次內部同一商品兩次 → 只加入一次
- [ ] P4-6 總數超過 500 → 顯示效能警示，「儲存」仍可用

### P5 — 顯示補齊

- [ ] P5-1 賣場無商品時 → DevTools 確認**未發出**補齊查詢
- [ ] P5-2 有商品時 → 補齊查詢帶 `include=101,102`
- [ ] P5-3 名稱 + SKU 正確顯示
- [ ] P5-4 刪掉某商品後重整 → 該列顯示「商品已失效」，移除鈕可用，其他列正常
- [ ] P5-5 模擬補齊查詢失敗（DevTools block request）→ 顯示 `#id`，儲存仍可用
- [ ] P5-6 200 筆商品的賣場 → 補齊分 2 個 chunk 查詢，皆成功

### 全域閘門

- [ ] G-1 `npx tsc --noEmit` 無新增錯誤（admin baseline 129）
- [ ] G-2 `pnpm lint:ts` 0 新增 error / warning
- [ ] G-3 `pnpm build` 成功
- [ ] G-4 既有 `#/profit-partner` / `#/profit-migration` / `#/profit-settings` 零 regression
- [ ] G-5 partner portal（`/profit-report/{slug}/`）零 regression

---

## 文件待修正（**不在本次範圍**，另開工作線）

規劃期間發現的文件與實際不一致，**不要在本次一併改**，登記備查：

| # | 位置 | 記載 | 實際 | 影響 |
|---|------|------|------|------|
| DOC-1 | `.claude/CLAUDE.md` §Build / Dev / Lint Commands | `pnpm lint` | `package.json:12-13` 實際腳本為 `pnpm lint:ts`（ESLint）與 `pnpm lint:php`（phpcbf + phpcs + phpstan），**無** `lint` 腳本 | 依 CLAUDE.md 執行會直接失敗；下游 agent 驗收時會卡住 |
| DOC-2 | `.claude/CLAUDE.md` 同節 | `composer lint` | ✅ 存在（`composer.json:58` → `phpcs`）—— 此條正確，僅列出以資對照 | 無 |
| DOC-3 | `.claude/rules/admin-list-ui.rule.md` §5「各列表頁對齊現況」 | 未收錄本次新增的 `ProductTableBody` 抽取結果 | — | 本次完工後應由 `@zenbu-powers:doc-updater` 補上「共用子元件」說明 |

> 建議在本次 5 個階段全部完工後，統一交 `@zenbu-powers:doc-updater` 處理 DOC-1 / DOC-3。

---

## 預估複雜度：**高**

前後端橫跨、5 個階段、含一次共用元件抽取（regression 面）與一次契約破壞性變更（BUG-A）。

單一階段複雜度：

| 階段 | 複雜度 |
|------|--------|
| 第一階段（BUG-A 契約） | 中 |
| 第二階段（`ProductTableBody` 抽取） | 中 |
| 第三階段（`ProductPickerModal`） | **高** |
| 第四階段（`ItemsEditor` 接線） | 中 |
| 第五階段（顯示補齊） | 中 |

---

## WHERE NEXT

**`@zenbu-powers:tdd-coordinator`** — 接手產出 Red → Green → Refactor 協調藍圖。

交接建議：

- **第一階段的 PHP 測試最適合走完整 TDD**：`ProfitShopHydratorTest` 先寫紅燈（尤其「扁平欄位被忽略」的回歸鎖），會直接證明 PHP 端已綠 —— 這正是「PHP 產品程式碼變更 = 0」的自動化證據
- **第二階段（`ProductTableBody` 抽取）不適用 TDD**：無前端測試框架，且是純重構。建議走「先跑一次 P2 驗收清單記錄 baseline → 重構 → 再跑一次比對」
- **第三 / 四 / 五階段**：建議交 `@zenbu-powers:react-master` 實作，以 feature Rule 逐條對照驗收
- 可考慮補派 `@zenbu-powers:test-creator` 撰寫 `tests/e2e/06-profit-shop/profit-shop-items.spec.ts`（API-level，沿用 `tests/e2e/03-products` 既有 pattern）
- 全部完工後交 `@zenbu-powers:doc-updater` 處理「文件待修正」節的 DOC-1 / DOC-3

### 關鍵路徑檔案（絕對路徑）

- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\ProfitShop\types.ts`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\ProfitShop\Edit\index.tsx`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\ProfitShop\components\ItemsEditor.tsx`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\ProfitShop\components\ProfitShopForm.tsx`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\ProfitShop\hooks.ts`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\product\ProductTable\index.tsx`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\product\ProductTable\hooks\useColumns.tsx`
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\product\ProductTable\atom.tsx`（**只讀不改**）
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\term\TaxonomyModal\hooks\index.tsx`（**參考範例**）
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\App1.tsx`（`syncWithLocation: true` 在 `:62-63`，**只讀不改**）
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\inc\classes\Domains\ProfitShop\Application\UseCase\Shop\Support\ProfitShopHydrator.php`（**只讀不改**）
- `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\inc\classes\Domains\ProfitShop\Infrastructure\Persistence\CptProfitShopRepository.php`（**只讀不改**）

### 上游規格對照

| 規格 | 路徑 |
|------|------|
| Clarify session | `specs/clarify/2026-07-29-profit-shop-product-selector.md` |
| UI 版型 | `specs/ui/profit-shop-edit.md` |
| Feature（command） | `specs/features/profit-shop/select-shop-products.feature` |
| Feature（command） | `specs/features/profit-shop/save-shop-item-overrides.feature` |
| Feature（query） | `specs/features/profit-shop/enrich-shop-item-display.feature` |
| Activity | `specs/activities/profit-shop-item-selection.activity` |
| API 契約 | `specs/api/api.yml:905-952`（`OverrideItemInput` / `OverrideItemOutput`，clarifier 已改為巢狀） |
