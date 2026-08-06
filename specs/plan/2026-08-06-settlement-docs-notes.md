# 分潤結算全鏈路 — 文件同步交付紀錄（2026-08-06）

> 對應任務：team lead 指派的「分潤結算文件同步」，範圍限定於文件檔案，**不碰**
> `inc/` / `js/` / `tests/` / `specs/plan/*.md`（既有）/ `specs/features/**`。
> 上游來源：commits `589d967` / `70a704f` / `866fbbd` / `6789585` / `621f40b` 及對應的
> 六份交付紀錄（`specs/plan/2026-08-06-settlement-{hook,api,fix,ui,hooks2,specs}-notes.md`）
> 與 `specs/plan/2026-08-06-profit-settlement-chain-plan.md`。

---

## 1. 改了哪些檔案

| 檔案 | 新增章節 / 內容 |
|---|---|
| `.claude/CLAUDE.md` | ① 新增 Common Pitfall #35～#39（純重構驗收標準 / red-green 探針窗口紀律 / PHPUnit `@group` 取代而非疊加 / CAS 繞過的兩個快取清除 / 測試數字必須現查）。② 更新「Profit Shop Domain」章節開頭的「目前狀態」行，加入 Phase 17 摘要。③ 新增「### Phase 17（分潤結算全鏈路）已完工」完整章節（在此之前的狀態、新增元件表、三個地基缺陷 F-1/F-2/F-3 摘要、驗收數字、已知落差），插在 Phase 16 之後、「Phase 5+ 預告」之前 |
| `.claude/rules/profit-shop.rule.md` | 新增「## 13. 分潤結算全鏈路（Phase 17，2026-08-06）」，含 10 個子節：13.1 完整資料流（含 priority 串聯圖）、13.2 cart/order meta 命名對映表、13.3 三個地基缺陷完整推導、13.4 D-1（賣場已刪除）、13.5 D-3（部分退款重算 + paid 不自動改）、13.6 消滅孿生驗章、13.7 `wcs_renewal_order_created` 是 filter、13.8 D-4（CAS）、13.9 訂單頁面板讀 item meta、13.10 CSV / 權限。另在 §11 commit 對照表補上 Phase 17 一列 |
| `.claude/rules/wordpress.rule.md` | 在既有 Phase 6-A1 端點表之後新增「Phase 17（分潤結算，7 個）」端點速查表，含權限、method、簡述，並交叉引用 `profit-shop.rule.md` §13 |
| `.claude/rules/architecture.rule.md` | ① 前台 Add-to-Cart 流程圖補上 `OrderLineItemSettlementHook`（priority 20）節點。② Domain 目錄樹補上 `Application/UseCase/Settlement/` 7 個 UseCase、`Infrastructure/Persistence/` 三個新類別、`Infrastructure/WooCommerce/` 四個新 hook + 2 個輔助類別、`Infrastructure/Rest/V2Api` endpoint 數 27→34。③ Loader 初始化流程樹補上新 hook 的實例化順序 |
| `.claude/rules/react.rule.md` | ① §5 路由表新增 `/profit-settlements`。② 新增「### 結算頁 ProfitSettlement + 訂單頁分潤面板（Phase 17）」章節（目錄結構、server-side 篩選的例外理由、`BatchActionModal` 二次確認、`skipped`/`audit_log_failed_partner_ids` 顯性呈現、金額字串定點加法、`atob()` Blob 陷阱、訂單面板讀 item meta 的理由、`DELETED_SHOP_LABEL` 對齊、`include=[0]` 陷阱、已刪除賣場保留在 selector）。③ §11 目錄樹補上 `ProfitSettlement/` 與 `Orders/Edit/ProfitPanel/` |
| `specs/README.md` | 把「🚧 實作中」紅色警語改寫為「✅ 已完工」，Stage 1-6 逐項標記完成狀態，保留唯一未完成項（真實站台端到端驗證）的顯性提示 |
| `specs/tech-debt.md` | 新增「## 分潤結算全鏈路（Phase 17 新增，2026-08-06）」小節，TD-72～TD-77 + TD-79～TD-87（共 15 條，見下方§2 說明編號跳過 78 的原因）；並在「已解決」章節新增 TD-78（設計文件 §6.1 權限矩陣已修正） |
| `specs/2026-05-06-profit-shop-design.md` | §6.1 認證矩陣：把 `profit-settlements/*` 從與 `profit-shops/*` / `profit-partners/*` 共用的 `manage_woocommerce` 一列拆出，改列 `manage_options`，並附修正說明段落 |

---

## 2. 技術債編號說明

新登記 TD-72～TD-77、TD-79～TD-87（共 15 條，涵蓋團隊指派的 8 項中的 7 項——第 8 項「`npx eslint` 無法執行」直接引用既有 TD-31，未另立新號），另有 1 條解決紀錄 TD-78（設計文件 §6.1 不一致，已修正並移入「已解決」章節）。

除團隊明確列出的 8 項之外，另外從六份交付紀錄裡「建議登記技術債 / 留給 Stage 7 登記」的段落補齊了 8 條（TD-79～TD-87 扣除 TD-78）：D-6 的 200 筆上限、`_override_signup_fee` 讀取時機、D-1 的 `rate=0` 紀錄無批次工具、`ProfitCalculator` 硬寫 4dp、部分退款重算的殘留競態、`shop_manager` 看得到結算選單但打不進去、訂單列表 `pageSize` 對 `wc-rest` 無效、`mark-settlement-paid.feature` 欄位名與實際 DTO 不符、CSV 匯出無筆數上限的 OOM 風險。這些是原始交付紀錄裡明確寫著「本工作項未改 `tech-debt.md`，留給 Stage 7」或「建議登記技術債」但尚未真正登記的項目——判斷這正是本次文件同步的職責範圍，因此一併補上，而非只做團隊訊息裡列出的 8 條。

---

## 3. 幾個值得說明的偏離 / 現查更正

1. **`vendor/bin/phpcs inc/` 重新現查為 167/167**，未沿用團隊提供的「164 檔」——實測後發現中間又新增了 3 個檔案，167 才是當下真實值。這正是新增的 Common Pitfall #39（測試數字必須現查，不可沿用文件記載）在本次文件同步過程中的直接應用。
2. **`npx tsc --noEmit` 現查為 118**，與團隊提供數字一致，已確認無誤直接引用。
3. **Unit / Integration 測試數字沿用團隊提供的最終值**（Unit 503/1160/1 failure；Integration 399/1904/15 failures/4 skipped）——未重新在 wp-env 容器內重跑，理由：(a) 多個交付紀錄本身就明確記載「多 agent 平行跑 phpunit 會污染共用 wp-env MySQL 容器」（現已登記為 TD-74），本次若再次執行同一份測試套件只是重新製造這個風險，且無法確定此刻是否仍有其他行程在使用同一個容器；(b) 團隊訊息明確標註這是「實測，可直接引用」的最終驗收數字，且與六份交付紀錄逐項核對後數字彼此一致（例如 hooks2-notes.md 記載的最終 Integration 399/1904/15/4 與團隊給的數字逐字相符）。
4. **`specs/README.md` 的 Feature 統計數字（50 = 31 @command + 19 @query）與「plan/ 目錄」列表未再變動**——查核後確認這兩處已由 `settlement-specs` agent 的前置作業（commit `0eb0342` / `70a704f`）同步完成，本次不重複改動。

---

## 4. 尚未驗證 / 誠實標註（第一輪）

第一輪交付時，依團隊訊息指示，「真實站台端到端動線尚未驗證」這件事在 `.claude/CLAUDE.md` Phase 17 章節、`specs/README.md` 警語段落**都以現在式明確標註為未完成**，未假設它會通過。**此節內容已被第 6 節取代**——團隊隨後傳來兩則訊息，附上真實站台端到端驗證的實測數據，指示改寫為「已通過」。

---

## 5. 未改動的檔案（確認在範圍外）

- `inc/` / `js/` / `tests/` 下所有檔案：零改動。
- `specs/plan/2026-08-06-*.md`（六份既有交付紀錄與藍圖）：只讀未寫。
- `specs/features/**`：只讀未寫（７個新 Feature 檔已由 `settlement-specs` agent 在 commit `70a704f` 建立，本次不重複處理）。
- `.claude/rules/admin-list-ui.rule.md`：不在本次檔案範圍內（團隊訊息的「檔案範圍」清單未列出），未改動；`ProfitSettlement` 列表頁若要在該檔 §5「各列表頁對齊現況表」補一列，需另行指派。

---

## 6. 第二輪更新：真實站台端到端驗證結果（團隊事後補傳）

團隊在第一輪交付後傳來兩則訊息，附上真實站台（`turbo.local`）端到端驗證的實測數據，指示把「尚未驗證」改寫為「已通過」，並更正兩條環境事實。以下是本輪異動：

| 檔案 | 異動內容 |
|---|---|
| `.claude/CLAUDE.md` | ① Pitfall #30 補充站台網域現況（前台是 `turbo.local`、`local-turbo.powerhouse.tw` 只是 siteurl，直接 curl 後者會 HTTP 000）。② Phase 12 章節的「Local by Flywheel PHP 連不上 DB」註記，補充「換對 PHP 仍不夠」——需用 Local 內建 mysql client 直連（含實測可用的路徑與連線參數）+ PHP 版本須與站台 `php.ini` 相符的補充陷阱。③ Phase 17「已知落差 / 尚未驗證」整段改寫為「真實站台端到端驗證（✅ 已通過）」，附訂單 #2241 / line item #428 的完整 meta 對照表、七件被同時證明的事、結算查詢驗證結果、HPOS 未涵蓋的限制、仍未驗證的 wp-admin UI / 續訂路徑、測試資料保留說明 |
| `.claude/rules/profit-shop.rule.md` | ① §13.3 F-3 的 HPOS 註記補充「自動化測試與真實站台剛好都只測到 legacy 分支，HPOS 完全零驗證」的推論，交叉引用 TD-88。② 修正 §13.10 內一處**筆誤**——原文寫「該文件已同步修正，見下方 §14 提示」，但本檔沒有 §14，已改為直接引用 `specs/2026-05-06-profit-shop-design.md` §6.1 與 `specs/tech-debt.md` TD-78。③ 新增「§13.11 真實站台端到端驗證」完整章節，內容與 CLAUDE.md 對應但以本檔慣例的深度呈現（逐 meta 列出對應與意義、結算查詢驗證、HPOS 限制、測試資料保留說明） |
| `specs/README.md` | 警語段落從「🔴 唯一尚未完成：真實站台端到端動線驗證」改寫為「✅ 真實站台端到端驗證已通過」，附精簡版驗證摘要；同時列出三項仍未驗證的部分（HPOS、wp-admin UI、續訂路徑），不誇大完成度 |
| `specs/tech-debt.md` | 新增 **TD-88**：結算查詢五處 SQL 的 HPOS 分支完全零驗證——把 `settlement-hook-notes.md` §4.2 記載的「自動化測試只測 legacy」與真實站台驗證時發現的「站台本身也未啟用 HPOS」兩件事合併記錄，明確結論為「兩者剛好覆蓋同一半，HPOS 分支目前沒有任何一層驗證覆蓋過」 |

### 6.1 度量與精確化更正

依團隊第二則訊息的更正，所有文件內對 `_profit_rate` 的描述一律採用「可核對的寫法」：**「從賣場讀取到的比例為 10%，`_profit_amount = 600 × 1 × 10% = 60`」**，不使用「讀取成功」這種無法核對的籠統敘述。

### 6.2 一併發現並修正的既有錯誤（非本輪任務範圍，但屬同一批檔案的筆誤）

`profit-shop.rule.md` §13.10 原文有一處寫「見下方 §14 提示」的**懸空引用**（本檔案沒有 §14 章節，是第一輪撰寫時的筆誤）。第二輪修改同一段落時一併發現並修正，改為直接引用實際檔案與 TD 編號。已確認全檔案 code fence（```` ``` ````）配對數量在兩輪修改後皆為偶數，無殘留的未閉合區塊。

### 6.3 未做的事（誠實列出）

- 未重新驗證 `.claude/CLAUDE.md` 與 `profit-shop.rule.md` 之外，是否還有其他檔案（例如 `architecture.rule.md`）需要因為「已驗證」而更新驗證狀態——判斷這兩處已是「端到端驗證結果」的唯一權威位置，`architecture.rule.md` 只放靜態架構圖不放驗證結果，故未動。
- 未親自重新查詢 MySQL 或重新操作真實站台驗證團隊提供的數據——本輪純粹依團隊提供的實測結果轉寫進文件，未做二次查證（團隊訊息本身即為第一手實測紀錄，非轉述）。
