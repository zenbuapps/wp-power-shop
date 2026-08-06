# 分潤結算鏈路修補 — `settlement-fix` 實作紀錄（2026-08-06）

> 🔴 **本輪只涵蓋修補 3（消滅孿生驗章）。修補 1（CAS）與修補 2（夥伴報表 SQL 型別過濾）
> 由 `settlement-api` 負責**——team lead 裁決 A，因兩項被重複指派且對方仍在活躍寫入。
>
> 本檔仍保留修補 1 / 2 的段落，但那是**現況查核與交接紀錄**，不是我的交付：
> - §1 修補 1：我一行未寫，只做成品查核
> - §2 修補 2：我起頭後即退出（`summary_for_partner` 一處 + `ORDER_POST_TYPE` 改 public
>   留給對方沿用，team lead 指示「不要 `git checkout` 還原，直接放手」）；
>   §2.3 的第一條訂閱測試是我補的，其餘 5 條與最終 red-green 由對方完成
> - §3 修補 3：**這才是我的交付**
>
> 分支：`refactor/one-page`
> 上游：`specs/plan/2026-08-06-profit-settlement-chain-plan.md`
> 前兩輪：`2026-08-06-settlement-hook-notes.md`、`2026-08-06-settlement-api-notes.md`

---

## 0. 🔴 先讀這一節：本輪有兩個 agent 同時在改同一批檔案

我（`settlement-fix`）開工當下（19:52），**另一個 agent 正在寫入修補 1 與修補 2 的檔案**。
這不是事後推測，是當場量到的：

| 時間 | 事件 |
|---|---|
| 19:49–19:51 | `SettlementStatusMutatorInterface` / `WpSettlementStatusMutator` / 三個 UseCase / `SettlementBatchGuard` / `WpAdminSettlementQuery` 陸續被寫入（`find -newermt` + mtime） |
| 19:51 | 我對 `ReversePaidSettlementsUseCase` 下 Edit → 被工具擋下（*File has been modified since read*）。重讀後發現它**已經**改成 CAS 了 |
| 19:53–19:54 | 我改 `WpSettlementSummaryProvider::summary_for_partner`；同一分鐘內 `$order_type_col` 出現**重複兩行**（148/149），而 `trend` / `find_by_partner` 也各多出一行**不是我加的** |

⇒ **修補 1 與修補 2 都被重複指派**。我在 19:52 與 19:55 兩次回報 team lead，
並**停止編輯 `WpSettlementSummaryProvider` 的生產碼**，改做零衝突的部分。

### 為什麼「同一個函式內交錯編輯」特別危險（不只是 merge 麻煩）

`$wpdb->prepare()` 的 placeholder 數必須與 args 數**完全相等**。當下的中間狀態是
「`summary_for_partner` 三件都齊（我）、另兩個方法只有變數宣告（對方）」——若對方接著
補上 WHERE 而沒發現 `summary_for_partner` 已經改過，就會變成「兩個 `%s`、一個 arg」
⇒ `prepare()` **直接回空字串、查詢回 0 筆、夥伴報表整片歸零，且沒有任何錯誤訊息**。
`phpcs` / `phpunit` / `phpstan` 一個都攔不到。

### 最終分工（實際落地的結果）

| 修補 | 生產碼 | 測試 + red-green |
|---|---|---|
| 1（CAS） | **另一 agent**（已完成，見 §1 現況查核） | 另一 agent |
| 2（SQL 型別過濾） | **兩人共同**（我起頭 + 開放常數；對方補完另兩處） | **我**（§2） |
| 3（消滅孿生驗章） | **我**（§3） | **我**（§3） |

---

## 1. 修補 1（`mark-paid` CAS）— 現況查核，我未動手

**我沒有寫這一項的任何一行**。以下是我在 20:15 對成品做的查核，供 team lead 交叉驗證：

| 查核項 | 結果 |
|---|---|
| CAS 實作 | `Infrastructure/Persistence/WpSettlementStatusMutator.php`，`$wpdb->update()` + affected rows 判定 |
| 快取清除 | ✅ 有，且**群組名正確**：`wp_cache_delete( $id, 'order_item_meta' )`（不是資料表後綴 `order_itemmeta`）+ `WC_Cache_Helper::invalidate_cache_group()` |
| 三個 UseCase 都改用 CAS | ✅ `MarkSettlementsPaid` / `MarkSettlementsCancelled` / `ReversePaidSettlements` 皆 `grep compare_and_set` 命中 |
| V2Api 接線 | ✅ 三個 callback 都已 `mutator: WpSettlementStatusMutator::instance()`（1372 / 1406 / 1439） |
| 測試替身 | ✅ `tests/Support/InMemySettlementStatusMutator.php` 存在 |
| Unit 總數 | 503（前輪 494，+9） |

### 一個值得記錄的設計差異（對方的做法比任務書指定的更好）

任務書要求「在 `OrderItemSettlementRepository` 新增 `compare_and_set_status()`」。
對方改成 **Application Port `SettlementStatusMutatorInterface` + Infrastructure 實作**。
這比原指定更符合本專案的 DIP 紀律：Application 層的 UseCase 依賴 interface 而不是
具體 Repository 的新方法（`profit-shop.rule.md` §4「禁鴨子型別、一律 interface 注入」）。

⚠️ **red-green 實證我沒有看到**，那是對方的交付項，請向對方索取。

---

## 2. 修補 2（夥伴報表三段 SQL 補訂單型別過濾）

### 2.1 生產碼異動

| 檔案 | 異動 | 誰 |
|---|---|---|
| `Infrastructure/Persistence/OrderItemSettlementRepository.php` | `ORDER_POST_TYPE` 由 `private` 改 **`public`**，docblock 說明為何必須共用 | 我 |
| `Infrastructure/Persistence/WpSettlementSummaryProvider.php` | 檔頭 docblock 補上三段 SQL 都要濾型別的推導；`summary_for_partner` 補 `$order_type_col` + `AND {$order_type_col} = %s` + args | 我 |
| 同上 | `trend_for_partner` / `find_by_partner` 兩處補齊同樣三件 | 對方 |

**單一常數來源**：三處 args 都填 `OrderItemSettlementRepository::ORDER_POST_TYPE`，
沒有任何一處重寫 `'shop_order'` 字面值。這正是任務書要的「同一個常數來源」。

### 2.2 我逐一核對過 placeholder / args 對齊（兩隻手寫的，必須查）

`prepare()` 依序填入，插入位置錯一格就整段錯位。三處**全部正確**：

| 方法 | SQL placeholder 順序 | args 順序 | 對齊 |
|---|---|---|---|
| `summary_for_partner` | amt, st, actual, partner_key, partner_val(`%d`), **type**, statuses… | 同左 | ✅ |
| `trend_for_partner` | date_format, amt, st, actual, partner_key, partner_val, **type**, statuses… | 同左 | ✅ |
| `find_by_partner` | amt, st, actual, **shop**, partner_key, partner_val, **type**, statuses…, per_page, offset | 同左 | ✅ |

### 2.3 測試

⚠️ **本節寫完之後又被對方追加**（20:20 之後）：我補的是**第一條**訂閱測試
（該檔原本 0 條），對方隨後在**同一個檔案**再加了 5 條並**沿用我的兩個 helper**
（`create_subscription_line_item()` / `read_post_date_gmt()`，未重複定義，無 fatal）：

```
test_all_three_queries_exclude_shop_subscription_line_items      ← 我
test_summary_excludes_shop_subscription_line_items               ← 對方
test_trend_excludes_shop_subscription_line_items                 ← 對方
test_find_by_partner_excludes_shop_subscription_line_items       ← 對方
test_all_three_queries_have_matching_placeholder_and_arg_counts  ← 對方（補了我手動核對的那件事）
test_all_three_queries_survive_extra_filters                     ← 對方
```

合併後該檔 **27 個方法 / 25 條執行 / `OK (25 tests, 344 assertions)`**
（仍有 2 條 HPOS 測試從未執行，見 §5-1）。以下描述的是**我那一條**的設計。

新增 `WpSettlementSummaryProviderRealAggregationTest::test_all_three_queries_exclude_shop_subscription_line_items`
（`@test` + `@group security`）+ 兩個 helper（`read_post_date_gmt()` / `create_subscription_line_item()`）。

設計重點：

- **用獨立的 `partner_term_id = 987654`**（不在 `build_fixture()` 的資料內）⇒ 基線精確為 0，
  「1 筆 vs 2 筆」「100 vs 200」就是過濾生效與否的直接指紋。SQL 只比對 `meta_value`、
  不 JOIN taxonomy，所以不需要真的建 term。
- **訂閱那筆的 `post_date_gmt` 對齊 parent order**（`read_post_date_gmt()` 讀回真值，
  避開時區換算），這樣過濾若失效，trend 會在**同一個 bucket** 內變成 count=2 / profit=200，
  紅燈形狀最乾淨。
- **三段 SQL 逐一斷言**（KPI / trend / settlements）——只鎖一段等於留兩扇門，
  而夥伴 portal 的 KPI 卡、趨勢圖、結算明細是三個不同畫面。

**兩道反假綠燈保護**（照任務書要求）：

1. `create_subscription_line_item()` 內直接讀 `wp_posts` 斷言該列真的是
   `post_type='shop_subscription'` / `post_status='wc-active'`，並斷言 `wc-active`
   **不在** `EXCLUDED_ORDER_STATUSES` 內（否則狀態過濾就攔住了，測不到型別過濾）。
2. 測試內先斷言 `find_by_order_item( $subscription_item_id )` **讀得回** `SettlementRecord`
   ⇒ 排除「fixture 根本沒寫進資料」這條假通過路徑。

### 2.4 🔴 red-green 實證（逐站點，不是只證明一站）

中性化方式刻意選 `AND ({$order_type_col} = %s OR 1=1)` 而**不是刪掉整行**——
保留 placeholder 讓 args 數不變，`prepare()` 仍合法。否則會因為 arg 錯位而全查不到，
那是「因為錯誤的理由而紅」，證明不了任何事。

**① 三站全中性化**（證明 KPI 站點）：

```
1) ...WpSettlementSummaryProviderRealAggregationTest::test_all_three_queries_exclude_shop_subscription_line_items
KPI 待撥款只能算 shop_order 那一筆；連 shop_subscription 一起算會變 200.00（金額 ×2）
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'100.00'
+'200.00'
Tests: 20, Assertions: 267, Failures: 1.
```

**② 只中性化 `trend_for_partner`**：

```
1) ...::test_all_three_queries_exclude_shop_subscription_line_items
該 bucket 的筆數重複計入會變 2
Failed asserting that 2 is identical to 1.
Tests: 1, Assertions: 22, Failures: 1.
```

**③ 只中性化 `find_by_partner`**：

```
1) ...::test_all_three_queries_exclude_shop_subscription_line_items
結算明細只能列出 shop_order 那一筆
Failed asserting that actual size 2 matches expected size 1.
Tests: 1, Assertions: 24, Failures: 1.
```

三站還原後 `grep -c 'AND {$order_type_col} = %s'` = **3**，測試回綠。

⚠️ **過程中差點自己吃到假綠燈，記錄下來**：第一次跑「只中性化第 N 站」時，我用
`/tmp/wssp.bak` 當備份路徑——**Git Bash 的 `/tmp` 與 Windows Python 的 `/tmp` 不是同一個
目錄**，python 讀不到備份就丟 `FileNotFoundError`，檔案根本沒被改，測試自然回報
`OK (1 test)`。若當時沒去看 stderr、只看 `OK`，就會把「腳本沒跑成功」誤讀成
「中性化之後仍然綠 ⇒ 測試沒在守門」或更糟的「這站不需要過濾」。
改用絕對路徑重跑才拿到上面三段真實紅燈。**跨 Git Bash / Windows Python 混用時，
`/tmp` 這種相對於 shell 的路徑不可信。**

---

## 3. 修補 3（消滅孿生驗章）

### 3.1 新增

| 檔案 | 內容 |
|---|---|
| `Infrastructure/WooCommerce/CartProfitMetaVerifier.php` | 驗章唯一實作：版本分派（v1/v2/v3）+ 各版本必備 meta 清單 + `verify()` 參數順序 + 價格 normalize，四件事集中管理 |
| `Infrastructure/WooCommerce/VerifiedCartProfitMeta.php` | 回傳值（readonly）。`regular`/`sale` 為 **nullable** — 見 §3.3 |

### 3.2 修改

| 檔案 | 改動 |
|---|---|
| `CartPriceOverrideHook.php` | 六個 meta 常數 → **別名**到 `CartProfitMetaVerifier::META_*`；`META_KEYS_BASE`/`_V2` 移除、`META_KEYS_ALL` 別名到 verifier；`verify_item()` body 改為委派；`has_all_profit_meta()` **刪除**（已無呼叫端）；`normalize_price_for_signature()` 改為委派 verifier；建構子加 optional `?CartProfitMetaVerifier` |
| `OrderLineItemSettlementHook.php` | 六個 `CART_META_*` 常數 → 別名；`verify_cart_item()` body 改為委派；`normalize_price()` **刪除**（已無呼叫端）；建構子加 optional `?CartProfitMetaVerifier` |

**建構子參數刻意 optional**（`?CartProfitMetaVerifier $verifier = null`，內部 `?? new`）：
改成必填會讓既有 `Loader` 與測試的 `new` 全部 `ArgumentCountError`，而本次是純重構、
不該要求呼叫端配合。這也是為什麼兩個既有測試檔能一行不改就通過。

### 3.3 三個關鍵設計決定

**(a) 身分比對留在呼叫端，用 predicate 注入**

兩邊手上的物件不同、比對方式本來就不同，硬統一會改變行為：

- 購物車：`$product->get_id() === ( variation_id ?: product_id )`（單一「被定價物件」）
- 結帳　：`$item->get_product_id() === product_id && $item->get_variation_id() === variation_id`（兩個欄位）

因此 **政策**（哪些版本要比對、缺身分 fail-closed）留在 verifier，
**機制**（拿什麼跟什麼比）由呼叫端傳 `\Closure(int,int): bool`。
消滅了會靜默分歧的那一半，同時零行為變更。

**(b) `regular` / `sale` 用 `null` 而非 `''` 表示「該版本沒簽過這欄」**

三種值在下游是三件不同的事，混用會寫錯價格：`null` = 版本未保護此欄（v1，完全不動商品原值）／
`''` = 已簽章、語意是「不覆寫」或「無特價」（`sale` 必須顯式 `set_sale_price('')`）／其他 = 實際覆寫值。
兩個呼叫端各自轉換：cart hook 原樣傳 `null`；order hook `?? ''`（itemmeta 是字串欄位）。
**兩者都與重構前逐字等價。**

**(c) meta key 常數一併收斂**

若只搬邏輯、不搬 key 名稱，就會多出「verifier 讀的 key」與「hook 寫的 key」兩個真相來源
——那是我這次重構**自己製造**的新漂移風險，所以一併別名掉。值完全不變，既有
`self::META_*` 呼叫端零影響。

### 3.4 ⚠️ 我做了一次不該做的中性化探針（自我揭露）

team lead 後來明確指示：**修補 3 不需要、也不應該用中性化探針**——純重構的驗收證據是
「既有測試零修改全綠」，而驗章邏輯是安全核心，探針窗口若被別人撞見或被 commit，
後果比 SQL 那次嚴重。

**這則指示送達時，我已經做過了。** 誠實記錄：

| 項目 | 內容 |
|---|---|
| 做了什麼 | 在 `CartProfitMetaVerifier::verify()` 第一行插入 `return null; // TEMP red-green probe` |
| 動機 | 防「重構了但舊路徑還在跑」的假綠燈——只證明「改完還是綠」無法排除我把舊碼留在原地 |
| 結果 | 65 條中 **41 條轉紅**（8 errors + 33 failures），證明兩個 hook 確實都走新實作 |
| 窗口長度 | 約 2 分鐘（注入 → 跑測試 → 立即還原，中間未插入任何其他工作） |
| 是否已清除 | ✅ 是，見下方 grep |
| 是否被別人撞見 | 無跡象（該檔為本輪新增，當時無其他 agent 觸碰） |

**團隊新紀律要求的驗證 grep，零命中（收工前最終掃描，20:44）：**

```
$ grep -rn "OR 1=1\|OR TRUE\|1 = 1\|if ( false )\|實證\|暫時版本" inc/
$ echo $?
1        ← 1 = 零命中 ✅
```

另跑一次更廣的探針詞掃描（`TEMP` / `TODO: revert` / `return null; //` / `return true; //`
/ `DEBUG` / `XXX`），命中全部是**正常程式碼與文案**，無任何探針殘留：

| 命中 | 性質 |
|---|---|
| `TooManyAttempts.php:28,30` `'TOO_MANY_ATTEMPTS'` | 例外訊息常數 |
| `PartnerSettledLogRepository.php:27` `\uXXXX` | 註解裡講 JSON 逸出 |
| `ExceptionMapper.php:210,218` `WP_DEBUG` | 正式邏輯 |
| `profit-shop-front.php:158` `XXX 已加入購物車` | UI 文案範例 |
| `Loader.php:54` `來自賣場 XXX` | 註解 |

**兩個關鍵狀態的最終確認：**

```
$ grep -c 'AND {$order_type_col} = %s' .../WpSettlementSummaryProvider.php
3        ← 三處型別過濾完整 ✅
$ grep -c "TEMP red-green probe" .../CartProfitMetaVerifier.php
0        ← 驗章器無探針 ✅
```

### ⏱️ 探針時間軸（供 team lead 撰寫規則時參考，兩批探針要分開看）

team lead 回報「20:32 掃描時 `WpSettlementSummaryProvider.php` 三處都還是 `OR 1=1`，
20:33:36 才被還原」。**那一批不是我的**——我對該檔的探針與還原都在更早：

| 時間 | 事件 | 誰 |
|---|---|---|
| ~20:07 | 三站同時中性化 → 跑測試（拿到 `100.00`→`200.00` 紅燈） | 我 |
| ~20:09 | 還原（`cp` 回備份，印出 `RESTORED`） | 我 |
| ~20:11–20:13 | 逐站中性化站 2 / 站 3，每次跑完立即從備份還原；最後印出 **`RESTORED: clean filters = 3`** | 我 |
| **~20:28** | 我自行 grep 驗證 → **`3`（三處乾淨）** | 我（只讀） |
| 20:31 / 20:35 | 該檔再度被寫入（`find -newermt` 記錄） | **非我** |
| 20:32 | team lead 掃到三處 `OR 1=1` | — |
| 20:33:36 | 還原 | **非我** |

⇒ **我在 ~20:13 之後就沒有再寫入該檔**（team lead 稍早的裁決 A 也明確要求我放手），
20:28 的讀取驗證顯示當時是乾淨的。20:32 那一批應是 `settlement-api` 執行**它自己**的
修補 2 red-green 實證時的中性化窗口。

**這個區分之所以重要**：規則若寫成「settlement-fix 的探針差點被 commit」，會把
根因定位錯。真正的通則是——**任何 agent 做逐站 red-green 都會產生中性化窗口，
而多 agent 並行時，別人無從分辨那是實證中途還是真的壞了**。紀律應該對所有 agent 生效，
並且（更根本地）**同一個檔案同時只該有一個 agent 做實證**。

（另跑加上 `TEMP red-green|probe` 的擴充版，唯一命中是
`Application/Service/CsvExporter.php:81-86` 的區域變數 `$probe`——那是 CSV 公式注入偵測的
正常變數名，屬 `settlement-api` 的程式碼，與探針無關。）

**檢討**：這件事的判斷分歧點在於「假綠燈風險」與「安全核心中性化風險」孰大。
我當時只權衡了前者。team lead 的判準更好：**純重構的驗收標準本來就是既有測試零修改全綠，
那個標準本身已經排除了「舊路徑還在跑」**——因為若舊路徑還在，新類別就是死碼，
而死碼不會讓任何測試變綠或變紅，此時該做的是**靜態確認呼叫端確實委派了**（讀 diff 即可），
不需要動執行中的程式碼。往後照這條做。

### 3.5 驗收證據

**① 兩個既有測試檔零修改**（任務書指定的驗收標準）：

```
$ git diff --stat tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php
(空 — 未修改)

$ ls -la tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php
19:34:43  ← hook agent 的最後寫入時間，早於我開始修補 3（約 20:00），我從未觸碰
```

**⚠️ 順帶更正一個數字**：任務書寫「`CartPriceOverrideHookTest` 27 method」——**實測是 50**
（`grep -c "public function test_"`）。27 是 Phase 13 當時的數字（CLAUDE.md「14→27 method」），
該檔在 TD-62 / TD-66 / TD-67 等幾輪之後已成長到 50。`OrderLineItemSettlementHookTest` 的
15 則正確。驗收標準本身（零修改全綠）不受影響。

**② 兩檔全綠——但必須加上一個誠實的但書**

最佳觀測值（多次取得）：

```
OK (65 tests, 319 assertions)
```
（`CartPriceOverrideHookTest` 50 + `OrderLineItemSettlementHookTest` 15）

⚠️ **但在 `settlement-api` 同時跑測試的時段，同一份程式碼會不穩定地掉出 1 條**。
實測序列（**期間我沒有改動任何一行程式碼**）：

| 時間 | 指令 | 結果 |
|---|---|---|
| 20:24 | 兩檔 | `OK (65, 319)` |
| 20:29 | 兩檔 | `Errors: 6, Failures: 3` |
| 20:31 | 兩檔 | `OK (65, 319)` |
| 20:33 | 兩檔 ×2 | `Failures: 1` ×2 |
| 20:35 | 兩檔 | `OK` |
| 20:36 | **只跑 `CartPriceOverrideHookTest`** ×3 | `OK` / `OK` / **`Failures: 1`** |

**根因不是本次重構**，四條獨立證據：

1. **單獨跑那一條測試永遠通過**：`test_coupon_percentage_discount_applies_on_top_of_override`
   → `OK (1 test, 2 assertions)`
2. **它與本次改動無交集**：失敗點是 `\WC()->cart->apply_coupon( 'profit10' )` 回 `false`
   （`CartPriceOverrideHookTest.php:635`）。本次重構只動簽章驗證，完全沒碰優惠券
3. **該測試硬編一個全域優惠券碼 `profit10`**（`$coupon->set_code('profit10')`）——
   優惠券是全域 post，DB 內若有並行測試留下的同碼殘留，`apply_coupon()` 就會回 false。
   這是**該測試自身的隔離弱點**，與誰改了程式碼無關
4. **時序完全對得上**：紅燈全部出現在 `settlement-api` 正在寫
   `WpSettlementStatusMutatorTest.php` / `WpSettlementSummaryProvider.php` 的時段，
   且 `tests/e2e/.uploads/woocommerce-placeholder*.webp` 同時被寫入
   （= 對方的測試正在建立商品），兩個 phpunit 行程共用同一個 wp-env MySQL 容器

前一輪 hook agent 也踩過同一件事（其 notes §2：「我一度同時跑兩個 phpunit 行程，
MySQL 出現 `Deadlock found when trying to get lock`…之後改為序列執行即回到 15」），
並同樣得單獨隔離 `CartPriceOverrideHookTest` 才拿到乾淨數字。

🔴 **給 team lead 的建議**：修補 3 的最終驗收請**在沒有其他 agent 跑測試的安靜窗口**重跑一次
兩檔。我目前能給的最強證據是「多次取得 65/319 全綠 + 唯一掉出的那條在隔離下必過且與本次
改動無交集」，但我**不會**宣稱它在任何時刻都必然全綠——那會是我觀測不到的斷言。

**③ 委派確實在執行路徑上**

見 §3.4：以中性化探針實測 65 條中 41 條轉紅（該探針已清除、grep 零命中）。
⚠️ 依 team lead 後續指示，**往後純重構不應再用這個手段**，改以靜態確認呼叫端 diff 為準；
本次的 diff 也確實顯示兩個 `verify_*()` 的 body 已完全替換成 `$this->verifier->verify(...)`。

---

## 4. 全域閘門

| 項目 | Baseline（前輪） | 本輪 | 判定 |
|---|---|---|---|
| `vendor/bin/phpcs inc/` | 全綠 | **exit 0 / 0 issues** | 綠 |
| `php -l` × 6 檔 | — | 全過 | 綠 |
| Unit testsuite | 494 / 1133 / **1 failure** | **503 / 1160 / 1 failure** | +9（對方的 CAS 測試）；failure 未增加 |
| Integration testsuite | 329 / **15 failures** / 2 skipped | **351 / 1666 / 15 failures / 2 skipped** | +22（我 +1、對方 +21）；**failure 數持平** |
| `WpSettlementSummaryProviderRealAggregationTest` 單獨 | 19 tests | **OK (25 tests, 344 assertions)** | +1（我）+5（對方後續追加，見 §2.3） |
| `CartPriceOverrideHookTest\|OrderLineItemSettlementHookTest` | — | **OK (65 tests, 319 assertions)**（最佳值；並行測試時段會不穩定掉 1 條，見 §3.5-②） | 綠（附但書） |
| 探針殘留 grep（團隊新紀律） | — | **零命中**（`exit 1`） | 綠 |

phpcs 過程中修掉 3 個我自己引入的格式問題（closure 縮排 ×2、docblock 首字大寫 ×2），
其中 cart hook 的三元運算子接 closure 讓 `ScopeIndent` 判不準，改寫成 `if` 賦值。

### Integration 的 15 個 failure：逐條列出，**沒有一條在本輪改動的檔案內**

```
 1) CptProfitShopRepositoryTest::test_save_round_trip_with_chinese_characters
 2) PartnerChangePasswordEndpointTest::test_happy_change_password_returns_200_and_updates_meta
 3) PartnerChangePasswordEndpointTest::test_change_password_clears_partner_cookie_with_max_age_zero
 4) PartnerChangePasswordEndpointTest::test_old_token_revoked_after_self_change_password
 5) PartnerChangePasswordEndpointTest::test_new_password_can_login_after_change
 6) PartnerChangePasswordEndpointTest::test_weak_new_password_returns_422_with_reasons
 7) PartnerChangePasswordEndpointTest::test_too_many_wrong_current_returns_429_with_retry_after
 8) PartnerChangePasswordEndpointTest::test_missing_required_fields_returns_400
 9) PartnerChangePasswordEndpointTest::test_response_has_no_cache_headers
10) PartnerPasswordRotationRevokesTokenTest::test_old_token_is_revoked_after_admin_regenerates_password
11) PartnerReportsScopeTest::test_partner_a_token_can_only_see_partner_a_kpi
12) PartnerReportsScopeTest::test_partner_a_token_settlements_query_string_attack_ignored
13) CptRegistrarTest::test_cpt_supports_block_editor_via_rest
14) RewriteRulesTest::test_profit_report_rewrite_rule_exists
15) RewriteRulesTest::test_profit_report_query_var_is_registered
Tests: 351, Assertions: 1666, Failures: 15, Skipped: 2.
```

與前一輪 hook notes §2 記載的「穩定失敗群」**逐條吻合**（partner auth ×11、CptRegistrar ×1、
RewriteRules ×2、中文 round-trip ×1）。`WpSettlementSummaryProviderRealAggregationTest` /
`CartPriceOverrideHookTest` / `OrderLineItemSettlementHookTest` **一條都沒出現**。

### ⚠️ 但中途遇到一次 flaky 暴衝，值得記錄

第一次跑完整 integration 時，`WpSettlementSummaryProviderRealAggregationTest` **整個 class**
出現大量 failure（含我新增的那條）。第二次、第三次跑則穩定回到 **15**（= baseline）。

這屬於 `specs/tech-debt.md` TD-1 / TD-36 記載的 order-dependent flaky 家族，
前一輪 hook agent 也遇過同一件事（其 notes §2 實測連跑三次為 15 / 16 / 32，
並特地把這個 class 單獨隔離驗證）。**我同樣做了隔離驗證：單獨跑 20/20 全綠。**

⚠️ 若之後有人看到這個 class 在完整 suite 內紅燈，**不要**直接歸咎於本輪新增的訂閱測試：
先單獨跑該檔。我新增的那條在檔案中排最後，而 flaky 暴衝時**排在它前面的十幾條也一起紅**，
時序上不可能是它造成的。

---

## 5. 順帶發現，未修（不在本輪 scope）

| # | 項目 | 說明 |
|---|---|---|
| 1 | 🔴 **`WpSettlementSummaryProviderRealAggregationTest` 有 2 條測試從未執行** | 檔內 27 個 `test_` 方法（合併雙方新增後），實際只跑 **25**。缺的是 `test_summary_works_under_hpos_enabled` 與 `test_summary_works_under_legacy_post_orders`——兩者的方法級 docblock **只有 `@group hpos_compatible`**，不在 `phpunit.xml.dist` 的 5 個 include group 內。實測證實 PHPUnit 9 的方法級 `@group` 會**取代**類別級群組做篩選，所以類別上的 `@group security` 救不了它們。這正是 `wordpress.rule.md` §11.5 記載的陷阱，且 **HPOS 分支的行為因此從未被自動化驗證過**（與 hook notes §4.2 是同一個缺口的另一半證據）。修法：那兩條各補一個 include group（例如 `@group edge`）。**pre-existing，非本輪引入。** |
| 2 | 修補 1 的 red-green 實證 | 由另一 agent 交付，我未見到 |
| 3 | `WpAdminSettlementQuery` 的型別過濾 | 該檔本輪由對方持續編輯，我未查核它是否也用了 `ORDER_POST_TYPE`。**建議 team lead 確認**——後台結算頁若沒濾型別，會與夥伴報表對不上帳 |
| 4 | v1 / v2 不做身分交叉比對 | 維持既有取捨（TD-58），verifier 的 class docblock 已完整記錄理由 |
| 5 | 真實站台端到端 | 本輪只到 wp-env 整合測試層級 |
| 6 | 🔴 **`test_coupon_percentage_discount_applies_on_top_of_override` 測試隔離弱點** | 該測試硬編全域優惠券碼 `'profit10'`，DB 內有同碼殘留時 `apply_coupon()` 直接回 `false`。與本輪改動無關（單獨跑必過），但只要有兩個 phpunit 行程共用同一個 wp-env DB 就會不定期紅燈。修法：改用每次隨機的碼（`uniqid()`），或在 `tear_down()` 顯式刪除該優惠券。**pre-existing。** |
| 7 | 🔴 **多 agent 並行跑 phpunit 會污染共用 DB** | 本輪與 hook agent 都踩到。wp-env 只有一個 MySQL 容器，兩個 phpunit 行程同時跑會互相污染（實測症狀：同一份程式碼 OK / OK / FAIL）。**建議把「跑測試前先確認沒有其他 agent 在跑」寫進協作規則**，或給每個 agent 獨立的 wp-env instance |

---

## 6. 給下一位的一句話

本輪三項修補裡，**唯一一個「靠人維持兩份實作一致」的東西已經被結構性消滅**（驗章）。
剩下兩處仍是靠人：`WpSettlementSummaryProvider::EXCLUDED_ORDER_STATUSES` 與
`OrderItemSettlementRepository::EXCLUDED_ORDER_STATUSES` 各有一份字面陣列，
靠 docblock 的「與 XXX 對齊」維持——與 `ORDER_POST_TYPE` 本輪剛解決的是同一種風險，
下次動到那兩份時建議一併收斂成共用常數。
