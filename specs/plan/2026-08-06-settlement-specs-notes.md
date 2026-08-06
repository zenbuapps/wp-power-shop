# 分潤結算鏈路 — 規格補齊紀錄

> 日期：2026-08-06
> 分支：`refactor/one-page`
> 上游藍圖：[`2026-08-06-profit-settlement-chain-plan.md`](2026-08-06-profit-settlement-chain-plan.md) §9
> 範圍：**只碰 `specs/`**，未動 `inc/` 與 `js/`（實作由平行進行的 agent 負責）

---

## 1. 新增與異動的檔案

### 新增（7 個 `.feature`，全部 `@ignore`，放 `specs/features/profit-shop/`）

| 檔名 | tag | 行數 | 對應 Stage | 設計文件 |
|---|---|---|---|---|
| `attribute-order-line-item.feature` | `@command` | 263 | Stage 0（F-1/F-2/F-3）+ Stage 1 | §2.5、§5.2 |
| `reverse-settlement-on-refund.feature` | `@command` | 167 | Stage 2 | §5.3、§5.7、§7.5、§7.9 |
| `carry-profit-to-renewal-order.feature` | `@command` | 159 | Stage 3 | §5.4 |
| `query-settlements.feature` | `@query` | 234 | Stage 4（查詢側）+ Stage 5 | §3.5、§4.3、§5.7、§6.6 |
| `mark-settlement-paid.feature` | `@command` | 229 | Stage 4（命令側）+ Stage 5 | §4.3、§5.5、§7.5、§7.9 |
| `export-settlements-csv.feature` | `@query` | 183 | Stage 4（匯出） | §3.5、§4.3、§6.9 |
| `view-order-profit-panel.feature` | `@query` | 169 | Stage 6 | §3.9、§5.7 |

合計 1404 行。

### 異動

| 檔案 | 內容 |
|---|---|
| `specs/README.md` | profit-shop Feature 數 23 → 30；新增「分潤結算」小節（7 列）；統計 43 → 50（31 `@command` + 19 `@query`）；`plan/` 目錄列出四份計畫；**紅色「完全未實作」警語改寫**為「實作中 + 依 Stage 列出尚未完成的子項」 |
| `specs/features/profit-shop/query-partner-report.feature` | 檔頭註解的上游缺口說明從「從來沒有任何程式寫入」改為「實作中，見藍圖與 `attribute-order-line-item.feature`」；順帶補上「結算查詢必須過濾訂單型別，否則訂閱金額 ×2」這條連帶前提（該 Feature 的金額斷言直接受它影響） |

⚠️ `specs/api/api.yml` 的 7 個 endpoint schema **未補**——藍圖 §5 Stage 7 已把它指派給
`doc-updater`，且 endpoint 的實際請求 / 回應形狀由 Stage 4 的實作決定，此刻補會變成猜測。

---

## 2. 藍圖 §9 清單的落實情況

**檔名、tag、涵蓋 Rule 全部照 §9 執行，未做任何調整。** 三點補充說明：

1. **`profit-settlements/order-ids`（決策 D-6）寫在 `view-order-profit-panel.feature`**，
   不在 `query-settlements.feature`。依據是 §9 表格自己把「訂單列表賣場過濾與 200 筆上限」
   列在 `view-order-profit-panel` 那一列。`query-settlements.feature` 因此涵蓋 6 個 endpoint
   的其中 3 個（list / summary + 分頁與權限），另 3 個在 `mark-settlement-paid.feature`
   （mark-paid / mark-cancelled / reverse-paid）、1 個在 `export-settlements-csv.feature`。

2. **三個地基缺陷（F-1 / F-2 / F-3）沒有獨立檔案**，而是寫進行為所在的 Feature：
   - F-1（結帳當下品項 id 為 0）→ `attribute-order-line-item` 的
     `Rule: 後置（時機）`，含「重複觸發不重複累積 meta」
   - F-2（`RoundingStrategy` 零實作）→ 同檔 `Rule: 後置（金額）`，以
     「小數位數必須與結算頁加總一致」表達，不描述類別本身
   - F-3（訂單型別過濾）→ 分別出現在 `query-settlements`、`export-settlements-csv`、
     `reverse-settlement-on-refund` 三檔的 `Rule: 安全（型別）`，以及
     `query-partner-report.feature` 的檔頭註解
   理由：規格描述的是**外部可觀察行為**，「新增一個 class」不是行為。

3. **決策 D-1～D-6 全部照拍板結果寫入，並在檔頭或 Rule 註解簡述理由**：

   | 決策 | 落點 |
   |---|---|
   | D-1 賣場已刪寫入 rate=0 | `attribute-order-line-item` `Rule: 錯誤（賣場已刪）` |
   | D-2 金額精度收斂 | `attribute-order-line-item` `Rule: 後置（金額）` + `query-settlements` 摘要加總 |
   | D-3 部分退款重算 | `reverse-settlement-on-refund` `Rule: 後置（部分退款）` |
   | D-4 撥款用條件式更新 | `mark-settlement-paid` `Rule: 安全（併發）` |
   | D-5 CSV 回 base64 | `export-settlements-csv` `Rule: 後置（格式）` + 檔頭說明理由 |
   | D-6 order-ids 200 筆上限 | `view-order-profit-panel` `Rule: 已知落差` |

---

## 3. 負向與安全 Scenario 覆蓋對照

任務要求的五條 invariant，逐條對應：

| 要求 | 落點 |
|---|---|
| 簽章驗證失敗 → 完全不寫任何分潤 meta | `attribute-order-line-item` `Rule: 前置（驗章）`（竄改價格 / 無簽章 / meta 不齊三種） |
| 併發 mark-paid 不重複撥款 | `mark-settlement-paid` `Rule: 安全（併發）`（含「狀態不符時不得改寫任何欄位」與「同請求內須讀到新狀態」＝快取清除） |
| reverse-paid 對非 paid 狀態 → 拒絕 | `mark-settlement-paid` `Rule: 後置（沖回）` 的 Scenario Outline（pending / refunded / cancelled 三態） |
| CSV 公式注入被中和 | `export-settlements-csv` `Rule: 安全（公式注入）`（`=` `+` `-` `@` TAB CR ＋「三個名稱欄位都要跑」＋兩條反向 case：安全值不改、中間出現不改） |
| 訂閱不得重複計入 | `query-settlements` / `export-settlements-csv` / `reverse-settlement-on-refund` 的 `Rule: 安全（型別）` |

另補了任務未列、但藍圖風險登記表點名的三條：

- **R-5**（照抄購物車 hook 的後台守門會讓後台建單完全沒有分潤）→
  `attribute-order-line-item` `Rule: 環境（後台）`
- **R-6**（續訂 hook 是 filter，漏 return 會弄壞整條訂閱鏈路）→
  `carry-profit-to-renewal-order` `Rule: 後置（契約）`，含「拋例外時仍須回傳物件」
- **R-16**（兩條結帳路徑未來可能被拆開）→
  `attribute-order-line-item` `Rule: 路徑（結帳）`，並在檔頭寫明「共用同一方法是實作細節、
  不是 WooCommerce 承諾的契約」，避免日後有人看到紅燈就把測試改掉

刻意保留的取捨也都以 `Rule: 已知落差` 或檔頭註解標註（`_override_signup_fee` 的讀取時機、
D-6 的 200 筆上限、D-1 的 rate=0 紀錄需人工處理、CSV 無分批機制、結算列表無跨夥伴合併撥款）。

---

## 4. 無法從藍圖或設計文件確定、標記為缺失的項目（4 項）

以下四項規格中**只寫了行為輪廓、未固定字面值**，實作時需要拍板；不腦補成看起來合理的具體值。

| # | 項目 | 現況 | 影響 |
|---|---|---|---|
| **G-1** | 結算摘要的**回應欄位名**（`pending_amount` / `pending_count` / …） | 設計文件 §3.5 只說「摘要卡：待撥款金額（筆數）/ 已撥款金額（筆數）/ 退款金額」，未給欄位名；藍圖亦無。規格暫以這組命名撰寫 | 若 Stage 4 採用別的命名，`query-settlements.feature` 的摘要 Example 需同步改。**退款只有金額、沒有筆數**是設計文件的原文，規格仍寫了 `refunded_count`（結算頁需要它才能顯示「N 筆」），此處為刻意補齊而非照抄 |
| **G-2** | CSV 的**標題列文字與欄位順序** | 設計文件 §3.5 只列了結算表格的欄位（訂單# / 商品 / Partner / 賣場 / 應分潤 / 狀態），沒有匯出檔的標題文字，也沒說要不要含「訂單品項編號」與「實際單價」。規格採「表格欄位 + 訂單品項編號 + 實際單價 + 訂單日期」共 9 欄 | 對帳用途需要 order_item_id（撥款動作以它為鍵）與訂單日期，因此補上。若實作採不同欄位集合，`Rule: 後置（欄位）` 的兩個 Example 需同步 |
| **G-3** | 「已刪除賣場」／「已停用夥伴」的**確切字面值** | 設計文件 §5.7 寫的是 `[已刪除賣場]` 與 `[已停用 KOL]`。本專案其餘規格與 UI 一律用「夥伴」而非「KOL」，藍圖 §5 Stage 6 也寫 `[已停用夥伴]`。規格採 `[已停用夥伴]` | 三個檔案（query-settlements / export-settlements-csv / view-order-profit-panel）都斷言這個字串，改動時要三處一起改 |
| **G-4** | 部分退款「**累計退完**」的判定基準 | 藍圖 D-3 只說判準是 `$refund->get_items()` 的 `_refunded_item_id` + `qty` / `total`，未說明「多次部分退款累加到滿額」該以 qty 還是 total 為準（優惠券、運費分攤會讓兩者不一致）。規格以 qty 表達（`Example: 累計退完全部數量時視為全額退款`） | 若實作改以 total 為準，該 Example 的敘述需調整。兩者在有折扣的訂單上會給出不同結果 |

另有一項**已在規格中據實表達、不需拍板**但值得提醒：`attribute-order-line-item` 的
`Rule: 後置（金額）` 用了 `19.99 × 3 × 15% = 9.00` 這組數字。原始值為 `8.9955`，
在「兩位小數、四捨五入」下為 `9.00`；若站台的價格小數位數設為 3 位，正確值是 `8.996`，
Example 的 Background 因此明寫「站台的價格小數位數為 2」。

---

## 5. 給下游 Stage 的三個提醒

1. **`attribute-order-line-item.feature` 的 11 筆 meta 表格是逐 key 契約**，
   驗收時請比對**字面值**，不要用常數比對常數——那樣抄錯名字也會通過（藍圖 R-1）。
2. **`mark-settlement-paid.feature` 的稽核 Example 斷言 `total` 只計入實際成功的筆數**
   （`Example: 稽核金額只計入實際成功的筆數`）。若實作把整批請求的金額都寫進稽核，
   站長對帳時會看到比實際撥款多的數字。
3. **`carry-profit-to-renewal-order.feature` 的 Scenario Outline 涵蓋母訂單四種狀態**，
   其中 `refunded` / `cancelled` 兩列容易被認為「不合理，不用測」——但 WooCommerce
   Subscriptions 是無條件複製 meta 的，母訂單處於任何狀態都可能產生續訂單，
   續訂單一律該是 `pending`。
