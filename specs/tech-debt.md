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
| TD-18 | `composer lint`（即 `phpcs.xml` 的預設掃描範圍）在 HEAD 就不綠，且錯誤**全部來自未追蹤的 `.claude/tmp/wp-power-shop-source/`**（某次 session 留下的整包原始碼副本） | `phpcs.xml` 的 `<file>.</file>` 掃描目前目錄下所有檔案，會把 `.claude/tmp/` 底下的複製品也掃進去；`vendor/bin/phpcs inc/`（限定產品程式碼目錄）本次實測 138/138 全綠，證明有意義的閘門是後者不是前者 | 誤導性的紅燈：讓人以為產品程式碼 lint 沒過，實際上只是一份未清除的暫存副本被誤掃。修法二選一：(1) 清掉 `.claude/tmp/wp-power-shop-source/`；(2) 在 `phpcs.xml` 的 `<file>.</file>` 之後加一行 `<exclude-pattern>.claude/*</exclude-pattern>`（`phpcs.xml` 現有的 `<exclude-pattern>` 都是這個寫法，跟既有慣例一致） | Phase 9（2026-07-30） |

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
| TD-19 | `memo(VariationOverrideEditor)` 實際不生效 | `ItemsEditor.tsx` 的 `expandedRowRender` 內，傳給 `VariationOverrideEditor` 的 `onPatchOverride` / `onPatchInflated` / `onRemoveOrphan` 三個 prop 都是 inline arrow function（`(variationId, patch) => handlePatchVariationOverride(record.product_id, variationId, patch)` 等），每次外層 `ItemsEditor` 重新 render 就是新的函式 reference，`memo` 的 shallow prop 比對必定判定為「有變化」而重新渲染 | 非正確性問題（外層表格本來就會因為使用者打字而整體重繪，`memo` 頂多是省下的優化沒生效，不會顯示錯誤資料）。若未來實測到展開列輸入延遲，這是第一個該查的地方；要真的讓 `memo` 生效需要把這三個 callback 改成 ref-based 穩定引用（例如用 `useCallback` 搭配把 `record.product_id` 存進 ref，或改用事件委派） | Phase 9（2026-07-30） |

## 功能缺口 / 前後端驗證落差

| # | 問題 | 證據 / 重現方式 | 影響 | 發現於 |
|---|------|------------------|------|--------|
| TD-13 | **⚠️ 已部分解決（2026-07-30）**：短碼本身已實作可用，但 Shortcode 模式的賣場**公開網址仍然開得起來、仍會被搜尋引擎索引** | 已解決的一半：`Infrastructure/WordPress/ProfitShopShortcode.php` 註冊 `[power_shop_profit_shop id="{post_id}"]`，`#/profit-shop` 列表頁對 shortcode 賣場顯示「複製」按鈕（17 條 IT 覆蓋）。**未解決的一半**：`ProfitShopRenderer::maybe_render` 依然只檢查 slug 與 status，**完全不讀 `mode`** | 站長現在拿得到可貼進頁面的短碼（原本「選了 Shortcode 模式卻拿不到任何短碼」的功能缺口已補上）。**但公開網址並未關閉**——mode=shortcode 的賣場 `/profit-shop/{slug}/` 行為與「獨立頁面」仍然 100% 相同，`BasicTab.tsx` 的據實告知警語因此**依然成立、不可移除**（不要因為列表頁多了「複製」按鈕、看起來功能完整就把它清掉）。刻意不一起做 Renderer 的 mode 檢查：那會讓**既有已上線**的 shortcode 賣場前台網址突然變 404，blast radius 遠大於當時的需求範圍。要補完剩下這半需：Renderer 對 `mode === shortcode` 回 404，並事先盤點既有 shortcode 賣場是否已有對外流量 | 2026-07-29 站長回報「為什麼 Shortcode 模式仍保留公開網址」時查證；2026-07-30 實作短碼與列表頁「複製」按鈕時解決前半 |
| TD-14 | 賣場 `slug` **前端 required、後端不驗**：後端接受空字串並回 201 | 直接打 `POST /power-shop/profit-shops`，body 帶 `slug: ""` → **201 Created**（已用 IT 探針實測）；`CreateShop::execute()` 只跑 `SlugConflictDetector::detect()`，空字串不算衝突，沒有任何必填 / 格式驗證 | 繞過前端表單（API 直呼 / 第三方整合）可建出 slug 為空的賣場，其公開網址會是打不開的 `/profit-shop//`。**目前不影響後台操作**（前端 `BasicTab` 的 `required` + 格式 regex 擋得住一般路徑），但違反「後端 Domain 驗證才是唯一真相」的分層紀律（`profit-shop.rule.md` §12.9 三層錯誤處理分工）。要修需在 `ProfitShopHydrator` 或新的 VO 補 slug 必填 + 格式 invariant | 2026-07-29，診斷「shortcode 賣場無法建立」時用 IT 探針意外驗到 |
| TD-15 | 變體層 `inflated_count`（灌水購買人數）**零前台顯示點** | `inc/classes/Domains/ProfitShop/Infrastructure/WordPress/templates/profit-shop-front.php` 只 `foreach ($items as $override_item)` 渲染父商品卡片（一個 item 一張卡），`variable` / `variable-subscription` 商品的卡片是一顆連到 WC 單品頁的連結，變體選擇整段發生在單品頁，本外掛未介入該頁的變體 UI 渲染 | 站長在 `ItemsEditor` 展開列為某個變體設定的灌水購買人數會被正確儲存與讀回，但**目前沒有任何畫面會顯示它**——與 `profit-shop.rule.md` §7 警告的 `default_rate` / `page_template` 反模式同型（「存得進去、讀得出來、但沒人用」）。差別是**這次是有意識的取捨且已對站長誠實揭露**：前端在該欄位掛了 Tooltip「變體層灌水人數目前僅會儲存，賣場前台與商品單頁尚未顯示變體層購買人數。此欄位為未來功能預留。」，程式碼註解也說明為何商品層與變體層的提示文案刻意不同（商品層的「前台會顯示 真實購買人數 + 灌水購買人數」已因 Phase 9 第六階段接上賣場前台而成真 —— 實測「已售 880 件」＝真實銷量 873 + 灌水 7；變體層的仍是未來式），防止日後有人「順手統一」把假承諾帶回來 | Phase 9（2026-07-30），實作前規劃階段即發現並列為已知取捨 |
| TD-16 | `wc-rest` `GET /products/{id}/variations` 的 `per_page` 硬上限 100，賣場編輯頁的變體展開列超過 100 個變體只顯示前 100 個 | `useProductVariations`（`pages/admin/ProfitShop/hooks.ts`）的 `VARIATION_PAGE_LIMIT = 100`；這是 WooCommerce REST API 本身的限制，不是本專案的選擇 | 變體數量異常多的商品（超過 100 個變體）無法在展開列設定第 101 個之後的變體覆寫。已有 UI 揭露（`VariationOverrideEditor` 的 warning `<Alert>`：「僅顯示前 100 個變體」），不是靜默截斷。要支援需改走分頁載入（累加式抓取多頁 `page` 參數），屬於後續工程 | Phase 9（2026-07-30） |
| TD-21 | `CartItemMetaDisplay`（Phase 4-C2）加在購物車項目上的「來自賣場 XXX」標記，在 **WooCommerce Cart Block / Store API** 路徑下顯示異常，出現字面上的 `undefined:` 字樣 | 真實環境人工驗證（Cart Block，本站實際使用的購物車介面）：畫面上看到 `undefined: [TMP] 變體覆寫驗證賣場`，而 legacy cart 頁（`[woocommerce_cart]` shortcode）正常顯示「來自賣場：[TMP] 變體覆寫驗證賣場」。已核對 `woocommerce/src/StoreApi/Schemas/V1/CartItemSchema.php:155-183` 的 `get_item_data()`，確認 Store API 的 PHP schema **原封不動**透傳 `woocommerce_get_item_data` filter 回傳的 `key` / `value` / `display` 三個鍵（只用 `wp_strip_all_tags` 去 HTML 標籤，不改鍵名），故 PHP 端本身沒有明顯的 key 命名錯誤；`undefined:` 較可能發生在 `@woocommerce/blocks`（WC Blocks 前端 React 元件，第三方套件，非本 repo 程式碼）消費 `item_data` 時所讀的欄位名稱，但受限於該套件編譯後為 minified JS，未能追到確切的渲染程式碼行號 | 影響**顧客結帳體驗**（購物車畫面出現看起來像程式錯誤的文字），且站台實際使用 Cart Block，並非邊緣案例。**與 Phase 9 改動無關**（`CartItemMetaDisplay` 是 Phase 4-C2 既有程式碼，本次未修改），是這次真實環境驗證變體覆寫功能時順帶發現的既有問題。修法需先在瀏覽器 DevTools 或 WC Blocks 原始碼確認前端實際讀取的欄位名稱，再決定是否需要讓 `CartItemMetaDisplay` 額外多回傳一個相容欄位 | Phase 9 真實環境驗證（2026-07-30），team lead 人工測試變體覆寫端到端流程時發現 |

## 交叉索引（已在其他文件維護狀態，本檔不重複）

| # | 問題 | 詳見 |
|---|------|------|
| TD-10 | `#/profit-partner` / `#/profit-migration` 尚未對齊 `admin-list-ui.rule.md` 標準版型（篩選卡片 + 實體列表） | `.claude/rules/admin-list-ui.rule.md` §5「各列表頁對齊現況」（該表已標記「⚠ 待對齊」，是唯一維護處） |

---

## 已解決（保留紀錄，供追溯）

> ⚠️ 依本檔「使用方式」第 4 條，ID 一旦指派**永久保留、不重複使用**——即使問題已解決並移到本節，`TD-20` 這個編號也不會再指派給其他問題。

| # | 問題 | 證據 / 重現方式 | 解決方式 | 發現於 → 解決於 |
|---|------|------------------|----------|-----------------|
| TD-20 | `VariationOverrideEditor` 展開列頂部的揭露 `<Alert>` 文案（「變體覆寫價於加入購物車後生效；商品單頁仍顯示商品原始價格」）在同一次 Phase 9 實作 `SingleProductPriceHook` 之後**與事實相反** | 真實環境實測（team lead，2026-07-30）：帶 `?profit_shop_id` 的單品頁價格區間從 `NT$42–NT$45` 變為 `NT$45–NT$888`，逐變體 `display_price` 為 79→888 / 86→777 / 80、81→45（fallback），購物車 Store API 實收 `888` 三處一致——證明單品頁**確實**已顯示覆寫價，Alert 宣稱的「仍顯示原價」不成立 | 文案改為四動線正確揭露（賣場卡片 → 父層覆寫價／從賣場進單品頁 → 變體覆寫價／購物車結帳 → 變體覆寫價／未經賣場直接開商品頁 → 原始價格），過期的程式碼註解一併重寫並標明「未經賣場顯示原價」是刻意保留的設計意圖而非缺口（防止日後被誤判成 bug「修掉」而導致覆寫價脫離賣場外洩）。**保留本列的教訓**：同一次改動內「規劃階段寫的緩解文案」與「後來才決定實作的加強措施」並存時，文案不會自動跟著實作更新——這次是文件同步階段逐檔比對 UI 文案才抓到，差一步就會留著一句與事實相反的說明給站長看。同型紀律已寫入 `profit-shop.rule.md` §7「`SingleProductPriceHook`」章節 | 發現：Phase 9（2026-07-30）文件同步時逐檔比對程式碼與 UI 文案；解決：同日，team lead 派人依真實環境測試結果改正文案 |
