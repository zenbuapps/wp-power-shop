# 結算頁 SPA（§3.5）+ 訂單頁分潤資訊面板（§3.9）— 前端交付紀錄

> 日期：2026-08-06
> 分支：`refactor/one-page`（後端 `866fbbd` 之上）
> 範圍：**純前端**。`inc/` 下的 PHP 一行未動（`git diff --stat -- inc/` 對本次改動為空）。

---

## 1. 檔案清單

### 新增（10 檔）

| 路徑 | 內容 |
|---|---|
| `js/src/pages/admin/ProfitSettlement/types.ts` | 狀態常數 / 狀態顯示對映 / 佔位文字常數 / 7 個回應型別 / 3 個批次操作的中繼資料 |
| `js/src/pages/admin/ProfitSettlement/utils.ts` | `toSettlementQuery` / `downloadBase64File` / `partitionByAllowedStatus` / `formatUnixSeconds` |
| `js/src/pages/admin/ProfitSettlement/hooks.ts` | `useSettlementList` / `useSettlementSummary` / `useSettlementOrderIds` / `useSettlementBatchAction` / `useSettlementExport` |
| `js/src/pages/admin/ProfitSettlement/index.tsx` | re-export |
| `js/src/pages/admin/ProfitSettlement/List/index.tsx` | 結算主頁（篩選卡片 + 摘要卡 + 列表卡片 + 批次操作 + CSV 匯出） |
| `js/src/pages/admin/ProfitSettlement/List/Filter.tsx` | 四條件篩選表單（夥伴 / 賣場 / 狀態 / 日期區間） |
| `js/src/pages/admin/ProfitSettlement/List/SummaryCards.tsx` | 四張摘要卡（待撥款 / 已撥款 / 已退款 / 已取消） |
| `js/src/pages/admin/ProfitSettlement/components/BatchActionModal.tsx` | 金流操作二次確認 Modal（§12.4 模式） |
| `js/src/pages/admin/ProfitSettlement/components/ShopSelector.tsx` | 賣場下拉（結算頁 + 訂單列表共用） |
| `js/src/pages/admin/Orders/Edit/ProfitPanel/index.tsx` | 訂單頁分潤資訊面板（兩層元件：守門 + 本體） |
| `js/src/pages/admin/Orders/List/Filter.tsx` | 訂單列表篩選表單（目前只有「分潤賣場」） |

### 修改（5 檔）

| 路徑 | 改動 |
|---|---|
| `js/src/pages/admin/Orders/List/Table/index.tsx` | 加篩選卡片 + 依 `order-ids` 動態組 `include` permanent filter + 截斷 / 空結果兩則 Alert；順手移除既有的死碼（被註解掉的篩選區塊與其殘留 import） |
| `js/src/pages/admin/Orders/Edit/index.tsx` | 在 `<Form>` **之外**掛上 `<ProfitPanel />` |
| `js/src/resources/index.tsx` | 新增 `profit-settlement` resource（掛 `marketing` parent，`WalletOutlined`） |
| `js/src/App1.tsx` | 新增 `/profit-settlements` route + import |
| `js/src/pages/admin/index.tsx` | 匯出 `ProfitSettlement` |

---

## 2. 閘門實測數據

| 項目 | Baseline | 完成後 | 判定 |
|---|---|---|---|
| `npx tsc --noEmit` | **127** | **118** | ✅ 不增反減 9（見下方說明） |
| 新增 / 修改檔案的 tsc 錯誤 | — | **0** | ✅ |
| `pnpm build` | 成功 | **✓ 35.52s** | ✅ |
| `npx eslint` | 無法執行 | 無法執行 | ⚠ 環境問題 TD-31（`unrs-resolver` native binding 載入失敗），非本次造成 |
| 新增 npm 套件 | — | **0** | ✅ |
| `git diff -- inc/`（本次） | — | **空** | ✅ 零 PHP 改動 |

**tsc 為何是 127 → 118（減 9）**：`Orders/List/Table/index.tsx` 原本留著一整塊被註解掉的篩選器，以及只為它存在的 9 個未使用 import / 變數（`PlusOutlined` / `FormInstance` / `Button` / `FilterTags` / `DOCS_POST_TYPE` / `searchFormProps` / `valueLabelMapper` / `isCreating` / `createOrder`），每個都是一條 `TS6133`。本次把那塊註解換成真的篩選卡片，那 9 條隨之消失。

已用 `git stash` 逐檔比對確認：該檔唯一剩下的錯誤
`TS2322: Type 'TableProps<T_1>' is not assignable to type 'TableRowSelection<TOrderBaseRecord>'`
**是 pre-existing** 的（baseline 在同檔 110 行、現在在 150 行，訊息逐字相同），來自 `useRowSelection` 的既有型別問題，與本次改動無關、也未加劇。

---

## 3. 關鍵決策與理由

### D-1 🔴 訂單頁面板讀「訂單品項的 meta」，不打結算 API

**這是本次最重要的決策，因為它偏離了「所有資料都從結算端點來」的直覺做法。**

兩個理由，第二個是硬性的：

1. **語意上就該如此**。`view-order-profit-panel.feature` 的
   「Rule: 後置（凍結）- 面板顯示凍結值而非當下重新計算的值」明講這份資訊已經凍結在訂單品項上，
   面板只是把它讀出來。直接讀 `record.items[].meta_data` 天然滿足「賣場改比例 / 改名 / 被刪除後歷史訂單數字不變」。
2. **`GET /profit-settlements` 沒有 `order_id` 過濾條件**。`SettlementFilterInput` 只有
   partner / shop / status / date 四種（已逐行確認）。要用它取單一訂單的明細，只能整批撈回來再前端過濾，
   而列表有 `per_page` 上限 100 —— 訂單一多就會靜默漏資料。

**已驗證 WC REST 真的送得出這些 meta**：`WC_REST_Orders_V2_Controller::get_order_item_data()`
的 `$data = $item->get_data()` 走 `WC_Data::get_meta_data()`，它回傳**全部** meta（含底線前綴，
只有 `get_formatted_meta_data()` 才會濾掉底線）；唯一的過濾發生在
`order_item_display_meta === 'true'` 且只針對變體屬性。`TOrderBaseRecord.items[].meta_data`
本來就已經是 `{id, key, value}[]`，型別不需擴充。

**連帶紀律**：名稱因此得在前端解析，`DELETED_SHOP_LABEL` / `DELETED_PARTNER_LABEL` 兩個常數
放在 `ProfitSettlement/types.ts` 由兩處共用，且**必須與 PHP `WpAdminSettlementQuery` 的同名常數逐字相同**
（已對齊 `[已刪除賣場]` / `[已停用夥伴]`）。「已刪除」的判定也對齊後端
`resolve_shop_names()`：**查不到 或 `post_status === 'trash'`** 都算已刪除。

### D-2 面板拆成兩層元件，讓「一般訂單不發查詢」在結構上成立

第一版把 `useProfitShopList()` / `useProfitPartnerList()` 放在外層，early return 之前——
那兩個查詢**仍然會照發**，只是結果沒人用，而我寫的註解卻宣稱「一般訂單完全不發這兩個查詢」。
這正是本 repo 已吃虧三次的失效模式（TD-20 / TD-54 / TD-67：註解的宣稱與實際行為相反，
而 lint / tsc / 測試一個都攔不到）。改成 `ProfitPanelComponent`（只判斷有沒有分潤品項）
+ `ProfitPanelBody`（持有全部查詢）兩層，沒有分潤品項的訂單根本不 render 本體，
註解才與行為一致。

### D-3 結算頁走 server-side 篩選 + 分頁，不沿用 profit-* 的 client-side 路線

`admin-list-ui.rule.md` §3.2 路線 B（`useCustom` + `useMemo` 前端過濾）適用於「後端無分頁、總量小」
的 profit-shop / profit-partner。結算紀錄是「每張訂單每個分潤品項一筆」，成長速度完全不同，
而後端本來就提供了四種篩選 + 分頁 + `MAX_PER_PAGE = 100`。因此**只有版型**沿用路線 B 的兩張 Card，
資料流走 server-side。分頁狀態由頁面自己持有（`page` / `perPage`），`total` 取自後端。

### D-4 🔴 `skipped` / `audit_log_failed_partner_ids` 一律顯性呈現，且 `duration: 0`

後端回的是 `{updated_ids, updated_count, skipped, skipped_count, total_amount, audit_log_failed_partner_ids}`
（**不是** brief 裡寫的 `succeeded` / `failed`，也不是 feature 檔裡的那組命名——以 `SettlementBatchOutput` 為準）。

- `skipped`（`order_item_id => 原因`）以獨立的 warning notification 逐筆列出，並明講
  「請勿重複操作，先重新整理列表確認目前狀態」。理由就是 feature 檔頭寫的：第二個管理員若不知道
  自己那次沒生效，很可能真的再去銀行轉一次帳。
- `audit_log_failed_partner_ids` 是**部分成功**（狀態改了、稽核紀錄沒寫），因此獨立成第三則警告，
  並明講「狀態不需要重做」——併進失敗清單會誘發重撥。
- 兩則都 `duration: 0` 不自動消失。

### D-5 二次確認用 Modal 三個 false，但**不**要求輸入確認字串

`closable={false}` / `maskClosable={false}` / `keyboard={false}` 照 §12.4 全開。

刻意不加 `ImportModal` 那種 typed confirmation（`IMPORT`）：撥款是站長每個結算週期要做很多次的
例行動作，逐次打字會養成反射性輸入、反而稀釋確認的意義；ImportModal 的遷入則是一輩子一次且完全
無法回頭。這裡改以「明確列出將被改動的每一筆 + 金額合計 + 事前算出會被跳過幾筆」承載確認的重量。
**若 team lead 認為撥款仍需 typed confirmation，改動範圍只在 `BatchActionModal`，不影響其他檔案。**

### D-6 單列操作與批次走完全相同的確認流程與端點

刻意不做「單列直接送出、不確認」的捷徑：單筆一樣是真實金流，而且共用同一條路徑才不會出現
「批次會擋、單筆不擋」的漏洞。

### D-7 訂單列表的「查無訂單」必須送 `include=[0]`，不能送空或不送

`include` 空陣列 / 不送 = **不過濾** = 站長選了一個沒有訂單的賣場卻看到**全部訂單**，
而畫面上的篩選還顯示著那個賣場名稱。改送 `[0]`（WP `post__in` 不可能命中）。
清單載入中同樣先送 `[0]`，避免「先閃一頁未過濾的訂單、再突然縮成幾筆」——那一瞬間的畫面是錯的。

### D-8 金額全程當字串，唯一的前端加總用定點整數

所有金額欄位在型別上就是 `string`，顯示時原樣輸出（`Statistic` 收字串會原樣渲染，
刻意不設 `precision` / `formatter` 以免被轉成 float）。

唯一需要前端加總的地方是訂單面板的「分潤合計」（後端沒有提供這個欄位）。那裡用**字串定點加法**
（把兩位小數當「分」相加，最後插回小數點），不是 `Number()` 累加——否則會出現
`48.00 + 180.00 = 227.99999999999997`。**已知限制**：只支援兩位小數（對齊 WC 預設與後端
`format_money()`），站台若改成 3 位以上這個合計會少算，屆時應改由後端提供合計欄位。

### D-9 `downloadBase64File` 不可把 `atob()` 結果直接丟進 Blob

`atob` 回傳的是「一字元一位元組」的 binary string，直接 `new Blob([str])` 會被瀏覽器**再以
UTF-8 編碼一次**：原本的 UTF-8 位元組被當成 Latin-1 各自膨脹成 2 bytes，中文全部亂碼、BOM 也壞掉。
必須逐字元 `charCodeAt` 還原成 `Uint8Array`。**BOM 由後端加在內容裡，前端不再加**（已確認
`ExportSettlementsUseCase` 產出的 CSV 已含 BOM）。

### D-10 `ShopSelector` 放在 `ProfitSettlement/components/`

它的兩個消費端（結算頁篩選、訂單列表過濾）都是結算流程；賣場 CRUD 自己不需要賣場選擇器。
**已刪除的賣場仍列在選項裡**（標「已刪除」Tag）——結算紀錄凍結的是 shop_id，賣場被刪掉後那些錢
還是要付，把它從篩選器移除等於讓站長再也篩不出那批待撥款紀錄。

---

## 4. 與後端契約的三處落差（以實際 PHP 為準，已據此實作）

動工前逐檔讀了 `Application/DTO/Settlement*.php` 與 `WpAdminSettlementQuery.php`，
發現交辦說明與實際契約有三處不同，一律以程式碼為準：

| 項目 | 交辦說明 | 實際 | 影響 |
|---|---|---|---|
| 批次回應 | `skipped`（正確）；feature 檔寫 `succeeded` / `failed` | `updated_ids` / `updated_count` / `skipped` / `skipped_count` / `total_amount` / `audit_log_failed_partner_ids` | 多出 `total_amount` 與稽核失敗清單，兩者都已呈現 |
| 摘要欄位 | 6 個（pending / paid / refunded × 金額+筆數） | **10 個**，另有 `cancelled_amount` / `cancelled_count` / `total_sales` / `total_count` | 摘要卡做成 **4 張**（多一張「已取消」）；`total_sales` / `total_count` 目前未用 |
| 唯讀端點權限 | 「唯讀是 `manage_woocommerce`」 | **7 個端點全部 `admin_only`（`manage_options`）** | 純後端行為，前端無需處理；但見下方「注意事項」第 3 點 |

另：`date_start` / `date_end` 是 **unix 秒**（不是日期字串），非數字會被靜默視為「未指定」而不過濾——
轉換集中在 `toSettlementQuery()`，結束日一律取 `endOf('day')`。

---

## 5. 與四份 `.feature` 規格的對照

### `query-settlements.feature`

| Rule | 狀態 |
|---|---|
| 安全（權限）| ✅ 後端負責；前端無繞道路徑 |
| 後置（格式）`{code,message,data}` / `items,total,page,per_page` / 每列 8 欄 | ✅ 全數消費 |
| 前置（篩選）夥伴 / 賣場 / 狀態 / 日期 + 多條件並行 | ✅ 四個條件皆已實作並可同時生效 |
| 前置（分頁）上限收斂 | ✅ 由後端 clamp；前端 `per_page` 走 antd `showSizeChanger` |
| 後置（摘要）三組數字 + 遵循相同篩選 + 無資料回零 | ✅ 摘要與列表共用同一組 query（僅去掉分頁） |
| 後置（已刪）佔位文字 + 金額照顯示 + `rate=0` 標記 | ✅ 名稱由後端給；`needs_manual_rate` 渲染紅色「需人工設定比例」Tag + Tooltip 說明 |
| 安全（型別）/ 資料（訂單）/ 相容（HPOS）| ✅ 純後端 |
| 已知落差：不提供跨夥伴合併撥款檢視 | ✅ 未實作（刻意） |

### `mark-settlement-paid.feature`

| Rule | 狀態 |
|---|---|
| 安全（權限 / 操作者）| ✅ 純後端（`settled_by` 取自登入身分，前端不送） |
| 後置（撥款 / 取消 / 沖回）三種狀態轉換 | ✅ 三顆批次按鈕 + 對應的單列按鈕；來源狀態鏡像在 `SETTLEMENT_BATCH_ACTION_META.allowedFrom` |
| 後置（逐筆）成功 / 失敗清單、一筆失敗不影響其他、空清單不報錯 | ✅ 已呈現；空選取時按鈕停用並用 Tooltip 說明原因 |
| 安全（併發）| ✅ 後端 CAS；前端**顯性呈現 `skipped`** 並明講「請勿重複操作」 |
| 後置（稽核）| ✅ `audit_log_failed_partner_ids` 獨立警告 |
| 後置（格式）| ✅ |

⚠️ feature 寫的欄位名是 `succeeded` / `failed`，實際 DTO 是 `updated_ids` / `skipped`。
**建議 team lead 順手把 feature 檔的那兩個欄位名對齊 DTO**，否則下一個人會照 feature 寫出讀不到值的程式碼。

### `export-settlements-csv.feature`

| Rule | 狀態 |
|---|---|
| 安全（權限 / 公式注入）| ✅ 純後端 |
| 後置（欄位順序）| ✅ 純後端 |
| 後置（範圍）等同當下篩選 | ✅ 匯出送的是**同一組 query**（去掉分頁）→ 所見即所得；按鈕 Tooltip 明講「不只當前頁」 |
| 後置（格式）`filename` / `content_base64` | ✅ |
| 篩選結果為空仍產出只有標題列的檔案 | ✅ `row_count === 0` 時照常下載，並提示「已匯出只含標題列的檔案」 |
| 內容為含 BOM 的 UTF-8 | ✅ 位元組原封不動（見 D-9）；前端不重複加 BOM |

### `view-order-profit-panel.feature`

| Rule | 狀態 |
|---|---|
| 後置（呈現）逐一列出 + 每列六欄 + 無歸屬品項不出現 + 合計 | ✅ |
| 後置（凍結）| ✅ 讀凍結的 item meta（見 D-1） |
| 後置（已刪）佔位文字 + 金額照顯示 + **已刪除賣場不提供連結** | ✅ 三條皆已實作 |
| 後置（狀態）四種狀態中文 + 與結算頁一致 + 已撥款顯示撥款時間 | ✅ 共用 `SETTLEMENT_STATUS_META`，兩頁不可能分歧 |
| 後置（無資料）完全不顯示面板、不留空區塊 | ✅ 外層守門 return null |
| 前端（過濾）訂單列表依賣場過濾 / 先取 order-ids 再當 include / 無訂單為空不報錯 / 清除回復 | ✅ 全數實作 |
| 已知落差：200 筆上限 + **據實告知** | ✅ `truncated` 為 true 時顯示 warning Alert，寫出總數、上限與替代做法 |
| 安全（權限）order-ids 端點 | ✅ 純後端 |

**未實作 / 不適用**：`query-settlements.feature` 的「已知落差」與各 feature 的 `安全（權限）`
Rule 都是後端行為，前端沒有對應實作點。

---

## 6. 尚未解決 / 需後續注意

1. **未做真實站台端到端驗證**。目前只過了 `tsc` 與 `pnpm build` 兩道靜態閘門。
   結算鏈路的後端 hook（退款回沖 / 訂閱續訂）由其他 agent 併行進行中，站上也還沒有真實的分潤訂單資料，
   因此**沒有**實際跑過「加購 → 結帳 → 出現在結算頁 → 撥款 → 訂單頁面板顯示已撥款」這條完整動線。
   ⚠️ 特別是這三項只有程式碼層面的推理、沒有實測：
   - CSV 下載後用 Excel 開啟的中文是否正常（D-9 的位元組還原）
   - `include` filter 在 `wc-rest` 的實際序列化結果（雖與已驗證的 `useShopItemProducts` 同款寫法）
   - 批次操作的 `skipped` 呈現（需要製造併發或狀態不符的資料）

2. **`npx eslint` 無法執行**（TD-31，`unrs-resolver` native binding 載入失敗，pre-existing）。
   新檔案沒有經過 lint 檢查，只有 tsc 與 build 把關。

3. **`profit-settlement` 選單對 `shop_manager` 會是壞的入口**。7 個端點全要求 `manage_options`，
   但 resource 註冊沒有能力判斷，只有 `manage_options` 以下的使用者仍看得到選單項目，點進去會拿到 403。
   而 `antd-toolkit` 的 axios interceptor 對**任何** 403 都會跳一個
   「網站 Cookie 已經過期，請重新整理頁面」的 `window.confirm`（既有行為，TD-7 同類），
   訊息與真實原因完全不符。**建議**：resource 加 capability 條件、或後端把唯讀端點降回
   `manage_woocommerce`（後者與 spec §6.1 一致，但與兩份 feature 檔的斷言衝突，需 team lead 裁決）。

4. **訂單列表每頁實際是 10 筆而非 20 筆**（pre-existing）。`useTable` 只設了
   `pagination.pageSize: 20`，而 6 個 dataProvider 會把它序列化成 Powerhouse 風格的
   `posts_per_page`，WC REST 完全不認 → 靜默退回預設 10（`react.rule.md` §3 記載的同款陷阱）。
   **本次刻意不修**：那是可見的行為變更、與交辦範圍無關。修法是加一條
   `{ field: 'per_page', operator: 'eq', value: 20 }` filter。

5. **`SettlementAdminSummaryOutput` 的 `total_sales` / `total_count` 目前無消費者**。
   摘要卡只用了四種狀態的金額與筆數。若要顯示「總成交金額」再加第五張卡即可，欄位已在型別內。

6. **訂單面板的「分潤合計」只支援兩位小數**（見 D-8）。若日後站台把 `wc_get_price_decimals()`
   調成 3 位以上，這個合計會少算——正解是請後端在某個端點提供合計欄位，而不是把前端的定點加法改複雜。

7. **`mark-settlement-paid.feature` 的 `succeeded` / `failed` 欄位名與 DTO 不符**（見上）。
   建議一併修正 feature 檔。

8. **`Orders/List/hooks/useValueLabelMapper.tsx` 目前成為孤兒**。它原本只服務被註解掉的
   FilterTags 區塊，本次改寫後不再被 import（它在改寫前就已經是未使用狀態，只是 import 還留著）。
   新的訂單篩選器只有一個賣場欄位、暫不需要 FilterTags；日後若把通用訂單篩選補回來會再用到，
   因此**保留檔案不刪**。
