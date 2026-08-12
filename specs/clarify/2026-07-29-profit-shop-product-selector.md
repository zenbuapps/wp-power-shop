# Clarify Session 2026-07-29

## Idea

> 重構 `/wp-admin/admin.php?page=power-shop#/profit-shop/edit/[ID]` 這個頁面的商品選擇器
>
> 目前的商品選擇器只是一個簡單的 selector
>
> 但如果商店擁有10萬個商品
>
> 用這個簡單的 selector，就會很難以篩選
>
> ## 修正方案
>
> 做成一個 button 點開可以彈出 SimpleModal (from antd-toolkit)
> SimpleModal 裡面有完整個商品篩選器 & 商品分頁列表，即 SimpleModal 裡面會顯示整個 `/wp-admin/admin.php?page=power-shop#/products` 頁面組件
>
> 勾選商品，添加後，會出現在 `/wp-admin/admin.php?page=power-shop#/profit-shop/edit/[ID]` 這個頁面底下
>
> 這樣如果商店擁有10萬個商品
> 就能有豐富的 filter 篩選商品

## 讀碼現況（Step 2 Structural Read）

| 項目 | 位置 | 事實 |
|------|------|------|
| 目前的「簡單 selector」 | `js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx:214-224` | `useSelect`（`wc-rest` / `products`，`pageSize: 20`）+ antd `Select` 單選；`handleAdd` 推入下方 Table |
| 掛載點 | `js/src/pages/admin/ProfitShop/components/ProfitShopForm.tsx:169-177` | `<Item name="items"><ItemsEditor /></Item>`，僅 `mode === 'edit'` 啟用 |
| 表單填值 | `js/src/pages/admin/ProfitShop/Edit/index.tsx:40-61` | `filledIdRef` 守門，把 `record.items` map 進 form |
| 送出 | `Edit/index.tsx:70-86` | items 只取 `product_id / variation_id / price_override / inflated_count` |
| 商品列表基準 | `js/src/components/product/ProductTable/index.tsx` | `useTable` + `wc-rest`；兩張 `antd-toolkit` `Card`；Jotai `selectedProductsAtom`；`ActionArea` + `SelectedItem`；`<CreateButton>` |
| Modal 元件 | `antd-toolkit` `SimpleModal` / `useSimpleModal` | props：`width / title / children / footer / className / onCancel / zIndex / opacity / pointerEvents / destroyOnHidden`；**無 `open` prop**，開關由 `useSimpleModal` 的 `show()` / `close()` 控制 |
| Modal 既有範例 | `js/src/components/term/TaxonomyModal/` | `useXxxModal` hook 產 `modalProps`（含自訂 `footer` 確認鈕）→ `<SimpleModal {...modalProps}>` |

## 讀碼發現的既有缺陷（BUG-A）

前後端 `items` 契約不一致，`price_override` 與 `variation_id` 存檔時被靜默丟棄。

| 來源 | items 單筆形狀 |
|------|---------------|
| 後端 Domain `OverrideItem::to_array()`（`inc/classes/Domains/ProfitShop/Domain/Entity/OverrideItem.php:94-106`） | `{product_id, inflated_count, override:{regular_price, sale_price, signup_fee}, variations:{vid:{override:{…}}}}` |
| `specs/api/api.yml:879-895` + 前端 `js/src/pages/admin/ProfitShop/types.ts:35-42` | `{product_id, variation_id, price_override:int\|null, inflated_count}` |

`ProfitShopHydrator::build_override_item()`（`Application/UseCase/Shop/Support/ProfitShopHydrator.php:107-136`）只讀
`$row['override']` 與 `$row['variations']`；前端送的 `price_override` / `variation_id` 從未寫入。
`inflated_count` 與 `product_id` 正常。

副作用：`OverrideItemOutput` 也不含 `name` / `sku`，`ItemsEditor` 的商品欄在重新整理後只能顯示 `#{id}`。

## 讀碼發現的次要問題（OBS）

- `ProductTable` 的 `cardProps?: CardProps & { showCard?: boolean }`（`index.tsx:34-38`）已宣告但**在元件內完全未使用**，兩張 `<Card>` 都沒 spread。

## Q&A（Round 1 已定案）

| # | 問題 | 決議 | 理由 |
|---|------|------|------|
| Q1 | 前後端 `items` 契約錯配是否納入本次範圍 | **B**：一併修正，以 Domain 巢狀為準 | 不修＝交付一個「看起來能用但存不進價格」的 UI，屬品質底線而非範圍取捨。Domain 巢狀是 `PriceCalculator` / `CartPriceOverrideHook` 實際在吃的形狀；扁平 `price_override:int` 連 `sale_price` 都表達不了，選 C 會用一次改動永久閹割 Domain 能力 |
| Q2 | 商品 name/sku/縮圖 重新載入後的來源 | **B**：前端 `wc-rest` `products?include=` 批次補齊 | name/sku/image 是商品「當下狀態」而非賣場設定；寫進 `OverrideItemOutput` 會造成商品改名後賣場顯示舊名的 stale 問題，且違反 Domain 純度（Application 層為 UI 便利去 enrich WC 資料）。B 零後端改動並可與 Modal 共用 react-query 快取 |
| Q3 | Modal 是否支援勾選變體 | **A**：只選商品層，variation 維持禁選 | 使用者原始需求全篇未提變體，最小驚訝原則。Q1 選 B 已保留 `variations` 結構，後續要開變體覆寫不需回頭改契約 |
| Q4 | 已加入商品在 Modal 內的呈現 | **B**：「已加入」Tag + checkbox disabled | 貼合原始描述「勾選商品，添加後，會出現在頁面底下」——Modal 是加入器不是同步器。A 有誤刪風險（Modal 內誤取消勾選會連帶清掉下方已調好的 `regular_price` / `sale_price` / `inflated_count`）；C 則讓使用者無法確認商品是否已在賣場內 |

### Q1 的範圍邊界（orchestrator 明確界定）

本次**只做**「讓前端送出的巢狀 items 能被 `ProfitShopHydrator` 正確寫入 / 讀出」。

不得觸碰：
- `Domain/Service/PriceCalculator`（fallback chain）
- `Infrastructure/WooCommerce/CartPriceOverrideHook`（HMAC 簽章邏輯）
- `Domain/Entity/OverrideItem`、`Domain/ValueObject/PriceOverride`（Domain Entity / VO）

若實作時發現修正**必須**動到 Domain 層 → **先回報再繼續**。

## Round 2 追加要求（orchestrator 指定，已納入規格）

| # | 要求 | 落點 |
|---|------|------|
| R1 | 「不複用 `selectedProductsAtom`」在 spec 寫死，避免後續實作 agent 為了「重用」又接回去 | `ui/profit-shop-edit.md` §元件重用策略（硬性禁止表）、`select-shop-products.feature` Rule「Modal 的勾選狀態不得與商品列表頁的批次選取互相污染」 |
| R2 | 註明 Pitfall #10 的約束對象是 profit-\* resource，products 本來就走 `wc-rest`，避免後續 agent 誤讀而繞路 | `ui/profit-shop-edit.md` §dataProvider 分工 |
| R3 | `ProductTable` 重用策略給明確結論 + Modal 情境必須關閉的元素清單，判準：`#/products` 不得產生任何 regression | `ui/profit-shop-edit.md` §元件重用策略 |
| R4 | 重複加入的處理（批次加入時 dedupe 是否仍成立） | `select-shop-products.feature` Rule「批次加入必須去重」 |
| R5 | 批次加入數量上限結論 | `select-shop-products.feature` Rule「批次加入數量上限」+ `ui/profit-shop-edit.md` §數量上限 |

### R5 的瓶頸實測依據

| 層 | 瓶頸 | 實際位置 |
|----|------|---------|
| 前端（最先撞牆） | `ItemsEditor.handleUpdate` 每次按鍵都 `onChange?.(items.map(...))` 重建整個陣列 → 整張 Table 重繪。500 列 × 3 個受控 `InputNumber` = 1500 個受控元件每次按鍵重繪 | `ItemsEditor.tsx:106-112` |
| 後端存檔 | `ItemValidator::validate()` 逐筆呼叫 `WpProductLookup::exists()` → 每筆一次 `wc_get_product()`，N 筆 = N 次商品物件載入 | `Application/Service/ItemValidator.php:53-62` + `Infrastructure/WordPress/WpProductLookup.php:28-37` |
| 補齊查詢 | WC REST `per_page` 上限 100，且 `include=` 走 GET query string（IDs 過多會撞 URL 長度上限） | `wc-rest` dataProvider |
| 賣場前台 | `templates/profit-shop-front.php` 一次渲染全部 items，無分頁 | Phase 4-C1 |

結論：**設上限**。單次批次加入硬上限 **100**（對齊 WC REST `per_page` 上限，補齊查詢一次打得完）；
賣場商品總數 **500** 為軟上限（警示不阻擋，超過需先改 `ItemsEditor` 為虛擬列表 / 分頁，屬後續迭代）。

## 已自行推導（不佔用提問回合）

| # | 決策 | 依據 |
|---|------|------|
| D1 | Modal 用 `SimpleModal` + `useSimpleModal`，確認鈕放 `footer` | 使用者指定 + `TaxonomyModal` 既有範例；`SimpleModal` 無 `open` prop |
| D2 | Modal 內商品列表走 `wc-rest` + `useTable`（路線 A） | Pitfall #10 只禁 profit-\* resource 用 `useTable`；products 本就走 `wc-rest`。賣場本體仍 `useCustom` + `power-shop` |
| D3 | 不得複用 `selectedProductsAtom` | module-level 全域 atom，且 `ProductTable` mount 時 `setSelectedProducts([])`（`index.tsx:128-131`）會與 `#/products` 互相污染 |
| D4 | Modal 內不得出現 `<CreateButton>`（新增商品） | 選商品情境不該能建商品 |
| D5 | Modal 內不用 `ActionArea`（fixed 底部條），改用 Modal `footer` | `ActionArea` 為 fixed 定位，在 Modal 內層級錯亂 |
| D6 | 版型仍遵循 `.claude/rules/admin-list-ui.rule.md`「篩選卡片 + 實體列表」 | 專案硬性規則 |
