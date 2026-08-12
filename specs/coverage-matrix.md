# Feature Rule 覆蓋盤點（快照）

> 盤點日期：**2026-08-12** ｜ 範圍：`specs/features/profit-shop/` 30 個 `.feature`、256 條 Rule
> 方法：逐 Rule 判斷「這條 Rule 描述的行為，有沒有測試真的會因為它被破壞而轉紅」，並實跑測試套件取當下真實數字。
>
> 本檔與 [`traceability.md`](traceability.md) 是**兩件不同的事**，不要混用（見 §8）：
> - `traceability.md`（機器產生）問的是：**這條 Rule 有沒有被標註 `@covered-by`**。
> - 本檔（人工盤點）問的是：**被標註的那個測試，真的覆蓋這條 Rule 嗎**。
>
> 🔴 本檔是**某個時間點的快照**，數字會過時。引用前請現查，理由見 §8。

---

## 一、先講清楚一件事：`.feature` 不是可執行測試

這是整份文件最重要的一段。本專案的 50 個 `.feature`（profit-shop 佔 30 個）**不會被任何東西執行**：

| 事實 | 現查驗證方式 | 結果 |
|---|---|---|
| 沒有安裝任何 BDD runner | `grep -iE "behat\|cucumber\|codeception\|gherkin" composer.json package.json` | 零命中 |
| `vendor/` 內也沒有 | `ls vendor/ \| grep -iE "behat\|codeception\|cucumber"` | 零命中 |
| 沒有任何 step definitions | 搜尋 `*.steps.*` / `behat.yml` / `codeception.yml` / `cucumber.js` | 零命中 |
| 全部帶 `@ignore` | `grep -l "@ignore" specs/features/**/*.feature \| wc -l` | **50 / 50** |
| 多數只有 `Rule:`、沒有 `Examples:` | — | 行為契約寫在 Rule 標題與註解裡 |

它們是 AIBDD Phase 01 產出的**規格骨架**，不是測試。

### ⇒ 由此推出的關鍵結論

> **「測試全綠」與「符合 feature 情境」是兩件不同的事。**

中間隔著一層 **`@covered-by` 註記**——那是**人工維持、沒有任何工具強制正確性**的對應關係。工具只能檢查「有沒有標」，**無法檢查「標得對不對」**。

而本輪已**實測證明那一層會標錯**：**13 條** `@covered-by` 指向不覆蓋該 Rule 的測試，**其中 2 條指向的測試斷言的是相反命題**（詳見 §7）。

所以：**看到某條 Rule 有 `@covered-by` 註記，不等於那條 Rule 受到保護。**

---

## 二、實測數字（2026-08-12 現查，非引用文件）

| 套件 | tests | assertions | failures | errors | skipped |
|---|---:|---:|---:|---:|---:|
| unit | **514** | 1178 | **0** | 0 | 0 |
| integration | **503** | 2601 | **0** | 0 | **1** |

- 唯一 skipped：`WpSettlementSummaryProviderRealAggregationTest::test_summary_works_under_hpos_enabled`（HPOS 未啟用，本輪跑的是 legacy posts 路徑）。
- **Pitfall #37 全域掃描：113 個測試檔、1012 個 test 方法，零靜默跳過。**

### 🔴 assertion 數不是可靠的回歸指標

同一組 **503 tests** 在同一份程式碼上連跑兩次，assertions 分別量到 **2601** 與 **2640**（皆 0 failures）。

⇒ 判斷有無回歸請看 **tests / failures / skipped** 三個數字，**不要看 assertions**。

### 執行指令（wp-env 容器內，見 `.claude/rules/wordpress.rule.md` §11.3）

```bash
./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration --colors=never
```

- ⚠️ 用 `./tests/e2e/node_modules/.bin/wp-env`，**不要**用 `npx wp-env`。
- ⚠️ wp-env 自身參數與容器內指令之間要用 `--` 分隔。
- ⚠️ `composer test` 在 Windows host 上跑不起來。
- ⚠️ `curl` 對 `localhost:8890` / `8900` 需加 `--noproxy '*'`，否則回 HTTP 000，容易誤判站台掛掉。

---

## 三、覆蓋總表

### Rule 層級（256 條）

| 判定 | 數量 | 佔比 | 意義 |
|---|---:|---:|---|
| `covered` | **129** | 50.4% | Rule 被破壞會有測試轉紅 |
| `partial` | **109** | 42.6% | 部分 Example 有保護，仍有可被破壞而不轉紅的面向 |
| `none` | **15** | 5.9% | 零保護（詳見 §6） |
| `unverifiable` | **3** | 1.2% | 依定義無實作可測，或恆跳過（詳見 §5） |

### Feature 層級（30 個）

| verdict | 數量 | Feature |
|---|---:|---|
| `fully_covered` | **3** | `carry-profit-to-renewal-order`、`export-settlements-csv`、`save-shop-item-overrides` |
| `mostly_covered` | **20** | 見 §4 |
| `thin` | **7** | 見 §4.1 |

> `export-settlements-csv` 雖有 1 條 `none`，仍判為 `fully_covered`——那條是 feature **自標 NOT-COVERED** 的「目前未提供的匯出能力」，依定義無實作可測，不是缺口。

---

## 四、逐 feature 覆蓋分布

依 verdict 排序，同 verdict 內依 `none` 數遞減。C / P / N / U = covered / partial / none / unverifiable。

| Feature | Rule | C | P | N | U | verdict |
|---|---:|---:|---:|---:|---:|---|
| `select-shop-products` | 12 | 0 | 8 | **4** | 0 | 🔴 thin |
| `import-legacy-shop` | 6 | 1 | 3 | **2** | 0 | 🔴 thin |
| `render-shop-front` | 11 | 2 | 6 | **2** | 1 | 🔴 thin |
| `query-shop-detail` | 5 | 2 | 2 | **1** | 0 | 🔴 thin |
| `view-order-profit-panel` | 8 | 1 | 6 | **1** | 0 | 🔴 thin |
| `add-to-cart-from-modal` | 10 | 1 | 8 | 0 | 1 | 🔴 thin |
| `enrich-shop-item-display` | 5 | 0 | 5 | 0 | 0 | 🔴 thin |
| `query-partner-report` | 9 | 4 | 2 | **3** | 0 | mostly_covered |
| `render-shop-via-shortcode` | 10 | 6 | 3 | **1** | 0 | mostly_covered |
| `apply-cart-override-price` | 10 | 6 | 4 | 0 | 0 | mostly_covered |
| `apply-single-product-override-price` | 7 | 5 | 2 | 0 | 0 | mostly_covered |
| `attribute-cart-item-to-shop` | 10 | 8 | 2 | 0 | 0 | mostly_covered |
| `attribute-order-line-item` | 10 | 6 | 4 | 0 | 0 | mostly_covered |
| `authorize-priceless-cart-item` | 9 | 4 | 5 | 0 | 0 | mostly_covered |
| `change-partner-password` | 8 | 1 | 7 | 0 | 0 | mostly_covered |
| `change-shop-status` | 6 | 2 | 4 | 0 | 0 | mostly_covered |
| `login-partner` | 11 | 5 | 6 | 0 | 0 | mostly_covered |
| `manage-partner-credentials` | 10 | 5 | 5 | 0 | 0 | mostly_covered |
| `manage-profit-partner` | 7 | 4 | 3 | 0 | 0 | mostly_covered |
| `mark-settlement-paid` | 9 | 4 | 5 | 0 | 0 | mostly_covered |
| `query-settlements` | 10 | 6 | 3 | 0 | 1 | mostly_covered |
| `reverse-settlement-on-refund` | 7 | 5 | 2 | 0 | 0 | mostly_covered |
| `save-profit-shop` | 11 | 8 | 3 | 0 | 0 | mostly_covered |
| `save-variation-overrides` | 11 | 7 | 4 | 0 | 0 | mostly_covered |
| `schedule-shop-availability` | 11 | 7 | 4 | 0 | 0 | mostly_covered |
| `select-product-variation` | 8 | 6 | 2 | 0 | 0 | mostly_covered |
| `validate-shop-slug` | 5 | 4 | 1 | 0 | 0 | mostly_covered |
| `export-settlements-csv` | 6 | 5 | 0 | 1 | 0 | ✅ fully_covered |
| `carry-profit-to-renewal-order` | 7 | 7 | 0 | 0 | 0 | ✅ fully_covered |
| `save-shop-item-overrides` | 7 | 7 | 0 | 0 | 0 | ✅ fully_covered |

### 4.1 `thin` 的 7 個與共通成因

| Feature | 為什麼 thin |
|---|---|
| `select-shop-products` | **12 條 Rule 沒有一條是 covered**。`ProductPickerModal` 與 `ItemsEditor.handleBatchAdd` 全在前端，8 條只由 vitest 覆蓋 |
| `add-to-cart-from-modal` | Modal 非同步加購的緩解行為（凍結按鈕、不重送）6 條只由 vitest 覆蓋 |
| `enrich-shop-item-display` | **5 條全部只由 vitest 覆蓋、0 條 covered**。`useShopItemProducts` 是 React hook |
| `view-order-profit-panel` | 訂單頁分潤面板是 React 元件；唯一的 `none` 是訂單列表過濾接線 |
| `import-legacy-shop` | `ImportModal` 四道閘門在 vitest；另有權限與保留兩條**後端**缺口（§6-5、§6-6） |
| `query-shop-detail` | 前端填表防覆寫在 vitest；另有一條**規格與實作不符**（§6-15，✅ 2026-08-12 已裁決為改規格，見 §七之一 ①） |
| `render-shop-front` | ⚠️ **例外，見下** |

**共通成因（適用於上表 7 個中的 6 個）**：行為主體在前端，而**前端測試不進 CI**。

🔴 **`render-shop-front` 只有一半適用，不要整個歸因於前端。** 現查其 11 條 Rule 中有 **2 條**提及 `tests/js`（後置（Modal）、後置（多賣場）），並非「提及數為 0」。精確拆解：

| 該 feature 的缺口 | 屬於哪一側 |
|---|---|
| 後置（SEO）注入標題與正規網址（`none`） | **純 PHP 側**零覆蓋，與前端 CI 無關 |
| 後置（多賣場）隔離（`none`） | **橫跨兩側**——PHP 端無「兩個賣場串接輸出」的測試，JS 端 helper 只建一個容器 |
| 已知落差（版型）Elementor（`unverifiable`） | 兩者皆非，需真實站台 |

把它整包歸進「前端沒 CI」會掩蓋 SEO 那條**純後端**的缺口——那正是 §7-1 標到相反命題測試的同一條。

### 4.2 🔴 前端測試不進 CI 是全域問題，不限 thin

| 統計項 | 數量 |
|---|---:|
| 只由 **vitest** 覆蓋的 Rule | **44** |
| PHPUnit + vitest 混合 | 9 |
| 只由 PHPUnit 覆蓋 | 187 |
| 無 `test_refs` | 16 |

那 44 條散布在 **15 個** feature（不只 thin 的那幾個），其中 `select-shop-products` 8、`select-product-variation` 8、`add-to-cart-from-modal` 6、`enrich-shop-item-display` 5。

**前端測試框架的精確狀態**（現查，2026-08-12）：

| 項目 | 狀態 |
|---|---|
| `vitest` devDependency | ✅ 已安裝 `^3.2.7` |
| `vitest.config.ts` | ✅ 存在 |
| `test:js` npm script | ✅ 存在 |
| 前端測試檔位置 | `tests/js/`（**17** 個 `.test.ts`）；`js/src/` 下 **0** 個 |
| e2e spec | 25 個 `.spec.ts` |

⚠️ 專案文件多處仍寫「專案無前端測試框架」——**那個說法已經過期**，框架已裝。準確的說法是「**框架已裝，但覆蓋不進 CI**」。

⚠️ **CI 狀態請現查，本檔不下斷言**：盤點當下量到 `.github/workflows/pipe.yml` 無任何 `npx playwright test` 呼叫、且三處測試呼叫一律 `--testsuite integration`（即 CI 從未跑過 `--testsuite unit`）。但歸檔當下 `pipe.yml` 正被修改（`git status` 顯示 `M`），**此處隨時可能已不同**。

---

## 五、3 條 `unverifiable`

| # | Feature | Rule | 為什麼無法判定 |
|---|---|---|---|
| U-1 | `add-to-cart-from-modal` | 已知落差（第三方）- 訂閱商品在購物車時回應可能非 JSON | 後端 3 條測試全部以 `require_subscriptions()` → `markTestSkipped` 開頭，wp-env 未安裝 WooCommerce Subscriptions（同 TD-64），**恆跳過、從未真正執行過**；前端 4 條 vitest 斷言確實但 CI 不執行 ⇒ **這條 Rule 目前在任何自動化閘門上都沒有實際保護** |
| U-2 | `query-settlements` | 已知落差 - 目前未提供的查詢能力 | 描述的是刻意未納入 v1 的能力（跨夥伴合併撥款檢視），無對應實作、依定義無行為可斷言。feature 自標 NOT-COVERED，屬誠實揭露而非缺口 |
| U-3 | `render-shop-front` | 已知落差（版型）- 頁面建構器排版的賣場內容不會被渲染 | 依定義無實作可測（TD-28），feature 自標 NOT-COVERED。要驗證需一個真的用 Elementor 排版的賣場跑真實站台（wp-env 沒有建構器）。目前只驗到前提條件（`get_the_ID()` 已修成賣場本身 id），實際渲染結果未驗 |

---

## 六、15 條 `none`（零保護）

分三類：**本輪正在補（6）**、**feature 自標已知落差（1）**、**其餘缺口（8）**。

### 6.1 本輪正在補的 6 條

| # | Feature | Rule | 為什麼沒覆蓋 | 要補什麼 |
|---|---|---|---|---|
| 1 | `query-partner-report` | 前置（分頁）- 分頁參數必須有上限以防資源耗盡 | 唯一引用的 `test_happy_returns_paginated_list` 只是傳 `page:1/per_page:20` 再斷言原值回來，與上限無關。實作在 `V2Api.php:1757-1758`，但**沒有任何測試對 `partner-reports/settlements` 送過 `page`/`per_page`**；Domain 的 `FilterCriteria` 也無 clamp。**把那兩行 `min()` 刪掉測試依然全綠** | IT 直接對端點送超限參數，斷言生效值（500→100、0→1、999999999→10000） |
| 2 | `query-partner-report` | 前置（期間）- 日期區間必須被正規化且有合理上限 | 引用的 `test_passes_interval_to_provider` 測的是粒度（day/week/month）傳遞，與時間戳收斂無關。對應 `format_datetime` 的 `MAX_TIMESTAMP` cap 零測試 | 斷言超範圍時間戳被收斂 |
| 3 | `query-partner-report` | 後置（格式）- 夥伴端點一律回傳裸 payload | 🔴 見 §7-1（標註掛錯 Rule）。全 `tests/` 找不到任何對夥伴端點的 `assertArrayNotHasKey('code')`。**契約若被改成裹 `{code,message,data}`，前端 partner portal 會整片壞掉而測試全綠** | 一條斷言 KPI 回應最外層直接是報表欄位且不含 `code` |
| 4 | `render-shop-front` | 後置（SEO）- 賣場頁必須注入標題與正規網址且不重複 | 🔴 見 §7-3（標的測試斷言相反命題）。renderer 的 `remove_action('wp_head','rel_canonical')` / `_wp_render_title_tag` 與自訂 title/canonical 注入**零覆蓋——拿掉會產生雙 canonical、雙 title，無任何測試會紅** | 把 `wp_head` 注入抽成可直接呼叫並 `ob_start` 擷取的方法 |
| 5 | `import-legacy-shop` | 權限（寫入）- 匯入需要 `manage_options` | 🔴 見 §7-4（標到完全不同的端點）。**把 `profit-migration/import` 的 permission 從 `admin_only` 降成 `default` 不會有任何測試轉紅** | 照抄 `PartnerCrudTest:151-182` 的 `shop_manager` 模式 |
| 6 | `import-legacy-shop` | 後置（保留）- 匯入不刪除也不修改舊版資料 | 標的兩條 `ListImportableLegacyShopsTest` 測的是**列表 UseCase**，全程沒執行任何匯入。`ImportLegacyShopTest` 的 `assertSame(1, $this->shopRepo->count())` 數的是**新的 ProfitShop repo**、不是 legacyRepo ⇒ **在 UseCase 內加一行 `legacyRepo->delete()` 不會有任何測試轉紅** | 匯入後對 `legacyRepo->find($legacy_id)` 斷言仍非 null 且內容等值 |

### 6.2 feature 自標 NOT-COVERED（快照當下 1 條，非缺陷）

> ✅ 2026-08-12 裁決後**實質上是 2 條**——§6.3 的第 15 條（`query-shop-detail` 列表分頁）已改標 `NOT-COVERED`，性質與本節相同。此處的「1 條」維持盤點快照不動（見 §七之一 開頭的說明）。

| # | Feature | Rule | 說明 |
|---|---|---|---|
| 7 | `export-settlements-csv` | 已知落差 - 目前未提供的匯出能力 | 描述「不提供分批 / 背景排程」，依定義無實作可測。feature 自身已標 NOT-COVERED，**非缺陷，不需補測** |

### 6.3 其餘 8 條

其中 5 條卡在**前端無 CI**（標 🖥️），2 條是**多賣場隔離**從未被驗證，1 條是**規格與實作不符**。

| # | Feature | Rule | 為什麼沒覆蓋 | 要補什麼 |
|---|---|---|---|---|
| 8 🖥️ | `select-shop-products` | 後置（加入）- 新商品應以預設值附加於列表末端 | 行為全在 `ItemsEditor.tsx:357` 的 `handleBatchAdd`（元件內 inline `useCallback`、**未 export**），`grep handleBatchAdd tests/` 只命中註解。標的 `ProfitShopItemsRoundTripTest` 驗的是後端持久化，是兩件事 | 把 `handleBatchAdd` 抽成純函式（如 `utils.ts` 的 `appendPickedItems`）再以 vitest 覆蓋 |
| 9 🖥️ | `select-shop-products` | 後置（加入）- 不得覆寫既有商品已設定的覆寫值 | 保證來自 `[...items, ...toAdd]`（既有列 object reference 不動），無測。兩個標的都是後端 REST round-trip，**若有人把 `handleBatchAdd` 改成重建整個陣列並套預設值，那兩條照樣全綠** | 同上抽純函式，斷言既有列的 `override` / `inflated_count` 逐字不變 |
| 10 🖥️ | `select-shop-products` | 後置（加入）- 批次加入必須去重且靜默略過重複項 | 🔴 見 §7-2（標的測試斷言相反結果）。真正的實作是 `handleBatchAdd` 的 `seenInBatch`/`alreadyInShop` 雙層 Set 與對應 notification，零測試 | 抽純函式後斷言去重筆數與提示文案 |
| 11 🖥️ | `select-shop-products` | 後置（加入）- 總數超過 500 筆時應警示但不阻擋 | `SOFT_ITEM_LIMIT=500` 的警示在 `ItemsEditor.tsx:402-408`，無測試。唯一標的只送 105 筆並斷言後端接受，**既沒觸及 500 門檻、也沒驗證警示與儲存仍可用** | 抽 `shouldWarnSoftLimit(total)` 純函式測門檻 + e2e 驗警示與儲存按鈕狀態 |
| 12 🖥️ | `view-order-profit-panel` | 前端（過濾）- 訂單列表可依分潤賣場過濾 | 標的只是後端 `order-ids` 端點。四個 Example 全是前端接線、零測試，最危險的是「**查無訂單時必須送 `include=[0]` 而非空陣列**」——空陣列在 `wc-rest` 語意是「不過濾」會顯示全部訂單。`grep tests/js` 無任何 `include`/`order-ids`/`ShopSelector` 命中 | 前端接線測試，尤其鎖死 `include=[0]` 這條 |
| 13 | `render-shop-front` | 後置（多賣場）- 同一頁可存在多個賣場且互不干擾 | 標的 `test_renderer_and_shortcode_paths_render_identical_dom` 是「同一個賣場、兩條渲染路徑」比對，與「一頁兩個賣場」無關。`tests/js/helpers/shopFront.ts` 只建**一個** `.ps-profit-shop-front` 容器 ⇒ **把 `closest()` 改成 `document.querySelector` 不會有任何測試轉紅** | PHP 端串接兩個賣場輸出；JS 端 helper 支援建立多容器 |
| 14 | `render-shop-via-shortcode` | 後置（多重）- 同一頁可放多個不同賣場的短碼 | 標的 `test_do_shortcode_renders_shop_content` 只 `do_shortcode` 一個賣場。沒有任何測試在同一份 HTML 內放兩個賣場並驗證各自渲染自己的商品 | 同上 |
| 15 | `query-shop-detail` | 後置（列表）- 賣場列表支援分頁 | 🔴 **規格與實作不符，且無測試**。`V2Api::get_profit_shops_callback`（`V2Api.php:473-486`）只讀 `partner_term_id`，直接 `array_map` 後 `self::ok($data)`——**沒有 `page`/`per_page` 參數、沒有 `total` 欄位**，兩個 Example 都不成立。（`V2Api:1758` 那個 `min(100, per_page)` 屬 `FilterCriteria`，不是這支端點） | ✅ **2026-08-12 已裁決：改規格、不補實作**（見 §七之一 ①）。Rule 已改標 `NOT-COVERED`，性質等同 §6.2 的第 7 條 ⇒ **不需補測試**。本列的 `none` 計數維持快照原樣，不回頭調整 |

---

## 七、🔴 13 條錯標的 `@covered-by`

> ⚠️ **本節數字曾為 4，經逐條複查後更正為 13。** 早期版本只收「註記中明確出現『標錯』字樣」的那 4 條；逐條檢視後確認另有 9 條的標註對象同樣不覆蓋該 Rule，只是措辭不同。**引用時請用 13。**

按嚴重度排序，**前 2 條標的測試斷言的是相反命題**。

| # | Feature / Rule | `@covered-by` 標的是誰 | 那個測試實際斷言什麼 | 為什麼不成立 |
|---|---|---|---|---|
| 7-1 | `render-shop-front`<br>後置（SEO）注入標題與正規網址 | `ProfitShopShortcodeTest::test_output_contains_no_seo_meta` | 🔴 shortcode **不得**注入 `<title>` / canonical（`assertStringNotContainsString`） | 🔴 **完全相反的命題**。Rule 要求 **renderer 必須注入**，標的測試要求 **shortcode 不得注入**。全 `tests/*.php` grep `canonical` 只有這一條 |
| 7-2 | `select-shop-products`<br>後置（加入）批次去重 | (1) `ProfitShopHydratorTest::test_product_id_zero_or_negative_is_silently_skipped`<br>(2) `ProfitShopKnownGapsTest::test_backend_rejects_duplicate_product_in_same_shop` | (1) 測 `product_id <= 0` 被跳過<br>(2) 🔴 `assertSame(409, ...)`——斷言後端**會報錯** | (1) 與「重複項去重」無關。(2) 🔴 **斷言相反結果**：Rule 的 Example 明文要求「**不應出現錯誤訊息**」＋「應提示實際加入 1 筆」 |
| 7-3 | `query-partner-report`<br>後置（格式）裸 payload | `PartnerReportsScopeTest::test_no_token_returns_401`<br>`::test_invalid_token_returns_401` | 兩條**各只有一行** `assertSame(401, $response->get_status())` | 與**回應形狀**完全無關，**掛錯 Rule**（兩者實屬「安全（範圍）」）。契約若被改成裹 `{code,message,data}`，前端 partner portal 會整片壞掉而測試全綠 |
| 7-4 | `import-legacy-shop`<br>權限（寫入）匯入需 `manage_options` | `LegacyImporterTest::test_list_legacy_shops_returns_200` | 以 **administrator** 呼叫 **GET 列表**並斷言 **200** | **既非 import 端點、也不驗證拒絕**。Rule 的 Example「僅具 `manage_woocommerce` 者不得匯入」完全沒有測試 |
| 7-5 | `import-legacy-shop`<br>後置（保留）不刪改舊資料 | 兩條 `ListImportableLegacyShopsTest` | 測**列表 UseCase**，全程沒執行任何匯入 | 無法證明匯入的副作用。`ImportLegacyShopTest` 的 `assertSame(1, $this->shopRepo->count())` 數的是**新** repo 不是 legacyRepo |
| 7-6 | `render-shop-front`<br>後置（多賣場）隔離 | `test_renderer_and_shortcode_paths_render_identical_dom` | 「同一個賣場、兩條渲染路徑」逐字比對 | 與「一頁兩個賣場」無關。`tests/js/helpers/shopFront.ts:102` 只建**一個**容器 ⇒ 把 `closest()` 改成 `document.querySelector` 不會轉紅 |
| 7-7 | `render-shop-via-shortcode`<br>後置（多重）同頁多短碼 | `test_do_shortcode_renders_shop_content` | 只 `do_shortcode` **一個**賣場 | 沒有第二個賣場，隔離完全未驗 |
| 7-8 | `select-shop-products`<br>後置（加入）附加於末端 | `ProfitShopItemsRoundTripTest::test_put_nested_items_round_trips_through_get` | 後端 PUT→GET 持久化 | 與「前端 form state 附加於末端且採預設值」是兩件事 |
| 7-9 | `select-shop-products`<br>後置（加入）不覆寫既有值 | `test_put_variation_overrides_round_trip_through_get`<br>`test_only_sale_price_set_leaves_regular_price_null` | 後端 REST round-trip | 完全不經過 Modal 加入路徑 |
| 7-10 | `select-shop-products`<br>後置（加入）500 筆警示 | `test_known_gap_backend_accepts_more_items_than_frontend_limit` | 送 105 筆並斷言後端接受 | 既沒觸及 500 門檻，也沒驗證警示與儲存仍可用 |
| 7-11 | `query-shop-detail`<br>後置（列表）分頁 | 3 條 `ListShopsTest` | 只測回傳全部或依 partner 過濾 | 零分頁斷言（且實作根本沒分頁，見 §7.1） |
| 7-12 | `enrich-shop-item-display`<br>降級不阻擋編輯 | `ProfitShopItemsRoundTripTest::test_put_nested_items_round_trips_through_get` | 後端 round-trip | 與降級語意無關 |
| 7-13 | `apply-cart-override-price`<br>三處價格一致 | `test_shop_cart_item_does_not_pollute_other_product_instances` | 驗「不污染」 | 非「三處一致」。三處各自有測試，但**沒有任何一條在同一組 fixture 上交叉比對三者相等**——🔴 正是 Phase 13 那個 bug（購物車劃線價與單品頁分歧）能潛伏的形狀 |

⇒ **`@covered-by` 的可信度整體偏低，看到註記不要直接當成有保護。**

---

## 七之一、2 條「規格與實作分歧」—— ✅ 2026-08-12 已裁決

這兩條**補測試解決不了**（規格與實作互相矛盾，必須先決定哪一邊是對的）。盤點當下標記為待裁決，同日由 `profit-shop-spec-gap` 落地，**兩條都判「改規格、不補實作」**。

⚠️ §3 / §4 的數字仍是**盤點當下**的快照，**未因本次裁決回頭調整**——改了會與 `129/109/15/3` 的交叉驗證對不上。裁決結果只記在本節與各自的引用點。

### ① `query-shop-detail` 列表分頁 → 改規格

| | 內容 |
|---|---|
| 原規格 | 「後置（列表）- 賣場列表支援分頁」，兩個 Example 斷言「回應應包含賣場陣列與總筆數」「`per_page` 超過 500 時收斂為 100」 |
| 實作 | `V2Api::get_profit_shops_callback`（`V2Api.php:473-486`）只讀 `partner_term_id`，`array_map` 後 `self::ok($data)`（`:892` 回 `{code:'success', data: <純陣列>}`）——**沒有 `page`/`per_page`，也沒有 `total`**，兩個 Example 都不成立 |
| **裁決** | **規格寫錯了，不是實作漏做。** Rule 標題改為「已知落差（列表）- 賣場列表不分頁，一次回傳全部」並標 `NOT-COVERED`；兩個 Example 改寫為符合實作的斷言（「回應的 data 應為賣場的純陣列」/「該參數應被忽略」） |
| 理由 | (1) 站上目前只有 2 個賣場，補分頁是過度設計；(2) `admin-list-ui.rule.md` §3.2 路線 B 本來就規定 profit-* 系列走「後端一次回全部、前端 `useMemo` client-side 過濾」——**實作與那條既有規則一致** |

🔴 **Rule 保留而非刪除**（與盤點時的建議一致）：刪掉會讓「規格曾經承諾過分頁」這個事實消失，日後有人重新提出時沒有任何線索說明當初為什麼不做。原本掛在此處的 3 個錯標 `@covered-by`（§7 的 7-11）已一併移除並在 feature 內註明原因。

⚠️ feature 內另留一條警告：日後若真要補分頁，**須連同 `admin-list-ui.rule.md` §3.2 的路線選擇一起重新評估**，不能只改後端——前端的 client-side 過濾會與 server-side 分頁互相矛盾。

### ② `mark-settlement-paid` 空清單 → 改規格，保留 fail-fast

| | 內容 |
|---|---|
| 原規格 | Example「空的識別清單回傳空結果**而非錯誤**」 |
| 實作 | `MarkSettlementsPaidUseCaseTest::test_empty_id_list_throws` 與 `ProfitSettlementsEndpointTest::test_mark_paid_with_empty_list_returns_400` 都鎖死拋錯 / 400 |
| **裁決** | **保留實作的 fail-fast**，Example 改為「空的識別清單應被拒絕並回報錯誤」（400） |
| 理由 | 這是**金流操作**。批次撥款收到空清單通常代表前端狀態有問題，靜默回「空結果、成功」會讓站長**以為已處理完畢** |

另補一條原本沒有的 Example「清單內全為非法識別時等同空清單一併拒絕」，對應既有測試 `MarkSettlementsPaidUseCaseTest::test_all_invalid_ids_throws`（`:265`，斷言 `expectException(\DomainException::class)` + `execute([0,-1,'abc',null], 42)`）。該測試標 `@group error`，在 `phpunit.xml.dist` 的 include 白名單內 ⇒ **會實際執行**，非 `wordpress.rule.md` §11.5 那種靜默跳過。

---

## 七之二、一條值得記的 `partial`

`query-partner-report` 明細狀態篩選：未覆蓋「未知結算狀態值一律被忽略」的 Scenario Outline（`unknown`、`' OR 1=1 --`）——**夥伴路徑零測試**。

⚠️ admin 路徑雖有 `SettlementFilterInputTest::test_unknown_status_throws_instead_of_being_dropped`，但那是**不同 DTO 且語意相反**（admin 拋錯 vs 夥伴忽略），**不能互相頂替**。

---

## 八、怎麼用這份文件

### 8.1 這是快照，不是持續正確的事實

🔴 本檔所有數字都是 **2026-08-12 當下**的。專案已因「引用過時文件數字」吃過虧，通則見 `.claude/CLAUDE.md` Common Pitfall **#39**：

> **不要在文件、docblock 或交付紀錄裡直接沿用文件記載的測試數字**，一律現查。

實例就在本輪：`tech-debt.md` TD-1 記載 integration 有 15 條 pre-existing 失敗、TD-2 記載 unit 有 1 條，**實測兩者皆為 0**（且單獨重跑 TD-2 指名那條確認是真的通過，不是沒被執行）。

**引用前請重跑**（指令見 §2），或至少現查：

```bash
grep -c 'public function test_' <測試檔>          # 方法數
grep -cE '^\s*Rule:' specs/features/profit-shop/*.feature   # Rule 數
```

#### 🔴 案例一：這份文件差點在自己身上犯同一個錯

歸檔過程中，本檔曾被寫入一句**沒有實測支撐的量化宣稱**：

> 「`render-shop-front` 的註記中，vitest / CI / React 提及數**皆為 0**」

回原始盤點資料查證，**實際有 2 條提及 `tests/js`**。若這句留下來，會把 §6-4（SEO 那條**純後端**缺口）整包歸進「前端沒 CI」而被掩蓋——那正好是 §7 表第 1 列「標到相反命題測試」的同一條 Rule。

⇒ 這不是抽象的「數字會過時」，而是**一份專門在講「宣稱要有實測支撐」的文件，自己寫下了無實測支撐的宣稱**。與 `.claude/CLAUDE.md` Pitfall #34 記載的 TD-20 / TD-54 / TD-67 是同一種失效模式：`phpcs` / PHPUnit / `tsc` / lint **一個都攔不到**。

**通則**：寫下任何「皆為 N」「全部都是」「零命中」之前，回原始資料跑一次；跑不了就改寫成有範圍的敘述，不要給精確數字。

#### 🔴 案例二：scratchpad 產出不能只看 mtime 判斷新舊

本輪一度差點採用**錯的那份**盤點：同一個 scratchpad 目錄下，有另一份檔案 **mtime 是當天**（比正確那份還新），內容卻是**另一輪**的結果——

| | 正確（本輪） | 誤採的那份 |
|---|---|---|
| feature 數 | 30 | 30 |
| `fully_covered` | 3 | 5 |
| `thin` | 7 | 17 |
| `unverifiable` | 3 | 6 |

檔案時間戳是新的、內容卻是舊的（很可能是被其他 process 讀取或重寫過而更新了 mtime）。

**判準：拿彙總數字對，不是看時間戳。** 本輪的指紋是 **30 feature / 256 Rule / 129-109-15-3**；任何一份聲稱是本輪盤點的檔案，這四個數字對不上就不是。

### 8.2 本檔與 `traceability.md` 的分工

| | `traceability.md` | 本檔 |
|---|---|---|
| 產生方式 | **機器產生**（`bin/check-traceability.php`），勿手動編輯 | 人工盤點 |
| 回答的問題 | Rule **有沒有被標** `@covered-by` | 被標的測試**真的覆蓋嗎** |
| 能否自動維持 | 可以（重跑即最新） | 不行（需重做盤點） |

⚠️ **兩者的 Rule 總數目前對不上，且已查明原因**：`traceability.md` 報 **255**、本輪盤點為 **256**。差異在 `login-partner`（traceability 記 10、實際 **11**），多出來的是 `Rule: 前置（掛載）- 報表頁只在自己的網址上接手，不得攔截站台其他頁面`。

根因是**產生檔過期**：`traceability.md` 最後提交於 **Aug 7**（`765bda6`），而 `login-partner.feature` 最後提交於 **Aug 11**（`c707817`）。

⇒ **`traceability.md` 目前對外報的覆蓋率是失真的**，需重跑 `bin/check-traceability.php`。本輪的 256 已用兩種獨立算法交叉驗證（逐檔 `grep -cE '^\s*Rule:'` 求和 = 256；盤點結果 `rule_count` 總和 = 256；`129+109+15+3` = 256）。

### 8.3 想補測試時的優先順序建議

依「破壞了不會轉紅、且後果嚴重」排序，§6 中最值得先補的是：

1. **§6-5 `import-legacy-shop` 權限** — 權限降級不會轉紅，是安全邊界
2. **§6-3 夥伴端點裸 payload** — 契約一改前端整片壞掉但測試全綠
3. **§6-12 `include=[0]`** — 送錯會讓站長看到「全部訂單」卻以為已過濾
4. **§6-1 分頁上限** — 刪掉 `min()` 不會轉紅，是資源耗盡防線

（1、2、4 與 §6-4、§6-6 同屬本輪正在補的 6 條。）

---

## 附錄：本輪盤點方法

- **實跑**：unit / integration 各跑一次取真實數字；另跑一次 `--testdox` 交叉驗證執行筆數。
- **Pitfall #37 前提先自我驗證**，未直接採信文件：`WpSettlementSummaryProviderRealAggregationTest` 類別級標 `@group security`、27 個方法中僅 14 個在方法級標 `security`，實跑 `--group security --filter` 得 **14 而非 27** ⇒ **「方法級取代類別級」經實測確認成立**（合併語意會得 27）。掃描遂採此較嚴格假設。
- **掃描結果**：113 個測試檔、1012 個 test 方法，每一個的有效 group 都至少命中 `smoke`/`happy`/`error`/`edge`/`security` 其一 ⇒ 零靜默跳過。（`phpunit.xml.dist` 只有 `<include>`、無 `<exclude>`，已確認無第二條過濾路徑。）
- **解析陷阱**：PHPUnit 9 的 `--testdox` 會把類別名尾端的 `Test` 去掉（`...RealAggregationTest` → `...RealAggregation`）。第一版腳本因此全數對不上、產出 113 個 `exec=0` 的**假警報**，修正 FQCN 映射後才得到真實結果。
- **全程唯讀**：`git status` 於開始與結束皆為 clean。
