# 實作計劃：賣場編輯頁「可變商品變體層覆寫」（價格 + 灌水購買人數）

> 產出日期：2026-07-30
> 上游需求：`/wp-admin/admin.php?page=power-shop#/profit-shop/edit/[ID]` 的「賣場商品」Tab
> 前置：Phase 7（商品選擇器重構 / BUG-A 修正）、Phase 8（Partner 夥伴圖片）
> 相關規範：`.claude/rules/profit-shop.rule.md` §7、`.claude/rules/react.rule.md` §3 / §6.4 / §6.5、`.claude/rules/wordpress.rule.md` §11

---

## 概述

在 `#/profit-shop/edit/{id}` 的「賣場商品」表格，讓 `variable` / `variable-subscription` 商品能展開子列，逐一覆寫**每個變體的原價／特價與灌水購買人數**。

- 變體**價格**覆寫：後端全鏈路已就緒 → **純前端工作**
- 變體**灌水購買人數**：wire 契約**缺欄位** → 需跨 Domain / Application / Infrastructure / OpenAPI / 前端五層擴充

---

## 需求重述（使用者原文）

> `可變商品` & `可變訂閱` 商品的圖片左側，應該要有一個 `+` icon 點擊後可以展開，然後可以對每個變體覆寫價格跟灌水購買人數

- 賣場商品表格中，`可變商品` 與 `可變訂閱` 的列，在**商品圖片左側**顯示 `+` 展開圖示
- 展開後可對該商品的**每一個變體**設定：原價覆寫、特價覆寫、灌水購買人數
- 其他商品類型（simple / grouped / external…）不顯示展開圖示
- 沿用既有巢狀 items 契約，不得回退為扁平（BUG-A 三層鎖必須維持綠燈）

---

## 一、驗證後的事實（對上游三條「已查證事實」的確認與修正）

### ✅ 事實 1 成立：變體價格覆寫後端已就緒，是純前端工作

消費鏈完整：

```
OverrideItem::$variations (array<int, PriceOverride>)
  → PriceCalculator::calculate()          六層 fallback（Domain/Service/PriceCalculator.php:57-83）
  → CartPriceOverrideHook::resolve_effective_price()
                                          （Infrastructure/WooCommerce/CartPriceOverrideHook.php:229-250）
  → HMAC 簽章 → before_calculate_totals (priority 999) → set_price
```

> **修正一處**：`grep "variations" inc/` **不會**命中 `CartPriceOverrideHook.php`——它是透過
> `$item->get_variation_override($variation_id)` 這個 **method** 取用，不是直接讀 `variations` 屬性。
> 結論不變（消費鏈存在），但下次查證別靠這個字串。實際命中 7 檔，非 8 檔。

### ✅ 事實 2 成立：變體灌水購買人數契約缺欄位

`variations[vid]` 目前只有 `override`：

- `Domain/Entity/OverrideItem.php:94-106`（`to_array()`）
- `specs/api/api.yml:930-941`（`OverrideItemInput.variations`）

需擴充。

### ✅ 事實 3 成立，且**比上游描述更嚴重**：`inflated_count` 零前台消費點

```bash
grep -rn "inflated_count|InflatedCount" inc/
# → 只有 5 檔命中，全部在 DTO / Entity / Hydrator / Repository 的「存取路徑」上
```

逐行讀過 `Infrastructure/WordPress/templates/profit-shop-front.php`（157 行全文）——
**沒有任何一處讀取 `inflated_count`**。與 `profit-shop.rule.md` §7 警告的
`default_rate` / `page_template` 反模式**完全同型**。

**新增發現：變體層比商品層更嚴重**

> 賣場前台**只渲染父商品卡片**（`profit-shop-front.php:71` `foreach ($items as $override_item)`，
> 一個 item 一張卡）。variable 商品的卡片是一顆「前往選購」連結（`:142`），
> 變體選擇發生在 **WC 單品頁**，而本外掛**完全沒有介入單品頁渲染**。
>
> ⇒ **商品層** inflated_count 有可顯示的位置（賣場卡片，補約 5 行）
> ⇒ **變體層** inflated_count **沒有任何可顯示的位置**，除非另外接管 WC 單品頁（範圍大得多）

依團隊決策「照做、不縮減範圍」，本計畫仍完整實作變體層 inflated_count 的存取路徑，但：

- **第六階段順手把商品層接上前台**（成本低，讓這個欄位至少有一半是真的）
- **變體層顯示缺口明確登記為 TD-15**

### 🔴 事實 4（**上游未提及，高風險**）：變體覆寫價在單品頁不生效，會產生「價格跳動」

```bash
grep -rn "woocommerce_variation_prices|woocommerce_product_variation_get_price|woocommerce_get_price_html" inc/
# → 0 命中
```

`Infrastructure/WooCommerce/` 只有 3 個檔案：`AddToCartHook` / `CartItemMetaDisplay` /
`CartPriceOverrideHook`——**全部只作用於購物車**。實際顧客動線：

| 步驟 | 顧客看到的價格 |
|------|----------------|
| 1. 賣場前台卡片 | 父層 override 價 |
| 2. 點「前往選購」→ WC 單品頁 | **WC 原始變體價**（我方零介入） |
| 3. 選變體 → 加入購物車 | **變體 override 價**（`CartPriceOverrideHook` priority 10 生效） |

這個落差**今天就存在**，但今天 admin UI 根本沒開放設變體覆寫，所以沒人踩到。
**這次一開放，落差就變成使用者可見的信任問題**。列為 R-1，規劃選擇性的第六-B 階段。

### ✅ 事實 5：實作前必須知道的 5 個額外驗證（省去試錯）

| 驗證項 | 結果 |
|--------|------|
| `ProfitShop::$items` 是 map 還是 list？ | **內部是 `product_id => OverrideItem` map**（`ProfitShop.php:39,138`），`items()` 回 `array_values()`。PHP 陣列保留插入順序 ⇒ 拖曳排序契約不受影響。`CartPriceOverrideHook:104` 的 `$shop->items[$product_id]` **正確無誤** |
| antd 版本 / `showExpandColumn` 可用？ | antd **5.26.1**；`rc-table/lib/interface.d.ts:173` 有 `showExpandColumn?: boolean` ✅ |
| `expandable` 與既有 dnd-kit `components.body.row` 相容？ | **完全相容，零改動**。`rc-table/lib/Body/ExpandedRow.js:52` 只傳 `className` + `style` 給自訂 row component，**不傳 `data-row-key`** ⇒ 現有 `BodyRow` 的 `if (undefined === props['data-row-key']) return <tr {...props} />`（`ItemsEditor.tsx:236`）已正確接住展開列 |
| `expandedRowRender` 是否 lazy？ | **天然 lazy**。`rc-table/lib/Body/BodyRow.js:120-121,166`：`expandedRef.current \|\| (expandedRef.current = expanded)` + `if (rowSupportExpand && (expandedRef.current \|\| expanded))` ⇒ **展開過才 render**，之後保持 mount（收合是 `display:none`）。子元件內的 `useList` 因此天然只對展開過的商品發查詢，且收合不會 unmount → 不重複查詢 |
| `#/products` 用哪個 provider？ | **`default`（Powerhouse `/v2/powerhouse/products`）**，不是 `wc-rest`（`useProductTableQuery.ts` 無 `dataProviderName`）。且 Powerhouse `Product::to_array()` 預設 partials **含 `variation`**（`powerhouse/.../Model/Product.php:83-94`）→ 回應自帶 `children`（完整變體 DTO）。而 `useShopItemProducts` 用的 `wc-rest` 只回 `variations: number[]`（ID 陣列） |

---

## 二、關鍵設計問題（1-7）的決策與理由

### D1 — 變體資料查詢策略

**決策：展開時才查（lazy），走 `wc-rest` `GET /wc/v3/products/{id}/variations`，查詢放在 `expandedRowRender` 渲染的子元件內。**

**理由：**

1. **天然 lazy，無需自己實作**：已驗證 rc-table `BodyRow.js:166` 只在 `expandedRef.current || expanded` 為真時才呼叫 `expandedRowRender` ⇒ 沒展開過的列**根本不會 render**，也就不會發查詢。收合後保持 mount（`display:none`）⇒ 重新展開**不重新查詢**。
2. **繞開 Rules of Hooks 限制**：`useShopItemProducts` 為了固定 hook 數量，被迫寫死 5 個 `useList`（`hooks.ts:273-277`）。變體查詢若也放在 `ItemsEditor` 這一層會遇到同樣問題。**把查詢放進子元件**後，每個元件實例自己的 hook 數量固定，展開/收合 = 元件掛載/卸載，完全合法且無 N+1 隱憂（只查展開的那一個）。
3. **零 regression**：完全不動 `useShopItemProducts`，Phase 7 辛苦調校的四分支降級顯示（`ok` / `missing` / `failed` / `pending`）路徑不受影響。

**⚠️ 必須遵守 `react.rule.md` §3 的 `per_page` 陷阱**：

```ts
useList<TProductVariationRecord>({
  resource: `products/${productId}/variations`,
  dataProviderName: 'wc-rest',
  filters: [{ field: 'per_page', operator: 'eq', value: 100 }],  // ⚠️ 必須當 filter 送
  pagination: { pageSize: 100 },                                  //    只給 pageSize 會靜默退回每頁 10 筆
  queryOptions: { enabled, refetchOnWindowFocus: false },
})
```

**為什麼不用 Powerhouse `default` provider 的 `children`（誘人但否決）：**

已驗證 Powerhouse products 端點預設含 `variation` partial，`children` 自帶完整變體 DTO。
若把 `useShopItemProducts` 從 `wc-rest` 換成 `default`，變體資料會「免費」跟著回來、零額外查詢。**但否決**：

- (a) 會把 Phase 7 的四分支降級顯示整條路徑置於 shape 變動的回歸風險下
- (b) 批次查詢 payload 爆增（100 商品 × N 變體全部夾帶，即使使用者一個都沒展開）
- (c) `id` 型別 string↔number 差異需重新驗證整條 `enrich.map` 邏輯

登記為未來可評估的簡化方向，本次不做。

**已知限制**：WC REST `per_page` 硬上限 100 ⇒ 變體超過 100 個的商品只顯示前 100，登記 TD-16。

---

### D2 — antd `expandable` 與既有 dnd-kit 拖曳排序 / `components.body.row` 的相容性

**決策：完全相容，`BodyRow` / `SortableRow` / `DndContext` / `SortableContext` 一行都不用改。**

**理由（已從 rc-table 原始碼逐行驗證）：**

`node_modules/rc-table/lib/Body/ExpandedRow.js:52-56`：

```js
return React.createElement(Component, {
  className: className,
  style: { display: expanded ? null : 'none' }
}, ...)
```

`Component` 就是自訂的 body row component（即 `BodyRow`），但**只傳 `className` 與 `style`，
不傳 `data-row-key`**。而 `ItemsEditor.tsx:235-240` 的現有守門：

```tsx
const BodyRow = (props: TBodyRowProps) => {
  if (undefined === props['data-row-key']) return <tr {...props} />   // ← 展開列走這條
  return <SortableRow {...(props as ...)} />
}
```

⇒ 展開列自動落入 fallback 分支，**不會進 `useSortable`**，不會出現 `id: undefined` 崩潰。
這個守門原本是為了接住空資料時的 `ant-table-placeholder` 列而寫，剛好也接住了展開列。

**另外兩點也已確認安全：**

- `SortableContext` 的 `items={sortableIds}` 只取 `pagedItems.map(it => it.product_id)`（`ItemsEditor.tsx:433-436`），展開列不在其中 ⇒ 不會被誤算進拖曳序列
- dnd-kit 的 `listeners` 只綁在 `DragHandle`（`ItemsEditor.tsx:168-181`），不是整個 `<tr>` ⇒ 展開列內的 `InputNumber` 不會被 `PointerSensor` 攔截

**需要新增的狀態管理（失敗模式登記表已列）：**

- 換頁 / 換每頁筆數 → `setExpandedKeys([])`
- `handleRemove` → 一併移除該 product_id 的 expanded key

---

### D3 — `+` icon 位置（使用者明確指定「圖片左側」）

**決策：關閉 antd 內建展開欄（`showExpandColumn: false`），在「商品名稱」欄內自刻展開按鈕，放在 `<ProductName>` 之前。**

**理由：**

antd 預設的 `expandable` 會插入一個獨立的最左欄，位置在「排序」欄**之前**——那是表格最左端，
不是使用者說的「圖片左側」（圖片在商品名稱欄內，由 `ProductName` 渲染）。
`showExpandColumn: false`（antd 5.7.0+，本專案 5.26.1 ✅）可關掉該欄，改由自己控制位置。

```tsx
<div className="flex items-center gap-2 min-w-0">
  {canExpand ? (
    <Button type="text" size="small" className="flex-none"
      icon={expanded ? <MinusSquareOutlined /> : <PlusSquareOutlined />}
      onClick={() => toggle(record.product_id)} />
  ) : <span className="w-6 flex-none" />}   {/* 佔位，讓各列名稱垂直對齊 */}
  <ProductName record={toWpRecord(product)} className="min-w-0 [&_.at-name]:truncate" />
</div>
```

**⚠️ `min-w-0` + `flex-none` 缺一不可**——`react.rule.md` §6.4 已登記：
flex item 的 `min-width` 預設 `auto`（拒絕收縮到小於內容寬度），少一層長商品名稱會撐破欄位、
橫向蓋住右邊的「商品類型 / 狀態 / 價格」等欄位，而且**不會有任何錯誤訊息**。

**`enrich` 尚未回來時不顯示展開鈕**（`pending` / `failed` 狀態無從判斷商品類型），只渲染佔位 span。

---

### D4 — `rowExpandable` 限制（只有 variable / variable-subscription 可展開）

**決策：`rowExpandable: (record) => isVariable(product?.type)`，型別判斷來源是 `resolveDisplay(record).product`。**

**理由：**

- `isVariable`（`antd-toolkit/wp`）已涵蓋 `variable` 與 `subscription_variable` 兩種型別，
  專案內 `ProductEditTable/hooks/useColumns.tsx:94` 與 `utils/index.ts:59` 都是用它，沿用即可
- 商品型別**不在** `TShopItemRow` 裡（後端 `OverrideItemOutput` 刻意不含 name / sku / type，
  避免 stale），必須從 `useShopItemProducts` 補齊的 `enrich.map` 取
- ⇒ `enrich` 未回來 / 查詢失敗 / 商品已刪除時 `product` 是 `undefined` ⇒ `rowExpandable` 回 `false`，
  **降級為不可展開**，這是正確行為（不知道型別就不該讓人展開）

---

### D5 — 變體層價格驗證

**決策：三層各司其職，前端擴充 `validateItemsPriceOverride` 涵蓋 variations，後端一行都不改。**

| 層 | 責任 | 本次動作 |
|----|------|----------|
| 前端 Form rule | 第一道閘門，避免整張表單送到後端才被拒 | **擴充**：遍歷 `it.variations`，對每個 `v.override` 跑 `isSaleOverRegular` |
| 後端 `PriceOverride` VO | **唯一真相**：非負十進位、`bccomp` 比較 sale ≤ regular | **零修改**（`PriceOverride.php:59-76` 本來就對每個 VO 實例驗證，變體的 `PriceOverride` 也會走同一條） |
| 後端 `ItemValidator` | variation 歸屬檢查（`has_variation($product_id, $vid)`） | **零修改**（`ItemValidator.php:62-76` 已實作） |

**理由：**符合 `profit-shop.rule.md` §12.9 的三層錯誤處理分工——
**不因前端已擋就放寬後端**。繞過前端直打 API 一樣會被 400 / 404 擋下。

**錯誤訊息必須同時指出商品與變體**：`商品 #101 變體 #301 的特價覆寫不得高於原價覆寫`

**⚠️ 必須沿用 `react.rule.md` §6.5 的「多 Tab 表單驗證失敗絕不能靜默 return」**：
非 active Tab 是 `display:none`，紅字看不到 ⇒ 靠 `resolveValidationFeedback` 切到 `items` Tab + notification。
`FIELD_TO_TAB`（`ProfitShopForm.tsx:26-34`）已有 `items: 'items'` 對映，無需改動。

---

### D6 — 三層測試鎖（Unit + IT + e2e）

**決策：契約擴充必須同步補三層，且既有 BUG-A 三層鎖（Unit 11 + IT 10 + e2e 4）必須維持綠燈。**

| 層 | 檔案 | 新增 / 修改 |
|----|------|-------------|
| Unit | `tests/Unit/Domain/Entity/OverrideItemTest.php` | **修改** `:170`、`:184-195` 的 `to_array` 期望形狀；**新增** `VariationOverride` 存取、委派後 `get_variation_override` 仍回 `?PriceOverride` |
| Unit | `tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php` | **修改 `:82`、`:83`、`:183` 三處**（`$item->variations[N]->regular_price` → `->override->regular_price`）；**新增** 缺 `inflated_count` → 0、負數 → 0 |
| Unit | `PriceCalculatorTest` / `ProfitShopTest` / `DuplicateShopTest` | **不改，全綠即為委派法成功的證明** |
| IT | `tests/Integration/.../CptProfitShopRepositoryTest.php` | **新增**：舊格式 JSON（variations 只有 override）反序列化 → `inflated_count` = 0 ← **R-7 核心鎖** |
| IT | `tests/Integration/.../ProfitShopItemsRoundTripTest.php` | **新增**：變體 round-trip、變體 sale>regular → 400、variation 不屬於 product → 400 |
| IT | `CartPriceOverrideHookTest` | **不改，全綠即證明變體覆寫仍正確套價** |
| e2e | `tests/e2e/06-profit-shop/profit-shop-items.spec.ts` | **新增** API-level：PUT 帶變體 `inflated_count` → GET 還原；**既有 4 個 case 必須全綠** |

**⚠️ `@group` 陷阱**（`wordpress.rule.md` §11.5）：每個 `test_*` method 都要**各自**標
`smoke` / `happy` / `error` / `edge` / `security` 其中之一，class-level 標籤不夠。
驗證方式：`--filter XxxTest --testdox` 列出的筆數必須等於檔內 `test_` 方法數。

**前端無測試框架**（無 vitest / testing-library）⇒ UI 行為以型別 + lint 閘門 + 手動驗收清單把關（第五階段 11 條）。

---

### D7 — DB 既有資料相容

**決策：`?? 0` 預設值 + 專屬測試鎖住，不需 migration（比照 Phase 7 / Phase 8）。**

**理由：**

既有 DB 內 `_profit_shop_items` 的 `variations[vid]` 只有 `{ "override": {...} }`。
反序列化落點在 `CptProfitShopRepository::build_override_item()`（`:352-370`）：

```php
$inflated = new InflatedCount( (int) ( $variation_row['inflated_count'] ?? 0 ) );
```

- 缺鍵 → 0，語意正確（沒設過灌水人數 = 0）
- `InflatedCount` VO 本身對負數 `max(0, $value)` sanitize（`InflatedCount.php:30`），不拋例外
- 下次儲存時自然補上該鍵，**無需資料轉換腳本**

**必須有測試鎖住**，否則未來重構時很容易把 `?? 0` 拿掉而不自知
（→ `CptProfitShopRepositoryTest` 新增案例）。

---

## 三、架構變更

### PHP（5 檔修改 + 1 新檔）

| # | 檔案 | 變更 |
|---|------|------|
| 1 | `inc/classes/Domains/ProfitShop/Domain/ValueObject/VariationOverride.php` | **新增**。`final class`，持有 `PriceOverride $override` + `InflatedCount $inflated_count`，提供 `to_array()` |
| 2 | `inc/classes/Domains/ProfitShop/Domain/Entity/OverrideItem.php` | `$variations` 型別改 `array<int, VariationOverride>`；`set_variation_override()` 擴充；`get_variation_override()` **改為委派**（簽章不變）；新增 `get_variation_inflated_count()`；`to_array()` 輸出加 `inflated_count` |
| 3 | `inc/classes/Domains/ProfitShop/Application/UseCase/Shop/Support/ProfitShopHydrator.php` | `build_override_item()` variations 迴圈（`:119-128`）建 `VariationOverride` |
| 4 | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/CptProfitShopRepository.php` | `build_override_item()` variations 迴圈（`:352-370`）同上 + `?? 0` 向後相容 |
| 5 | `specs/api/api.yml` | `OverrideItemInput.variations.additionalProperties` 加 `inflated_count`（`:930-941`） |
| 6 | `inc/classes/Domains/ProfitShop/Infrastructure/WordPress/templates/profit-shop-front.php` | **第六階段**：商品層 inflated_count 顯示 |

> **零修改**：`ItemValidator` / `PriceCalculator` / `CartPriceOverrideHook` / `ProductSnapshotProvider` /
> `V2Api` / `ExceptionMapper` / `AddToCartHook` / `CartItemMetaDisplay`
> ——全部只透過 `get_variation_override()` 取值，委派法保住簽章。

### 前端（3 檔修改 + 2 新檔）

| # | 檔案 | 變更 |
|---|------|------|
| 7 | `js/src/pages/admin/ProfitShop/types.ts` | `TVariationOverride` 加 `inflated_count: number` |
| 8 | `js/src/pages/admin/ProfitShop/hooks.ts` | **新增** `useProductVariations(productId, enabled)` |
| 9 | `js/src/pages/admin/ProfitShop/components/VariationOverrideEditor/index.tsx` | **新增**。展開列內容：變體子表格 |
| 10 | `js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx` | `expandable` 設定、商品名稱欄自刻展開鈕、變體 patch handlers、`validateItemsPriceOverride` 擴充、檔頭 docblock 更新 |
| 11 | `js/src/pages/admin/ProfitShop/Edit/index.tsx` | 填表路徑正規化 `variations`（`:90`） |

---

## 四、資料流分析

### 流程 A：展開 → 變體查詢 → 顯示

```
使用者點「＋」(僅 variable / variable-subscription)
      │
      ▼
ItemsEditor: setExpandedKeys([...prev, product_id])
      │
      ▼  rc-table BodyRow.js:166  expandedRef.current || expanded
expandedRowRender(record) ──▶ <VariationOverrideEditor productId />
      │                              │
      │                              ▼
      │                      useProductVariations(productId)
      │                        useList({ resource: `products/${id}/variations`,
      │                                  dataProviderName: 'wc-rest',
      │                                  filters: [{ per_page, 100 }] })  ← R-4 必要
      ▼                              │
  收合 = display:none                ▼
  (不 unmount，不重查)     ┌─────────┴──────────┐
                          ▼                    ▼
                    [查詢成功]            [shadow paths]
                          │           ┌─ isLoading  → Skeleton
                          │           ├─ isError    → Alert「變體載入失敗，
                          │           │               已設定的覆寫值仍會正常儲存」
                          │           ├─ data = []  → Empty「此商品尚未建立任何變體」
                          │           └─ >100 筆    → warning Alert「僅顯示前 100 個變體」
                          ▼
              合併顯示：WC 原價/特價（唯讀） × items.variations[vid]（可編輯）
                          │
                          ▼
        ⚠️ 孤兒鍵：variations 裡有 vid 但 WC 已刪該變體
           → 額外渲染一列「變體已失效 #vid」+ 移除鈕（不可靜默吞掉，
              否則使用者永遠無法清掉它，且送出時會被 ItemValidator 打回 400）
```

### 流程 B：編輯 → 送出 → 持久化 → 讀回

```
InputNumber onChange
      │
      ▼
handlePatchVariationOverride(product_id, variation_id, patch)
   只重建該商品那一列 + 該變體那一筆，其餘 reference 不動（R-5）
      │
      ▼
antd Form store: items[]
      │
      ▼  Form.Item rules → validateItemsPriceOverride（擴充涵蓋 variations）
   [特價 > 原價?] ──是──▶ 阻擋送出 + 切到「賣場商品」Tab + notification
      │否
      ▼
Edit/index.tsx handleSubmit
   strip `_name` / `_sku` / `_enrichState`；variations 原樣攜帶
      │
      ▼
PUT /wp-json/power-shop/profit-shops/{id}
      │
      ▼
V2Api → ItemValidator::validate()
   [variation 不屬於 product?] ──▶ InvalidVariation → 400 validation_failed
      │
      ▼
ProfitShopHydrator::build_override_item()
   variations[vid] → new VariationOverride(PriceOverride, InflatedCount)
      │
      ▼  [sale > regular?] ──▶ InvalidPriceOverride → 400
      │  [負數 / 非數字?]  ──▶ InvalidPriceOverride → 400
      ▼
ProfitShop entity（items map，插入順序＝拖曳順序）
      │
      ▼
CptProfitShopRepository::persist_meta()
   OverrideItem::to_array() → wp_json_encode(JSON_UNESCAPED_UNICODE)
   → update_post_meta(_profit_shop_items)
      │
      ▼
─────────── 讀回 ───────────
decode_items() → build_override_item()
   variations[vid]['inflated_count'] ?? 0   ← R-7 舊資料無此鍵 → 0
      │
      ▼
GET /profit-shops/{id} → 前端填表（Edit/index.tsx 正規化）
```

### 流程 C：前台消費（現況 vs 本次變更）

```
                      商品層 override    變體層 override    商品層 inflated   變體層 inflated
賣場前台卡片              ✅ 已生效           n/a              ❌→✅ 第六階段      ❌ 無位置可放
WC 單品頁                 ❌ 不生效           ❌ 不生效  ◀R-1    ❌               ❌
購物車 / 結帳             ✅ 已生效           ✅ 已生效          n/a              n/a
```

---

## 五、錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|--------------|----------|----------|-------------|
| `useProductVariations` | 網路失敗 / 500 | `HttpError` | 展開區塊顯示 `<Alert type="error">`，**不阻擋**其他列編輯與儲存 | ✅ 可見 |
| `useProductVariations` | 403（nonce 過期） | `HttpError` | 同上；Powerhouse 全域 `confirm()` 會先卡住整頁（既有 **TD-7**，本 repo 無法修） | ✅ 可見（體驗差） |
| `useProductVariations` | 商品已刪除 → 404 | `HttpError` | 同 error 分支；該列 `_enrichState` 早已是 `missing`（既有「商品已失效」Tag） | ✅ 可見 |
| `useProductVariations` | 回傳空陣列（variable 但無變體） | 非錯誤 | `<Empty>`「此商品尚未建立任何變體」+ 指向商品編輯頁的說明 | ✅ 可見 |
| `useProductVariations` | 變體 > 100 筆 | 靜默截斷 | `<Alert type="warning">`「僅顯示前 100 個變體」 | ✅ 可見 |
| `VariationOverrideEditor` | `variations` 有 vid 但 WC 已無此變體（孤兒鍵） | 資料不一致 | **必須顯示**「變體已失效 #vid」+ 移除鈕。**不可靜默隱藏**——否則使用者無從清除，送出時被 `ItemValidator` 打回 400 卻找不到源頭 | ✅ 可見 |
| `validateItemsPriceOverride` | 變體特價 > 原價 | antd 驗證 | 阻擋送出；`resolveValidationFeedback` 切到 `items` Tab + notification 列出「商品 #101 變體 #301」 | ✅ 可見 |
| `InputNumber onChange` | 非數字 / 清空 | — | `toPriceString()` 回 `null`（＝沿用原價）；`inflated_count` 回 `0` | ➖ 靜默（既有語意） |
| `ItemValidator::validate` | variation 不屬於 product | `InvalidVariation` | → 400 `validation_failed`；前端因 **Refine HttpError 吃掉 response.data**（`react.rule.md` §3）只拿得到 `message`，故 `Edit/index.tsx` 需**沿用既有 fallback**顯示後端原文 | ✅ 可見 |
| `PriceOverride` ctor | 變體 sale > regular / 負數 / 非數字 | `InvalidPriceOverride` | → 400；**後端仍是唯一真相**，不因前端已擋就放寬 | ✅ 可見 |
| `ProfitShopHydrator` | variation 鍵 ≤ 0 | — | 靜默跳過（既有行為，`ProfitShopHydratorTest` 已鎖） | ➖ 靜默（刻意） |
| `CptProfitShopRepository` | 舊資料缺 `inflated_count` 鍵 | — | `?? 0`，**必須有測試** | ➖ 靜默（刻意） |
| **顧客單品頁看到原價** | **無 WC 價格 filter（R-1）** | **無錯誤** | **第六-B 階段補 filter；若不做 = CRITICAL GAP** | **❌ 靜默（危險）** |

> ⚠️ 最後一列是本計畫唯一的 **CRITICAL GAP**（處理方式＝無 + 靜默）。第六-B 階段為它而存在。

---

## 六、失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|------------|----------|---------|---------|-------------|----------|
| `OverrideItem::to_array()` | 新欄位讓舊 JSON 反序列化失敗 | ✅ `?? 0` | ✅ 第一階段新增 | ➖ | 自動，讀為 0 |
| `OverrideItem::get_variation_override()` | 委派後回傳型別漂移，破壞 `PriceCalculator` | ✅ 簽章不變 | ✅ 既有 `PriceCalculatorTest` 全綠即證 | ❌ | — |
| `CartPriceOverrideHook::resolve_effective_price()` | 變體覆寫取不到 → 靜默 fallback 原價（商家損失） | ✅ 同上 | ✅ 既有 `CartPriceOverrideHookTest` | ❌ 靜默 | 需人工核對 |
| `expandable` + dnd-kit | 展開列進 `useSortable` → `id: undefined` 崩潰 | ✅ 既有 `BodyRow` guard 已接住（已從 rc-table 原始碼驗證） | ⚠️ 無自動測試（前端無測試框架） | ✅ 會白屏 | 手動驗收清單 |
| `SortableContext` | 展開列被誤算進拖曳序列 | ✅ `sortableIds` 只取 `pagedItems` 的 `product_id` | ⚠️ 手動 | ✅ 順序錯亂 | 手動驗收 |
| 分頁切換 | 展開狀態殘留到不同商品 | ⚠️ **需處理**：換頁 / 換每頁筆數時 `setExpandedKeys([])` | ⚠️ 手動 | ✅ | 第五階段實作 |
| 移除商品 | 該商品仍在 `expandedKeys` | ⚠️ **需處理**：`handleRemove` 一併移除該 key | ⚠️ 手動 | ➖ | 第五階段實作 |
| 變體 patch | 誤把整個 `items` 重建 → 輸入延遲 | ⚠️ 需謹慎實作（R-5） | ⚠️ 手動 | ✅ 卡頓 | 第五階段驗收含 100 筆壓測 |
| `validateItemsPriceOverride` | 只驗商品層、漏驗變體層 → 送到後端才 400 | ⚠️ **需擴充** | ✅ 可由 IT 反證 | ✅ | 第五階段 |
| 前台變體價 | 單品頁與購物車價格不一致 | ❌ **未處理（R-1）** | ❌ | ❌ **靜默** | **第六-B 階段** |
| 變體 inflated_count | 存了但前台永遠不顯示 | ❌ **未處理（R-2）** | ➖ | ❌ **靜默** | 登記 TD-15，本次不做 |

---

## 七、已知風險與緩解

| ID | 風險 | 等級 | 緩解 |
|----|------|------|------|
| **R-1** | **變體覆寫價在 WC 單品頁不生效，顧客會看到「單品頁一個價、購物車另一個價」** | **高** | 第六-B 階段補 `woocommerce_variation_prices` + `woocommerce_product_variation_get_price` filter；若不做，第五階段步驟 6 的 UI 揭露成為**必要項**，並登記 TD-17 |
| **R-2** | 變體層 `inflated_count` 零前台顯示點，是「存得進去、讀得出來、但沒人用」的反模式 | 中 | 第六階段接商品層前台；變體層登記 TD-15，UI 文案不承諾前台效果 |
| **R-3** | `OverrideItem::$variations` 型別變更破壞既有測試斷言 | 中 | 用**委派法**保留 `get_variation_override(): ?PriceOverride` 簽章 ⇒ 生產程式碼零改動；只有 3 個直接讀屬性的測試斷言要改（`ProfitShopHydratorTest.php:82,83,183`，已定位行號） |
| **R-4** | wc-rest `products/{id}/variations` 預設每頁 **10** 筆（`react.rule.md` §3 已登記的坑） | 中 | `per_page` 必須當 **filter** 送；WC 硬上限 100 ⇒ 超過 100 變體只顯示前 100，登記 TD-16 |
| **R-5** | `ItemsEditor` 每次按鍵重建整個 `items` 陣列（既有設計，`SOFT_ITEM_LIMIT = 500` 註解已言明）；加上變體層後巢狀更深 | 中 | 變體 patch 只重建「該商品那一列 + 該變體那一筆」，其餘 object reference 不動；不引入新的全表重算 |
| **R-6** | 展開過的列保持 mount ⇒ 同時展開多個商品會累積 active query | 低 | react-query 自帶快取；不額外處理，僅登記 |
| **R-7** | DB 既有 `variations[vid]` 無 `inflated_count` 鍵 | 低 | `?? 0` 預設 + 專屬測試鎖住，**不需 migration**（比照 Phase 7 / 8） |
| **R-8** | `api.yml` 的 `variations` 型別宣告與 wire 實際形狀已不符（空值是 `[]` 不是 `{}`） | 低 | 既有 **TD-6**，本次不修，但新增欄位時不可讓情況惡化 |

---

## 八、分階段實作計劃

> 七個階段皆可獨立驗證、獨立合併。第一～二階段完成後系統行為**完全不變**（純契約擴充），
> 第五階段才真正開放 UI。

### 第一階段：PHP 契約擴充（variations 加 inflated_count）

**風險：中｜複雜度：中｜依賴：無**

1. **新增 `VariationOverride` ValueObject**（`inc/classes/Domains/ProfitShop/Domain/ValueObject/VariationOverride.php`）
   - 行動：`final class`，`__construct(public readonly PriceOverride $override, public readonly InflatedCount $inflated_count)`；`to_array(): array{override: array{...}, inflated_count: int}`
   - 原因：`PriceOverride` 是單純的三欄價格 VO，硬塞 `inflated_count` 會污染它在 `PriceCalculator` / `ProductSnapshot` 的其他用途
   - 依賴：無｜風險：低

2. **改 `OverrideItem`**（`.../Domain/Entity/OverrideItem.php`）
   - `$variations` 型別 → `array<int, VariationOverride>`（含 docblock `:29-31`、`:39`）
   - `set_variation_override(int $vid, PriceOverride $o, ?InflatedCount $c = null)`——第三參數可選，`null` 時保留既有值（無既有值則 `new InflatedCount(0)`）
   - `get_variation_override(int $vid): ?PriceOverride` → **改為 `return $this->variations[$vid]?->override;`**（簽章與回傳型別完全不變）
   - **新增** `get_variation_inflated_count(int $vid): ?InflatedCount`
   - `to_array()`（`:94-106`）→ `$variations[$vid] = ['override' => ..., 'inflated_count' => ...]`
   - 原因：委派法讓 `PriceCalculator:50` 與 `CartPriceOverrideHook:231` 零改動
   - 依賴：步驟 1｜風險：中

3. **改 `ProfitShopHydrator::build_override_item()`**（`:119-128`）
   - `$variations[$vid_int] = new VariationOverride(self::build_price_override($v_override), new InflatedCount((int) ($v_row['inflated_count'] ?? 0)))`
   - 依賴：步驟 1-2｜風險：低

4. **改 `CptProfitShopRepository::build_override_item()`**（`:352-370`）
   - 同上，`$variation_row['inflated_count'] ?? 0`
   - 原因：**這是 R-7 向後相容的落點**——舊 DB JSON 沒有此鍵
   - 依賴：步驟 1-2｜風險：低

5. **更新 `specs/api/api.yml`**（`:930-941`）
   - `variations.additionalProperties.properties` 加 `inflated_count: { type: integer, default: 0 }`
   - 同步移除 description 裡「目前 Admin UI 尚未開放編輯，保留供後續迭代」（第五階段後不再成立）
   - 依賴：無｜風險：低

6. **測試（PHP）**——見 D6 表格

**驗收條件**

```bash
composer lint                                    # 期望 全綠（baseline 154/154）

# Unit（CI 不跑 unit，見 TD-4，必須本機驗）
./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --colors=never
# 期望：baseline 340 tests / 677 assertions / 1 failure(TD-2) → 新增 N 筆，failure 仍為 1

./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration --colors=never
# 期望：baseline 171 tests / 742 assertions / 21 failures(TD-1) → 新增 N 筆，failures 仍為 21

# @group 陷阱驗證：逐檔確認 testdox 筆數 == 檔內 test_ 方法數
```

---

### 第二階段：前端型別與填表 / 送出路徑對齊（UI 尚未改變）

**風險：低｜複雜度：低｜依賴：第一階段**

1. **`types.ts`**：`TVariationOverride` → `{ override: TPriceOverride; inflated_count: number }`
   - 註解註明：價格是 `string | null`（後端 `bccomp` 十進位字串），`inflated_count` 是 `number`

2. **`Edit/index.tsx:90`**：`variations: it.variations ?? {}` → 逐 variation 正規化

   ```ts
   variations: Object.fromEntries(
     Object.entries(it.variations ?? {}).map(([vid, v]) => [vid, {
       override: {
         regular_price: v?.override?.regular_price ?? null,
         sale_price: v?.override?.sale_price ?? null,
         signup_fee: v?.override?.signup_fee ?? null,
       },
       inflated_count: v?.inflated_count ?? 0,
     }])
   )
   ```

   - 原因：與 `:85-89` 對 `override` 逐欄取值**完全同一個理由**（既有註解已寫明）——
     後端可能回缺欄位的舊資料，`ItemsEditor` 讀到 `undefined` 會讓 `InputNumber` 從受控變非受控
   - 風險：低

**驗收條件**

```bash
npx tsc --noEmit                     # baseline 127
npx eslint "js/src/**/*.{ts,tsx}"    # baseline 0 errors / 135 warnings（不加 --fix）
pnpm build                            # 成功
```

**UI 行為此時應與現況完全一致**（variations 仍只是原樣攜帶）。

---

### 第三階段：變體查詢 hook

**風險：低｜複雜度：低｜依賴：無（可與一、二階段並行）**

1. **`ProfitShop/hooks.ts` 新增 `useProductVariations(productId: number, enabled: boolean)`**——完整寫法見 D1

**驗收條件**：`npx tsc --noEmit` / `npx eslint` / `pnpm build` 無新增問題。

---

### 第四階段：`VariationOverrideEditor` 子元件（獨立可測）

**風險：中｜複雜度：中｜依賴：第二、三階段**

1. **新增 `components/VariationOverrideEditor/index.tsx`**
   - Props：`productId` / `variations: TVariationOverrideMap` / `onPatchOverride(vid, patch)` / `onPatchInflated(vid, n)` / `onRemoveOrphan(vid)` / `disabled`
   - 內部：呼叫 `useProductVariations`；渲染 `size="small"` 的內嵌 `<Table>`
   - 欄位：
     - `變體`——`attributes[].name: option` 串接 + `#vid` + SKU
     - `目前價格`——WC 原價 / 特價（唯讀）
     - `覆寫價格`——上下兩個 `InputNumber`，`prefix` 標「原價」「特價」，`placeholder`「沿用原價」「沿用特價」＋清除鈕
     - `灌水購買人數`——`InputNumber min={0}`
   - **四個 shadow path 分支**：`isLoading` → Skeleton；`isError` → Alert（明說「已設定的覆寫值仍會正常儲存」）；`data.length === 0` → Empty；`>100` → warning Alert
   - **孤兒鍵處理**：`Object.keys(variations)` 中不存在於查詢結果的 vid，額外渲染「變體已失效 #vid」列 + 移除鈕
   - ⚠️ 語意沿用商品層：**留空＝`null`＝沿用原價；填 0＝`"0"`＝免費贈品**，兩者不可互相吃掉
   - ⚠️ 本元件**不**放進 `SimpleModal`，故無 `react.rule.md` §6.4 的遮罩 `preventDefault` 問題；
     但**不得**使用 `type="submit"` 按鈕（外層 `ProfitShopForm` 是一張大 `<Form>`）
   - 風險：中

**驗收條件**：`npx tsc --noEmit` / `npx eslint` / `pnpm build`；元件尚未被引用，行為零影響。

---

### 第五階段：`ItemsEditor` 接上 expandable（功能上線）

**風險：高｜複雜度：高｜依賴：第四階段**

1. **展開狀態**：`const [expandedKeys, setExpandedKeys] = useState<number[]>([])`
   - 換頁 / 換每頁筆數 → `setExpandedKeys([])`
   - `handleRemove` → 一併移除該 key

2. **`expandable` 設定**

   ```tsx
   expandable={{
     showExpandColumn: false,          // ⚠️ 關掉 antd 預設最左欄，改由商品名稱欄自刻（滿足「圖片左側」）
     expandedRowKeys: expandedKeys,
     rowExpandable: (record) => {
       const { product } = resolveDisplay(record)
       return isVariable(product?.type as string)   // antd-toolkit/wp，涵蓋 variable + subscription_variable
     },
     expandedRowRender: (record) => <VariationOverrideEditor ... />,
   }}
   ```

   **不需動 `BodyRow` / `SortableRow` / `DndContext` / `SortableContext`**（見 D2）

3. **`+` 圖示位置**——完整寫法見 D3

4. **變體 patch handlers**（R-5：最小重建範圍）

   ```tsx
   const handlePatchVariationOverride = (pid, vid, patch) =>
     onChange?.(items.map((it) => it.product_id !== pid ? it : {
       ...it,
       variations: { ...it.variations,
         [vid]: { ...(it.variations[vid] ?? emptyVariationOverride()),
                  override: { ...(it.variations[vid]?.override ?? emptyPriceOverride()), ...patch } } },
     }))
   ```

   `handlePatchVariationInflated` 同理；`handleRemoveOrphanVariation` 用解構刪鍵

5. **`validateItemsPriceOverride` 擴充**——見 D5

6. **R-1 的 UI 揭露（若第六-B 不做則為必要）**
   - 展開區塊頂部加 `<Alert type="info">`：
     「變體覆寫價於**加入購物車後**生效；商品單頁仍顯示商品原始價格。」
   - 原因：據實告知，與 `BasicTab` 對 shortcode 模式的處理方式同一條紀律（`profit-shop.rule.md` §7）
     ——**不為了畫面好看而隱瞞**

7. **更新 `ItemsEditor` 檔頭 docblock**（`:242-254`）——移除「不支援變體層編輯」那條範圍限制

**驗收條件**

```bash
npx tsc --noEmit                     # = 127（不增）
npx eslint "js/src/**/*.{ts,tsx}"    # 0 errors（warnings ≤ 135 + 新增數，需逐條說明）
pnpm build                            # 成功
```

**手動驗收清單（本專案無前端測試框架，此為主要閘門）：**

1. variable 商品顯示 `+`，simple / grouped / external **不顯示**
2. `+` 位於商品圖片**左側**，各列名稱垂直對齊
3. 展開 → 變體列出；收合 → 再展開**不重新查詢**（Network 面板確認）
4. 設變體原價 / 特價 / 灌水 → 儲存 → **重整頁面** → 值仍在
5. 留空 → 儲存 → 讀回為空（不是 0）；填 0 → 讀回為 0（不是空）
6. 變體特價 > 原價 → 送出被擋 + 自動切到「賣場商品」Tab + notification 指出商品與變體
7. **拖曳排序仍正常**（含展開狀態下拖曳）
8. 換頁 / 換每頁筆數 → 展開狀態清空，無殘留
9. 移除已展開的商品 → 無 console error
10. 100 筆商品 + 多個展開 → 價格欄輸入無明顯延遲（R-5）
11. 變體 0 筆 / 查詢失敗 / 孤兒 vid 三種降級畫面正確，且**都不阻擋儲存**

---

### 第六階段：`inflated_count` 前台消費點（商品層）

**風險：低｜複雜度：低｜依賴：第一階段**

1. **`templates/profit-shop-front.php`**：在價格區塊下方顯示銷售數
   - `$sold = (int) $product->get_total_sales() + $override_item->inflated_count->value();`
   - `if ($sold > 0)` 才顯示「已售 {N} 件」，全 `esc_html`
   - 原因：**讓商品層 `inflated_count` 從「存得進去、沒人用」變成真的有作用**，直接消除 R-2 的一半
   - 風險：低（純顯示，不影響定價 / 加購 / 簽章）

**驗收條件**：`composer lint` 全綠；手動開 `/profit-shop/{slug}/` 確認數字 = 真實銷量 + 灌水值。

---

### 第六-B 階段（**強烈建議，可獨立決策**）：單品頁變體價格 filter — 解決 R-1

**風險：高｜複雜度：中｜依賴：第五階段**

1. **新增 `Infrastructure/WooCommerce/SingleProductPriceHook.php`**
   - 從 `$_GET['profit_shop_id']` 取賣場（**沿用 `AddToCartHook` 的三道閘門**：
     shop 存在 / `status === 'publish'` / `product_id ∈ shop->items()`），掛：
     - `woocommerce_product_get_price` / `_get_regular_price` / `_get_sale_price`
     - `woocommerce_product_variation_get_price` / `_get_regular_price` / `_get_sale_price`
     - `woocommerce_variation_prices` + `woocommerce_get_variation_prices_hash`
       （⚠️ **必須改 hash，否則 WC 變體價格 transient 快取會回舊價**）
   - 在 `Loader.php` 註冊
2. 測試：新增 `tests/Integration/.../SingleProductPriceHookTest.php`

**驗收條件**：`composer lint`；IT 綠；手動走完整動線確認
「賣場卡片 → 單品頁 → 購物車」三處價格一致。

> **若決定不做**：第五階段步驟 6 的 `<Alert>` 揭露成為**必要項**，且必須登記 TD-17。

---

### 第七階段：文件與技術債登記

1. `.claude/rules/profit-shop.rule.md` §7 — 新增「variations 契約含 inflated_count」小節、
   更新「§12.9 變體不可選」的範圍說明（Modal 仍不可選變體，但編輯頁可展開設定）
2. `.claude/rules/react.rule.md` §6.5 — 新增「`ItemsEditor` 變體展開」小節：
   `showExpandColumn: false` + 自刻圖示、rc-table 展開列不帶 `data-row-key`（dnd-kit 相容）、
   `expandedRowRender` 天然 lazy 且收合不 unmount
3. `.claude/CLAUDE.md` — Phase 9 章節 + 驗證數據表
4. `specs/tech-debt.md` — 新增：
   - **TD-15**：變體層 `inflated_count` 零前台顯示點
   - **TD-16**：wc-rest 變體查詢 100 筆硬上限
   - **TD-17**（僅在第六-B 不做時登記）：變體覆寫價在單品頁不生效，顧客會遇到價格跳動

---

## 九、測試策略總表

| 層級 | 檔案 | 內容 |
|------|------|------|
| Unit | `tests/Unit/Domain/Entity/OverrideItemTest.php` | `to_array` 新形狀、`VariationOverride` 存取、委派後 `get_variation_override` 仍回 `?PriceOverride` |
| Unit | `tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php` | 缺 `inflated_count` → 0、負數 → 0、變體價格違規拋 `InvalidPriceOverride`；**既有 BUG-A 回歸鎖必須維持綠燈** |
| Unit | 既有 `PriceCalculatorTest` / `ProfitShopTest` / `DuplicateShopTest` | **不改，全綠即為委派法成功的證明** |
| IT | `tests/Integration/.../CptProfitShopRepositoryTest.php` | **舊格式 JSON 反序列化 → `inflated_count` = 0**（R-7 核心） |
| IT | `tests/Integration/.../ProfitShopItemsRoundTripTest.php` | 變體 round-trip、變體 sale>regular → 400、variation 不屬於 product → 400 |
| IT | 既有 `CartPriceOverrideHookTest` | **不改，全綠即證明變體覆寫仍正確套價** |
| e2e | `tests/e2e/06-profit-shop/profit-shop-items.spec.ts` | 新增 API-level：PUT 帶變體 `inflated_count` → GET 還原；**既有 4 個 case 必須全綠** |
| 手動 | 第五階段 11 條清單 | 前端無測試框架，UI 行為以型別 + lint + 手動清單把關 |

**Baseline（新增測試前必須先跑一次記錄）**

- Unit：340 tests / 677 assertions / **1 failure（TD-2）**
- Integration：171 tests / 742 assertions / **21 failures（TD-1）**
- `npx tsc --noEmit`：**127**
- `npx eslint "js/src/**/*.{ts,tsx}"`：**0 errors / 135 warnings**
- `composer lint`：**154/154**

任何新增的 failure 都是本次引入的。

---

## 十、範圍邊界（不做什麼）

1. **不開放在 `ProductPickerModal` 勾選變體** —— 變體 checkbox 維持 disabled（Phase 7 Q3 範圍邊界）。
   變體覆寫只在「已加入賣場的父商品」展開列設定
2. **不做變體層 `inflated_count` 的前台顯示** —— 賣場前台無此顯示位置（見 R-2），登記 TD-15
3. **不開放 `signup_fee` UI**（商品層與變體層皆然）—— 契約保留、原樣攜帶，維持現況
4. **不動 Domain Service / Cart hook 的定價邏輯** ——
   `PriceCalculator` 六層 fallback、`CartPriceOverrideHook` 三閘門 + HMAC 簽章一律不改
5. **不做後端數量上限驗證** —— 沿用 Phase 7 的既有取捨（100 / 500 只在前端擋）；
   變體數量同樣不設後端上限
6. **不把 `useShopItemProducts` 從 `wc-rest` 換成 Powerhouse `default` provider** —— 理由見 D1
7. **不修 TD-6**（`api.yml` 的 `variations` 空值是 `[]` 不是 `{}`）—— 既有問題，本次只確保不惡化
8. **不修 TD-7 / TD-11**（Powerhouse 全域 403 對話框、Refine 重複錯誤通知）——
   成因在 Powerhouse / Refine 設定，不在本 repo
9. **不做資料 migration** —— 舊 `variations[vid]` 缺 `inflated_count` 由 `?? 0` 接住（比照 Phase 7 / 8）
10. **第六-B 階段（單品頁價格 filter）為獨立決策項** —— 不做的話必須在 UI 揭露 + 登記 TD-17

---

## 十一、成功標準

- [ ] variable / variable-subscription 商品在**商品圖片左側**顯示可點的 `+`，其他類型不顯示
- [ ] 展開後可逐一設定每個變體的原價覆寫、特價覆寫、灌水購買人數
- [ ] 儲存 → 重整 → 值完整還原；「留空（`null`）」與「填 0（`"0"`）」語意不互相吃掉
- [ ] 變體特價 > 原價在前端被擋下，並自動切到出錯的 Tab + 指出是哪個商品的哪個變體
- [ ] 後端 `PriceOverride` / `ItemValidator` 驗證不因前端已擋而放寬（直打 API 一樣被 400 / 404 擋下）
- [ ] 舊資料（`variations[vid]` 無 `inflated_count`）讀回為 0，**無需 migration**，且有測試鎖住
- [ ] 拖曳排序、分頁、批次加入商品、四分支降級顯示**零 regression**
- [ ] BUG-A 三層鎖（Unit 11 + IT 10 + e2e 4）全數維持綠燈
- [ ] `composer lint` 全綠；`npx tsc --noEmit` ≤ 127；`npx eslint` 0 errors；`pnpm build` 成功
- [ ] Unit / Integration 失敗數維持在 baseline（1 / 21），無新增失敗
- [ ] 商品層 `inflated_count` 在賣場前台真的顯示（第六階段），不再是「存得進去、沒人用」
- [ ] R-1 已解決（第六-B）**或**已在 UI 據實揭露 + 登記 TD-17

---

## 十二、預估複雜度：**中偏高**

PHP 契約擴充路徑清楚（委派法把破壞面壓到 3 個測試斷言）；
前端 expandable 與 dnd-kit 的相容性已從 rc-table 原始碼**逐行驗證確認無需改動**，
這通常是這類任務最大的未知數。真正的複雜度集中在第五階段的狀態管理
（展開 / 分頁 / 移除三者互動）與第六-B 的 WC 變體價格快取 hash。

---

## 十三、需要 team lead 決策的三個問題

1. **第六-B 階段（單品頁價格 filter）做不做？**
   這是唯一的 CRITICAL GAP。不做 = 顧客會看到「單品頁一個價、購物車另一個價」，
   而這個落差正是因為本次開放變體覆寫 UI 才會被觸發。
2. **第六階段（商品層 inflated_count 接前台）納不納入？**
   建議納入——成本約 5 行，能讓這個欄位從反模式變成真功能，
   也讓「變體層暫時沒有顯示位置」這件事的說服力更強。
3. **變體超過 100 個的商品**目前只顯示前 100（WC REST 硬上限）。
   若已知有這種商品，需要改走「分頁載入變體」，會增加第三／四階段的複雜度。
