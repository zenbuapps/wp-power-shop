# Power Shop — 已知問題與技術債登記表

> 持續累積的專案級已知問題清單。發現新問題時新增一列，問題解決後把該列移到「已解決」章節（保留紀錄）或直接刪除，並在對應 commit / PR 描述註記。
> 目的：讓「已知但暫不修」與「沒發現」有明確區分，避免同一個坑被不同 agent / 開發者重複踩、重複調查一次。
> 不是每一條都要立刻修——這份文件只負責記錄，**不代表待辦事項的優先順序承諾**。

**各文件分工**（避免重複維護同一件事）：
- 領域相關（PHP 4-layer DDD）：`.claude/rules/profit-shop.rule.md`
- React / TS 元件與 Data Provider 踩坑：`.claude/rules/react.rule.md`
- 後台列表頁版型：`.claude/rules/admin-list-ui.rule.md`
- PHP / 測試環境：`.claude/rules/wordpress.rule.md`
- 本檔：**跨領域、暫不處理**的項目；單一領域內已有專屬 rule 檔追蹤的問題，本檔只放交叉索引，不重複維護狀態

---

## 使用方式

1. 新發現的問題，若判斷「已知但這次不修」，登記到下方對應分類，附發現時間 / 來源 session。
2. 若某條問題被下一次工作處理掉，將該列移到文末「已解決」章節（不要直接刪掉，保留可追溯性），並在該次 commit / PR 描述中註明「解決 tech-debt.md 的 TD-N」。
3. **不要**把「這次工作本來就該做的事」登記在這裡——這份文件只放「發現但刻意不修」的項目，不是一般 TODO list。
4. 每條目 ID（`TD-N`）永久保留、不重複使用，即使該列已移到「已解決」。

---

## PHP / 測試

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-1 | Integration testsuite 有 **21 個 pre-existing 失敗**：partner 改密 7 / cart hook 6 / reports scope 2 / rewrite 2 / 其他 4 | 跑 `vendor/bin/phpunit --testsuite integration`（見 `wordpress.rule.md` §11.3 正確執行方式）；已用「把 Phase 7 新測試移到獨立目錄再單獨跑」的方式證明這 21 個失敗與 Phase 7 改動無關 | 讓人分不清「新壞的」與「本來就壞的」，需要每次都重新排除比對 | Phase 7（2026-07-29） |
| TD-2 | Unit testsuite 有 **1 個 pre-existing 失敗**：`LoginRateLimiterIpDimensionTest::test_ip_is_hashed_in_transient_key` | 跑 `vendor/bin/phpunit --testsuite unit --filter LoginRateLimiterIpDimensionTest` | 測試與實作不同步：Phase 3-D Batch 4 M-2 已把 IP hash 從 `hash('sha256', $ip)` 升級為 `hash_hmac(...)`，但這條測試斷言還停在舊的 `hash('sha256', $ip)` 寫法 | Phase 7（2026-07-29） |
| TD-3 | `vendor/bin/phpstan analyse inc` **在 HEAD 就跑不起來** | commit `3e0875c` 從 `phpstan.neon` 的 `bootstrapFiles` 移除了 `wp-cli-stubs.php`（註解稱「已由 powerhouse autoload 載入」，但實測 powerhouse 沒有定義 `WP_CLI_Command`）→ 直接 `Class "WP_CLI_Command" not found`，phpstan 無法執行 | 補回 stub 後可以跑，但會冒出 **81 個既有 level 9 錯誤**（散在 `WpSettlementSummaryProvider` / `V2Api` / `AddToCartHook` 等檔案）。這 81 個錯誤無一發生在 Phase 7 碰過的檔案 | Phase 7（2026-07-29） |
| TD-4 | CI（`.github/workflows/pipe.yml:681` / `:722` / `:762`）**只跑 `--testsuite integration`，從未跑過 `--testsuite unit`** | `grep -rn "testsuite" .github/workflows/*.yml` | 任何只寫在 `tests/Unit/**` 的新測試，CI 綠燈不代表它真的被執行過；需要在本機 / wp-env 容器手動跑 unit testsuite 才能驗證（見 `wordpress.rule.md` §11.4） | Phase 7（2026-07-29） |

## e2e / 測試基礎設施

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-5 | e2e 的授權（LC）bypass 機制有殘留風險 | `tests/e2e/helpers/lc-bypass.ts`：`applyLcBypass()` 會在 `plugin.php` 注入 `'lc' => false, /* E2E-LC-BYPASS */` 關閉授權檢查；`revertLcBypass()` **只在 `global-teardown` 呼叫**——Ctrl+C / timeout / `global-setup` 自己拋錯都不會觸發還原；還原失敗、殘留 marker 的狀態下重跑，`applyLcBypass()` 開頭 `if (content.includes(MARKER)) return` 會直接跳過注入**也跳過備份**，導致 `plugin.php` 停在被改過的版本；備份檔 `plugin.php.e2e-backup` **沒被 `.gitignore` 收錄** | **最壞情境：這個「關閉授權檢查」的狀態被 `git add -A` 一起 commit 進版本庫，等於出貨時關掉授權檢查** | Phase 7（2026-07-29） |

**TD-5 建議修法（未執行，登記備查）**：`.gitignore` 補上 `*.e2e-backup`；`revertLcBypass` 掛 `process.on('exit', ...)` 之類的保險，確保非正常結束也會還原。

## API 契約

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-6 | `specs/api/api.yml` 的 `variations` 型別宣告與實際 wire 格式不符 | `api.yml` 宣告 `variations: type: object`，但 PHP `wp_json_encode([])` 對「空關聯陣列」與「空索引陣列」無法區分，實際空值在 wire 上是 **`[]`（陣列）而非 `{}`（物件）** | 前端若嚴格照 OpenAPI schema 產生型別 / runtime 驗證器，空 `variations` 會驗證失敗。目前前端因此改用 `Record<string, …>` 並在型別註解說明「兩種形狀送回 PHP 都會被 `is_array()` 接受」規避（見 `profit-shop.rule.md` §7 / `react.rule.md` §6.5） | Phase 7（2026-07-29）。兩個獨立 agent（前端定型別時預判 + e2e 實跑撞到）各自驗到同一件事，屬於高信度的真實契約問題，不是單一 agent 誤判 |
| TD-12 | `ExceptionMapper::to_response()` 對 404 類例外（`ProfitShopNotFound` / `PartnerNotFound` / `ProductNotFound`）回應的 `data` 欄位固定是空陣列 `[]`，例外攜帶的細節沒有被結構化取出 | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/ExceptionMapper.php:101-106`：`return [ 404, 'not_found', [], [] ];`（第 4 個回傳值是 `data`，恆為空）；但 `ItemValidator.php:59` 拋的是 `new ProductNotFound( "商品 ID {$product_id} 不存在" )`，帶了 `product_id`，卻沒被放進 response body 的結構化欄位 | **目前不影響使用者**——訊息透過 exception `message` 走 `react.rule.md` §3「Refine HttpError 吃掉 response.data」踩坑的因應方式仍能到達前端；但若未來想讓前端**結構化**取得「是哪個 product_id 失效」（例如自動高亮該列），現在的回應格式做不到，只能靠字串比對 message。**本次刻意不修**：會打破「PHP 產品程式碼零修改」的交付狀態，且 UI 已有「商品已失效」Tag 可讓使用者自行定位，優先度不高 | 三輪改造（2026-07-29） |

## UX / 前端行為（跨頁面）

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-7 | REST 回 403（例如 nonce 過期）時，Powerhouse 全域錯誤處理會跳 `confirm()` 對話框「網站 Cookie 已經過期，請重新整理頁面後才能繼續使用」，**卡住整頁** | 任意頁面在 nonce 過期狀態下觸發一個查詢即可重現；不限 Profit Shop | 與 `specs/features/profit-shop/enrich-shop-item-display.feature` 的「補齊查詢失敗不得阻擋賣場編輯」spec 直接衝突——但成因在 Powerhouse 套件本身的全域處理邏輯，不在 Power Shop 程式碼內，無法在本 repo 單獨修掉 | Phase 7（2026-07-29），`useShopItemProducts` 補齊查詢的 403 情境下發現 |
| TD-8 | `ProductTable` mount 時無條件 `setSelectedProducts([])`（`components/product/ProductTable/index.tsx`） | 離開 `#/products` 再回來，先前的跨頁選取必定歸零 | 屬既有行為（非 Phase 7 引入）。Phase 7 曾懷疑是 `ProductPickerModal` 共用 Jotai atom 導致，已用「全程不開 Modal」對照組排除，確認與 Modal 無關，是 `ProductTable` 自身既有邏輯 | Phase 7（2026-07-29）排除 regression 嫌疑時發現，非新問題 |
| TD-9 | 商品列表「商品類型」FilterTag 顯示原始值 `simple`，而非中文「簡單商品」 | `#/products` 篩選後觀察 FilterTags | `valueLabelMapper` 沒有對應 product type 的中翻對照表，屬 UI 文案細節 | 重構前就存在，Phase 7（2026-07-29）覆查時再次確認仍未修 |
| TD-11 | Refine dataProvider 失敗時會**自己再跳一則**內建錯誤通知（例如「Error (status code: 500)商品 ID 999777 不存在」，約數秒後自動消失），與應用層自訂的 `onError` 通知同時出現，畫面上同時看到兩則重複的錯誤訊息 | 任一 Refine mutation 失敗即可重現；不限 Profit Shop | 使用者困惑「同一個錯誤為什麼跳兩次」，其中一則（Refine 內建）文字生硬、非繁中在地化。要收斂需調整 Refine 全域 `notificationProvider` 設定，影響全專案所有 Refine mutation，**本次刻意不修** | 三輪改造（2026-07-29），處理 `react.rule.md` §3「Refine HttpError 吃掉 response.data」踩坑時發現 |

## 功能缺口 / 前後端驗證落差

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-13 | **Shortcode 模式完全沒有實作**，只是一個存在 DB 的標記 | `grep -rn "shortcode" inc/ -i` 只有 4 個命中，全是 `ShopMode` enum 定義與註解，**沒有任何 `add_shortcode()` 註冊**；`ProfitShopRenderer::maybe_render` 只檢查 slug（`:111`）與 status（`:120`），**完全不讀 `mode`** | 選 Shortcode 模式的賣場：(1) 拿不到任何可貼進頁面的 shortcode (2) `/profit-shop/{slug}/` 公開網址照樣開得起來、照樣被搜尋引擎索引 —— 行為與「獨立頁面」100% 相同。`BasicTab.tsx` 目前以警語**據實告知**站長此事（刻意不把永久連結藏起來，避免 UI 誤導）。要補完需兩件事：Renderer 對 `mode === shortcode` 回 404 + 實作 `add_shortcode` 並在後台提供可複製的 shortcode 字串 | 2026-07-29，站長回報「為什麼 Shortcode 模式仍保留公開網址」時查證 |
| TD-14 | 賣場 `slug` **前端 required、後端不驗**：後端接受空字串並回 201 | 直接打 `POST /power-shop/profit-shops`，body 帶 `slug: ""` → **201 Created**（已用 IT 探針實測）；`CreateShop::execute()` 只跑 `SlugConflictDetector::detect()`，空字串不算衝突，沒有任何必填 / 格式驗證 | 繞過前端表單（API 直呼 / 第三方整合）可建出 slug 為空的賣場，其公開網址會是打不開的 `/profit-shop//`。**目前不影響後台操作**（前端 `BasicTab` 的 `required` + 格式 regex 擋得住一般路徑），但違反「後端 Domain 驗證才是唯一真相」的分層紀律（`profit-shop.rule.md` §12.9 三層錯誤處理分工）。要修需在 `ProfitShopHydrator` 或新的 VO 補 slug 必填 + 格式 invariant | 2026-07-29，診斷「shortcode 賣場無法建立」時用 IT 探針意外驗到 |

## 交叉索引（已在其他文件維護狀態，本檔不重複）

| # | 問題 | 詳見 |
|---|------|------|
| TD-10 | `#/profit-partner` / `#/profit-migration` 尚未對齊 `admin-list-ui.rule.md` 標準版型（篩選卡片 + 實體列表） | `.claude/rules/admin-list-ui.rule.md` §5「各列表頁對齊現況」（該表已標記「⚠ 待對齊」，是唯一維護處） |

---

## 已解決（保留紀錄，供追溯）

（目前無，本檔為 Phase 7（2026-07-29）首次建立）
