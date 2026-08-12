# 分潤結算 Application 層 + 7 個 REST endpoint — 實作紀錄

> 日期：2026-08-06
> 分支：`refactor/one-page`
> 規格權威：`specs/features/profit-shop/query-settlements.feature`、`export-settlements-csv.feature`（commit `70a704f`）
> 設計文件：`specs/2026-05-06-profit-shop-design.md` §3.5 / §4.3 / §5.5 / §6.1 / §6.9 / §7.5
>
> ⚠️ 本檔已依 team lead 的**兩則更正訊息**改寫（併發防護改 CAS、SQL 型別過濾、D-5/D-6、G-1~G-3）。
> 讀到「原本用 read-check-write」「原本回 `content`」「原本 GET 走 manage_woocommerce」的舊敘述，一律以本檔為準。

---

## 1. 更正落實情況（對照 team lead 的兩則訊息）

| 項目 | 狀態 | 落點 |
|---|---|---|
| 併發防護改 **條件式 UPDATE（CAS）** | ✅ | `SettlementStatusMutatorInterface` + `WpSettlementStatusMutator` + 三個 UseCase |
| CAS 後手動清 meta 快取 | ✅（⚠️ 群組名更正，見 §3.2） | `WpSettlementStatusMutator::flush_item_meta_cache()` |
| 併發 `@group security` 測試 | ✅ | Unit ×5 + IT `WpSettlementStatusMutatorTest`（真實 SQL，10 條） |
| **red-green 實證（三項修補各一次）** | ✅ | §4，含探針清除掃描 |
| `WpSettlementSummaryProvider` 三段 SQL 補訂單型別過濾 | ✅（⚠️ 過程中被 `OR 1=1` 中和過一次，已修，見 §4） | `:170` / `:295` / `:396` |
| D-5：CSV 回 `content_base64` | ✅ | `SettlementExportOutput::from_csv()` |
| D-6：新增第 7 個端點 `order-ids`，cap 200 + 截斷回報 | ✅ | `ListSettlementOrderIdsUseCase` |
| G-1：摘要欄位名 snake_case + `refunded_count` | ✅（原本就吻合） | `SettlementAdminSummaryOutput` |
| G-2：CSV 9 欄，順序與標題逐字對齊規格 | ✅（**由 11 欄改為 9 欄**） | `ExportSettlementsUseCase::HEADER` |
| G-3：`[已停用夥伴]` / `[已刪除賣場]` | ✅ | `WpAdminSettlementQuery::DELETED_*_LABEL` |
| `_profit_rate = 0` 不得被過濾掉 + 標示待人工處理 | ✅ | 列表項新增 `needs_manual_rate` |

### 我另外發現並一併修掉的（更正訊息未提及）

1. **`WpAdminSettlementQuery` 自己也缺訂單型別過濾**。更正訊息只點名 `WpSettlementSummaryProvider`，
   但後台列表 / 摘要 / 匯出 / order-ids 走的是我新建的這個類別，同一個雙倍計算缺陷完全一樣存在。
   四個查詢全數補上，且 `ORDER_TYPE` 直接取 `OrderItemSettlementRepository::ORDER_POST_TYPE`
   常數而非各自寫死字串——這是安全過濾條件，任一處漂移都會讓那條路徑金額變兩倍且毫無徵兆。
2. **權限比原指令更嚴**：規格明文斷言「僅具商店管理權限」→ 403，**讀寫皆然**。
   我原本依你第一則訊息把 GET 留在 `manage_woocommerce`，現已全部改為 `manage_options`（見 §3.1）。
3. **列表欄位缺 `partner_name` / `shop_name`**：規格的「每一列包含結算所需的欄位」列了名稱而非 ID。已補（ID 也保留，因為篩選的 Example 斷言了 `shop_id`）。

---

## 2. 檔案清單

### 產品程式碼（新增 20 檔）

| 層 | 路徑 |
|---|---|
| Application / DTO | `SettlementFilterInput` / `SettlementAdminSummaryOutput` / `SettlementBatchOutput` / `SettlementExportOutput` / `SettlementOrderIdsOutput` |
| Application / Port | `SettlementQueryProviderInterface` / `SettlementAuditLogInterface` / **`SettlementStatusMutatorInterface`** |
| Application / Service | `SettlementAuditRecorder` / `CsvExporter` |
| Application / UseCase | `ListSettlementsUseCase` / `GetSettlementSummaryUseCase` / `MarkSettlementsPaidUseCase` / `MarkSettlementsCancelledUseCase` / `ReversePaidSettlementsUseCase` / `ExportSettlementsUseCase` / **`ListSettlementOrderIdsUseCase`** / `Support/SettlementBatchGuard` |
| Infrastructure | `WpAdminSettlementQuery` / **`WpSettlementStatusMutator`** / `PartnerSettledLogRepository` |

### 產品程式碼（修改 2 檔）

- `Infrastructure/Rest/V2Api.php`：7 個 route + 7 個 callback + 4 個 helper
- `Infrastructure/Persistence/WpSettlementSummaryProvider.php`：三段 SQL 補訂單型別過濾

⚠️ **`OrderItemSettlementRepository.php` 我完全沒有動**（依你的分工要求）。CAS 寫在獨立的
`WpSettlementStatusMutator`，若你要整併回 Repository 的 `compare_and_set_status()`，
介面 `SettlementStatusMutatorInterface` 不需改，只要換 DI 的實作即可。

### 測試（新增 12 檔）

| 類型 | 檔案 | `test_` 方法數 |
|---|---|---|
| Support fake | `InMemorySettlementRepository` / `InMemorySettlementAuditLog` / `InMemorySettlementQueryProvider` / **`InMemorySettlementStatusMutator`** | — |
| Unit | `CsvExporterTest` | 10 |
| Unit | `SettlementFilterInputTest` | 14 |
| Unit | `MarkSettlementsPaidUseCaseTest` | 17 |
| Unit | `MarkSettlementsCancelledUseCaseTest` | 12 |
| Unit | `ReversePaidSettlementsUseCaseTest` | 13 |
| Unit | `ListSettlementsUseCaseTest` | 9 |
| Unit | `ExportSettlementsUseCaseTest` | 15 |
| IT | `ProfitSettlementsEndpointTest` | 38 |
| IT | **`WpSettlementStatusMutatorTest`**（真實 SQL 的 CAS） | 9 |

### 規格

`specs/api/api.yml`：7 個 path + 8 個 schema（新增 `SettlementOrderIdsOutput`；
`SettlementExportOutput` 改 `content_base64`；`AdminSettlementListItem` 加
`partner_name` / `shop_name` / `needs_manual_rate`）。

---

## 3. 關鍵決策

### 3.1 權限：七個端點一律 `manage_options`

規格兩份 feature 都明文斷言「僅具商店管理權限」→ 403，讀寫皆然。
理由寫在 feature 檔頭：結算頁緊鄰「標記已撥款」這個不可逆的金流動作，
讓 shop_manager 看得到金額卻按不了按鈕只會讓人繞路（截圖、口頭轉述），整頁同一個邊界才一致。

⚠️ 這比設計文件 §6.1 的 `manage_woocommerce` 嚴格。**兩份文件不一致，我以 feature 為準**
（較新、且是可執行的驗收條件）。若你認為應以 §6.1 為準，改回只需動 `$apis` 陣列與兩條測試。

三個 mutation 另外要求 `X-WP-Nonce`：capability 擋「你是誰」、nonce 擋「這請求是不是你自己發的」。

### 3.2 CAS 實作：`$wpdb->update` 的 WHERE 帶 `meta_value = $from`，以 affected rows 判定

```php
$affected = $wpdb->update(
    $wpdb->prefix . 'woocommerce_order_itemmeta',
    [ 'meta_value' => $to ],
    [ 'order_item_id' => $id, 'meta_key' => '_settlement_status', 'meta_value' => $from ],
    [ '%s' ], [ '%d', '%s', '%s' ]
);
if ( false === $affected || $affected < 1 ) { return false; }   // fail-closed
```

**三個實作細節：**

1. **`false` 與 `0` 都回 `false`**。`$wpdb->update()` 失敗回 `false`、成功回受影響列數；
   把 DB 錯誤誤判成成功就是重複撥款。
2. **`$from === $to` 直接拒絕**。MySQL 對「值沒變」回報 0 affected rows，與「沒搶到」無法區分。
3. **🔴 快取群組名是 `order_item_meta`，不是 `order_itemmeta`**。
   你訊息裡寫的 `order_itemmeta` 是**資料表後綴**，`wp_cache_delete()` 用它會**安靜地什麼都不做**
   （它不會回報「這個群組不存在」）。正解推導：WP metadata API 用 `$meta_type . '_meta'` 當群組
   （`wp-includes/meta.php:145`），而 WC 的 order item meta type 是 `order_item`
   （`class-wc-order-item-data-store.php:93`）⇒ `order_item_meta`。
   另外也補了 `WC_Cache_Helper::invalidate_cache_group( 'object_' . $id )`，
   因為 `wc_update_order_item_meta()` 自己也會清那個群組（`wc-order-item-functions.php:107`）。
   IT `test_cache_is_invalidated_so_wc_getter_sees_new_value` 鎖住這件事
   （先讀一次灌快取 → CAS → 再讀必須是新值）。

**Domain 狀態機沒有被 CAS 取代，兩者分工不同**（順序不可調換）：

| # | 動作 | 負責 | 失敗時 |
|---|---|---|---|
| 1 | `find_by_order_item()` | 取金額 / partner + 確認存在 | skipped「找不到」 |
| 2 | `$record->mark_paid()` | **Domain 規則**：轉換合不合法 | skipped（Domain 訊息） |
| 3 | `compare_and_set()` | **併發**：誰搶到 | skipped「未重複計入」 |

少了第 2 步，狀態轉換規則會散落成各 UseCase 的字串常數；少了第 3 步，就是重複撥款。

**`_settled_at` / `_settled_by` 只有搶到的那一方寫**——那是「誰在何時撥的款」的唯一證據。

**狀態 meta 不存在時 fail-closed**：Repository 對缺 `_settlement_status` 的紀錄預設回 `pending`，
但 CAS 的 WHERE 對不存在的資料列必然 0 rows ⇒ 這種紀錄永遠被跳過。刻意如此：
要讓它成功就得補 INSERT，而「先查再插」同樣是 read-check-write，等於把剛消滅的競態換個地方長回來。
少撥一筆（站長看得到跳過原因）遠優於重複撥款。

### 3.3 CSV：9 欄、`content_base64`、無業務筆數上限

- **欄位順序與標題逐字取自 `export-settlements-csv.feature`**（`ExportSettlementsUseCase::HEADER`
  的 docblock 有註明「改動前先改規格」）。夥伴 / 賣場欄輸出**名稱**而非 ID。
  訂單日期取 `Y-m-d`（規格資料列範例是純日期）。
- **base64**：內容開頭有 UTF-8 BOM、中間有 CRLF 與引號跳脫，這些位元組在 JSON 編解碼與
  各層 proxy 的字元集轉換中都可能被改動，而 BOM 少一個位元組 Excel 就會用系統預設編碼開啟、
  中文整片亂碼。
- **⚠️ 移除了原本的 5000 列上限與 `truncated` 欄位**：規格「已知落差」明訂
  「系統一次產出整份檔案，不提供背景排程或分批下載」。保留靜默上限與規格直接衝突。
  現在只剩 `MAX_PAGES = 10000` 作為**迴圈保險絲**（防 Provider 行為異常時無限迴圈），
  不是給站長的配額。**已知取捨見 §5.1。**
- 三個可輸入欄位（商品 / 夥伴 / 賣場）都跳脫——`CsvExporter::build()` 對每一格無差別中和，
  所以結構上不會漏；UseCase docblock 仍記下這個理由，避免日後增修欄位時忘記。

### 3.4 為何 `WpAdminSettlementQuery` 獨立於 `WpSettlementSummaryProvider`

`WpSettlementSummaryProvider` 每個 public 方法開頭都有 `if ( $partner_term_id <= 0 ) return empty;`
——「partner 範圍鎖死」是它的**類別級不變量**，也是 `profit-shop.rule.md` §7 的落點。
把「partner 可為空」的 admin 查詢塞進同一個類別等於在那個不變量上開洞：
日後只要有人在 partner 端點誤用 admin 方法，跨 partner 越權就成立，
而**型別檢查與既有 21 條測試一條都不會紅**。兩個 Port 介面也因此刻意分開。

複用的是 SQL 模式（HPOS 雙路徑 / prepare / 白名單 / bcadd），不是類別。

---

## 4. 🔴 red-green 實證（三項修補各一次，含探針清除驗證）

「測試綠燈」不等於「守門有在守」——測試本身可能量錯東西（CLAUDE.md Pitfall #34）。
以下三項都實際把守門中性化、確認測試轉紅、再還原重跑。

### RG-1　CAS 的守門確實寫在 SQL 的 WHERE 裡

**中性化**：`WpSettlementStatusMutator::compare_and_set()` 的 `$wpdb->update()` WHERE 陣列
拿掉 `'meta_value' => $from`（= read-check-write 在 SQL 層的形狀：無條件 UPDATE）。

```
✘ Cas guard lives in sql where clause not in php
  現況是 cancelled，CAS 的 WHERE meta_value = pending 應命中 0 列
  Failed asserting that true is false.
Tests: 10, Assertions: 28, Failures: 1.
```

還原後：`OK (10 tests, 29 assertions)`。

🔴 **本次最重要的發現：其餘 9 條全部維持綠燈**，包括
`test_second_cas_with_same_from_loses_the_race` 與（unit 層的）
`test_running_same_batch_twice_does_not_double_count`。
這**實測證實**了 team lead 的判斷：**循序呼叫兩次的測試證明不了 CAS 有在守門**
——read-check-write 在第二次會讀到 `paid`、比對 `pending` 不符、一樣回 false。

因此新增了 `test_cas_guard_lives_in_sql_where_clause_not_in_php`，關鍵在
**現況刻意設為 `cancelled`（既不是 `from` 也不是 `to`）**：

| 現況 | from | to | CAS | 無條件 UPDATE |
|---|---|---|---|---|
| paid | pending | paid | 0 rows → false | 值沒變 → 0 rows → false（**測不出來**） |
| **cancelled** | pending | paid | 0 rows → false | 值改變 → 1 row → **true（露餡）** |

⚠️ 該測試的 docblock 已寫明「不要把 `cancelled` 改成 `paid` 讓它跟其他測試一致」——
那會讓它退化成測不出任何東西的假綠燈。

### RG-2　UseCase 確實把決定權交給 CAS，而不是自己在 PHP 裡判斷

**中性化**：`MarkSettlementsPaidUseCase` 把 `compare_and_set()` 換成
「Domain 已檢查過 → 直接 `$this->settlements->save( $record )`」。

```
Tests: 17, Assertions: 49, Failures: 6.

1) test_pending_records_are_marked_paid
2) test_transition_goes_through_compare_and_set
3) test_losing_the_race_skips_the_record_and_writes_nothing
4) test_record_without_status_meta_is_skipped
5) test_duplicate_ids_are_processed_once
6) test_audit_failure_is_reported_without_rolling_back_status
```

還原後：`OK (17 tests, 59 assertions)`。

### RG-3　`WpSettlementSummaryProvider` 三段 SQL 的訂單型別過濾

**中性化**：`AND {$order_type_col} = %s` → `AND ({$order_type_col} = %s OR 1=1)`
（**保留 placeholder**；直接刪掉那行會讓 placeholder 少一個、`prepare()` 回空字串，
測到的會是「SQL 壞了」而不是「過濾失效」，紅燈意義完全不同）。

連跑兩次，結果一致：

```
Tests: 5, Assertions: 65, Failures: 3.

✘ Summary excludes shop subscription line items
  訂閱物件上的品項不得被計入——變成 700 就是「訂閱商品分潤 ×2」的具體形狀
  -'500.00'
  +'700.00'
✘ Trend excludes shop subscription line items      Failed asserting that 5 is identical to 4.
✘ Find by partner excludes shop subscription line items  actual size 5 matches expected size 4.
```

`500 → 700`、`4 → 5` 正是「訂閱物件多算一筆」的具體形狀。還原後全綠。

### 🔴 探針清除驗證（每次 red-green 結束後必做）

red-green 的中性化探針若被遺留，等於把缺陷完整還原，而且**比原本更難發現**——
SQL 表面上還有 `AND ... = %s`、docblock 還寫著「已過濾」、`phpcs` 全綠，
除了那一條專門測它的測試之外沒有任何徵兆。本次實際發生過（見 §5）。

因此紀律是：**任何 red-green 結束後，必須跑一次探針掃描並把零命中結果貼進 notes**。

```console
$ grep -rnE "OR 1=1|OR 1 = 1|OR TRUE|1 *= *1|if \( false \)|RED-GREEN 探針" inc/
ZERO MATCHES
```

三個中性化點的還原也逐一確認（數量對得上才算還原完成）：

```console
CAS WHERE meta_value        : 1   （WpSettlementStatusMutator）
UseCase compare_and_set     : 3   （MarkSettlementsPaidUseCase：import + 呼叫 + docblock）
SummaryProvider type filter : 3   （KPI / trend / settlements）
AdminQuery type filter      : 6   （list / count / summary / order-ids / count_order_ids）
```

### 反假綠燈保護（新增測試皆內建）

`seed_subscription_line_item()` helper 內含兩道，缺任一道，過濾被拿掉也會永遠綠：

1. 直接讀 `wp_posts` 斷言該列真的是 `post_type='shop_subscription'` / `post_status='wc-active'`
   （若 factory 因故建出 `shop_order`，測試會「通過」但測到的是錯的東西）
2. 斷言 `_profit_partner_term_id` 與 `_profit_amount` **確實寫進去了**
   （排除「fixture 根本沒寫入」這個假通過路徑）

另有兩條 `@group edge` 專門守 `prepare()` 的 placeholder/args 一致性
（`test_all_three_queries_have_matching_placeholder_and_arg_counts` /
`test_all_three_queries_survive_extra_filters`）：
`$wpdb->prepare()` 在數量不符時**回空字串** ⇒ 查詢回 0 筆 ⇒ 夥伴報表整片歸零，
而 `phpcs` 與 `phpunit` 都不會紅。這兩條對三個方法各跑一次真實查詢並斷言回傳非空。

---

## 5. 🔴 過程中發現的事故：型別過濾被 `OR 1=1` 中和

補完 `WpSettlementSummaryProvider` 三段 SQL 之後，稍晚重跑 phpcs 時發現三處都變成：

```sql
AND ({$order_type_col} = %s OR 1=1)
```

`OR 1=1` 使條件恆真 ⇒ **訂閱商品雙倍計算的缺陷被完整還原**，而 SQL 表面上仍有過濾、
docblock 也還寫著「已過濾」。這正是本次要防的那種「靜默失效、零錯誤訊息」缺陷。

已改回 `AND {$order_type_col} = %s`；`grep -rn "OR 1=1" inc/` 目前在產品程式碼**零命中**
（`tests/` 的命中都是合法的 SQL injection 測試 payload）。已即時回報 team lead。

**來源已釐清（team lead 回覆）**：那是另一個 agent 正在跑 red-green 的**中途快照**，
不是惡意也不是遺留死碼。`AND (... = %s OR 1=1)` 正是中性化的標準做法——保留 placeholder
只讓條件恆真，才測得出「過濾失效」而不是「SQL 壞了」（我自己在 §4 RG-3 用的也是同一手法）。

⚠️ **但這件事本身暴露一個真實風險**：中性化探針若被遺留，等於把缺陷完整還原，
而且比原本更難發現。因此 §4 末尾那條「red-green 結束後必須跑探針掃描並貼出零命中結果」
的紀律是本次最重要的流程產出，已請 team lead 寫進專案規則。

分工已由 team lead 收斂：該檔全歸我，另一個 agent 已完全退出。
根因是 team lead 的協調失誤（同一範圍寫進兩份任務書），不是任何一方的問題。

---

## 6. 測試結果

指令一律透過 wp-env 容器執行（`wordpress.rule.md` §11.3）。

| 層級 | Baseline（動工前實測） | 完成後 | 判定 |
|---|---|---|---|
| Unit | 413 / 882 / **1 failure** | **503 / 1160 / 1 failure** | +90 tests，failure 未增加（TD-2 pre-existing） |
| Integration | 286 / 1298 / **15 failures** / 2 skipped | **359 / 1759 / 15 failures / 3 skipped** | +73 tests，failure 未增加，且**逐條與 baseline 同名**（見下方清單） |
| `vendor/bin/phpcs inc/` | — | **164 / 164 全綠**（0 error / 0 warning） | 全綠 |

15 條 failure 逐條核對，**沒有一條落在本次改動的檔案內**：
`CptProfitShopRepositoryTest::test_save_round_trip_with_chinese_characters` ×1、
`PartnerChangePasswordEndpointTest` ×8、`PartnerPasswordRotationRevokesTokenTest` ×1、
`PartnerReportsScopeTest` ×2、`CptRegistrarTest` ×1、`RewriteRulesTest` ×2
——即 `specs/tech-debt.md` TD-1 / TD-36 記載的既有失敗家族。

第 3 個 skip 是我新增的 `WpSettlementSummaryProviderRealAggregationTest::test_summary_works_under_hpos_enabled`
所在檔案的既有 HPOS 守門（`markTestSkipped`，本機 HPOS 未啟用），屬**設計上的 skip**，非靜默跳過。

⚠️ **測試數的歸屬**：+90 / +65 之中包含平行任務（`settlement-hook` 等）的新增測試，
不全是本任務的。本任務自己的是 Unit 90 條（10+14+17+12+13+9+15）+ IT 47 條（38+9）。

⚠️ **一次假警報**：中途有一次 integration 跑出 36 failures + 2 errors，
重跑兩次都回到 15。原因是其他 agent 在該次執行期間正在寫入同一批檔案。
**多 agent 平行作業時，單次測試結果不可信，要重跑確認。**

### `@group` 覆蓋驗證（`wordpress.rule.md` §11.5，防靜默跳過）

`--filter XxxTest --testdox` 執行筆數 == 檔內 `test_` 方法數，**9 個檔案全數吻合**：

| 檔案 | `test_` 方法數 | testdox 執行數 |
|---|---|---|
| `CsvExporterTest` | 10 | 10 |
| `SettlementFilterInputTest` | 14 | 14 |
| `MarkSettlementsPaidUseCaseTest` | 17 | 17 |
| `MarkSettlementsCancelledUseCaseTest` | 12 | 12 |
| `ReversePaidSettlementsUseCaseTest` | 13 | 13 |
| `ListSettlementsUseCaseTest` | 9 | 9 |
| `ExportSettlementsUseCaseTest` | 15 | 15 |
| `ProfitSettlementsEndpointTest`（IT） | 38 | 38 |
| `WpSettlementStatusMutatorTest`（IT） | 10 | 10 |
| `WpSettlementSummaryProviderRealAggregationTest`（IT） | 27 | 27（其中 1 個 `markTestSkipped`） |

⚠️ **驗證過程本身踩到一次干擾**：`WpSettlementSummaryProviderRealAggregationTest`
一度回報 25/27，看起來像兩條被靜默跳過；單獨 filter 兩個方法後確認**兩條都有被收集**
（1 pass / 1 skip），重跑整檔即回到 27/27。原因同樣是平行 agent 的 DB 競用
（執行期間出現 `Record has changed since last read` / `Deadlock found`）。
**多 agent 平行作業時，「少了幾條測試」也可能是假象，必須重跑確認再下結論。**

### 必備 security 測試覆蓋

| 要求 | 對應測試 |
|---|---|
| 非 admin 存取被拒 | IT `test_shop_manager_is_denied_on_every_settlement_endpoint`（7 個端點逐一，且斷言 403 body 不含 `items` / `profit_amount` / `pending_amount` / `order_ids`）+ guest / subscriber / 缺 nonce |
| CSV 公式注入被中和 | Unit `CsvExporterTest` 4 條 + `test_all_three_user_input_columns_are_escaped` + `test_dangerous_prefixes_from_spec_examples_are_escaped` + IT 端到端 |
| **併發 mark-paid 不重複計入** | Unit `test_transition_goes_through_compare_and_set` / `test_losing_the_race_skips_the_record_and_writes_nothing` / `test_running_same_batch_twice_does_not_double_count` + **IT `test_second_cas_with_same_from_loses_the_race`（真實 SQL）** |
| reverse-paid 對非 paid 拒絕 | Unit ×3 + IT `test_reverse_paid_rejects_non_paid_records` |
| 訂閱物件不得雙重計算 | IT ×3（列表 / 摘要 / 匯出各一） |
| 未知 status 不得被組進查詢 | Unit ×2 + IT ×2（列表 / 摘要） |

### 實作過程中被測試抓到的真實缺陷（3 個）

1. **`truncated` 誤報**：原以「有沒有抓滿上限」判定，資料剛好等於上限時誤報截斷。
   （後續因規格要求「一次產出整份檔案」，整個 `truncated` 欄位已從 export 移除。）
2. **🔴 非法 status 讓過濾靜默退化成「不過濾」**：`SettlementFilterInput` 原本靜默丟棄
   不在白名單的 status，於是 `?status=paid' OR 1=1 --` 會讓 `statuses` 變空陣列 →
   Infrastructure 判定「沒有 status 條件」→ **列出全部**。站長會看到所有結算卻以為在看篩選子集。
   改為拋 `\DomainException` → 400。「回 0 筆」的方案已評估並否決（會被讀成「沒有待撥款的」）。
3. **403 body 斷言寫得太天真**：WP 的 `rest_forbidden` 回應本來就有 `data`（內含 status），
   原本斷言「沒有 data」是錯的。改成斷言 body 不含任何結算欄位。

---

## 7. 尚未解決 / 後續需注意

| # | 項目 |
|---|---|
| 1 | **CSV 匯出無筆數上限的 OOM 風險**（§3.3）。規格明訂 v1 不做分批，因此整份檔案在記憶體中組完才回傳。資料量成長到會觸發時，需要的是背景排程 + 檔案下載，**不是靜默截斷**（截斷會讓站長拿著少了幾百筆的明細去轉帳）。目前只有 `MAX_PAGES` 迴圈保險絲。 |
| 2 | **`is_refund_after_paid` 未持久化**：`OrderItemSettlementRepository::save()` 不寫這個欄位、讀回時硬編 `false`。「已撥款後退款」目前只存在 `_partner_settled_log` 的 `refund_after_paid` 稽核紀錄。屬任務 #7 範圍，本次未動該檔。 |
| 3 | **`_partner_settled_log` 的 read-modify-write**：同一 partner 被兩個請求同時寫稽核紀錄時後寫者可能覆蓋先寫者。遺失的只是稽核紀錄（狀態在 order itemmeta，各自獨立寫入不會遺失）。 |
| 4 | **稽核紀錄上限 2000 筆**（termmeta 每次讀 term 都會載入，不設限會膨脹到幾 MB）。需要完整歷史時要改存自訂資料表。 |
| 5 | **`order-ids` cap 200 是保守值**。若站長回報「賣場訂單超過 200 張就篩不完整」，正解是後端直接支援訂單列表的分潤篩選，而不是把 cap 調大（query string 有長度上限）。 |
| 6 | **前端尚未實作**（任務 #10）。回應都是 `{code, message, data}`，只能用 `useCustom` / `useCustomMutation` + `dataProviderName: 'power-shop'`（Pitfall #10）；快取失效必須用 `useInvalidateProfitQueries`。前端**必須**呈現 `skipped`（含原因）、`audit_log_failed_partner_ids`、`needs_manual_rate`、`order-ids` 的 `truncated`——這四個欄位若被忽略，各自對應一種站長會做出錯誤決策的情境。 |
| 7 | **未跑 e2e / 真實站台驗證**。本次只到 Unit + Integration，端到端留給任務 #13。 |
| 8 | **設計文件 §6.1 與 feature 的權限不一致**（前者 `manage_woocommerce`、後者 `manage_options`）。我以 feature 為準，建議回頭同步設計文件以免下一個人又踩。 |

---

## 8. 收工狀態（凍結點，2026-08-06）

本節為交接凍結點。此後不再編輯任何檔案。

### 8.1 探針清除驗證（零命中）

```console
$ grep -rn "OR 1=1\|OR TRUE\|1 = 1\|if ( false )\|實證\|暫時版本" inc/
ZERO MATCHES
```

三個中性化點皆已還原並逐一確認：

| 中性化點 | 還原後現況 |
|---|---|
| `WpSettlementStatusMutator::compare_and_set()` | `$wpdb->update()` 的 WHERE 含 `'meta_value' => $from`（3 個 format `[ '%d', '%s', '%s' ]`） |
| `MarkSettlementsPaidUseCase` | `compare_and_set` 出現 3 次（import / 呼叫 / docblock） |
| `WpSettlementSummaryProvider` | `AND {$order_type_col} = %s` × 3（KPI / trend / settlements） |

⚠️ team lead 亦曾自行中性化 `compare_and_set()` 做實證，凍結前已再次確認為 CAS 版本。

### 8.2 完成 / 未完成（誠實列出）

**已完成**

| 項目 | 佐證 |
|---|---|
| 7 個 REST endpoint（含 D-6 `order-ids`） | `V2Api.php` 244-275 行 7 個 route |
| 7 個 callback + DI 接線 | 三個 mutation callback 皆傳 `mutator: WpSettlementStatusMutator::instance()`（1372 / 1406 / 1439） |
| CAS（Port + Infrastructure + 3 UseCase） | `SettlementStatusMutatorInterface` / `WpSettlementStatusMutator` |
| **D-6 `ListSettlementOrderIdsUseCase`** | 已完成且可運作（cap 200 + `truncated` 誠實回報） |
| 修補 2：`WpSettlementSummaryProvider` 三段 SQL 型別過濾 | 3 處齊全（變數 + WHERE + args） |
| `WpAdminSettlementQuery` 型別過濾 | 6 處（list / count / summary / order-ids / count_order_ids） |
| 測試 fake ×4、Unit 90 條、IT 47+5 條 | §2 檔案清單 |
| `specs/api/api.yml` 7 path + 8 schema | 全檔 `$ref` 可解析、`yaml.safe_load` 通過 |
| red-green 實證 ×3 + 探針掃描 | §4 |

**未完成 / 刻意不做**

| 項目 | 說明 |
|---|---|
| e2e / 真實站台驗證 | 只做到 Unit + Integration（任務 #13 範圍） |
| `is_refund_after_paid` 持久化 | 屬任務 #7；本次未動 `OrderItemSettlementRepository` |
| 前端（結算頁 SPA） | 任務 #10 |
| 設計文件 §6.1 權限與 feature 同步 | 我以 feature 為準實作，但**未回頭改設計文件**，兩份仍不一致 |

### 8.3 🔴 CAS 測試的能力邊界（team lead 指出，必須誠實記錄）

`test_cas_guard_lives_in_sql_where_clause_not_in_php` 能區分的是
**CAS vs 無條件 UPDATE**，**區分不了 CAS vs 正確實作的 read-check-write**——
後者會先讀到 `cancelled`、判斷與 `from` 不符、不寫入，同樣回 `false`，測試照樣綠。

各層目前的實際保護範圍：

| 層 | 測試 | 擋得住 | 擋不住 |
|---|---|---|---|
| UseCase | `test_transition_goes_through_compare_and_set`（斷言 `cas_calls`） | 有人把 UseCase 改回自己在 PHP 判斷 + `save()` | — |
| Infrastructure | `test_cas_guard_lives_in_sql_where_clause_not_in_php` | `$wpdb->update()` 變成無條件 | **在 `compare_and_set()` 內部改用 PHP 讀-判斷-寫** |

⇒ **目前沒有任何自動化測試能防止有人把 `compare_and_set()` 的內部實作換成 read-check-write。**
要真正鎖住需要白盒斷言（例如檢查 `$wpdb->last_query` 的 WHERE 是否含 `meta_value`），屬後續工作。

另外 team lead 實測確認：`test_concurrent_mark_paid_does_not_double_count` 與
`test_running_same_batch_twice_does_not_double_count` 在 read-check-write 下**仍然通過**
——它們是循序呼叫，證明不了 CAS 有在守門。這與我在 §4 RG-1 的觀察一致。

### 8.4 凍結點測試數字（最後一次執行）

| 層級 | 結果 | 判定 |
|---|---|---|
| Unit | **503 tests / 1160 assertions / 1 failure** | failure 為 pre-existing TD-2 |
| Integration | **359 tests / 1759 assertions / 15 failures / 3 skipped** | 15 條逐條與 baseline 同名，無一在本次改動的檔案內 |
| `vendor/bin/phpcs inc/` | **0 error / 0 warning** | 全綠 |

⚠️ **多 agent 平行作業期間，單次測試結果不可信**：本輪實測同一 testsuite 曾出現
15 / 14 / 36 failures 與 25 / 27 tests 的浮動，成因是並行 agent 在執行期間寫入同一批檔案
（伴隨 `Deadlock found` / `Record has changed since last read`）。上表為重跑確認後的穩定值。
