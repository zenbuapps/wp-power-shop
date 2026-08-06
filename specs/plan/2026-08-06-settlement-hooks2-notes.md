# 分潤結算 — 退款/取消回沖（Stage 2）+ 訂閱續訂帶入（Stage 3）交付紀錄

> **日期**：2026-08-06
> **分支**：`refactor/one-page`（前置工作 commit `866fbbd`）
> **上游**：`specs/plan/2026-08-06-profit-settlement-chain-plan.md` §5 Stage 2 / Stage 3、§3 D-3
> **規格**：`specs/features/profit-shop/reverse-settlement-on-refund.feature`
>          `specs/features/profit-shop/carry-profit-to-renewal-order.feature`
>          `specs/2026-05-06-profit-shop-design.md` §5.3 / §5.4 / §5.7 / §7.9

---

## 1. 檔案清單

### 新增（`inc/`）

| 檔案 | 內容 |
|---|---|
| `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/SettlementReversalHook.php` | Stage 2 主體。`woocommerce_order_refunded`(10, 2) + `woocommerce_order_status_cancelled`(10, 2) |
| `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/RenewalProfitHook.php` | Stage 3 主體。`wcs_renewal_order_created`(20, 2)，**filter**，未裝 WCS 時整段不註冊 |
| `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/RefundAfterPaidNotice.php` | 「已撥款後退款」的後台待辦佇列（wp_options，autoload=false）+ `admin_notices` 呈現 + 帶 nonce 的清除連結 |

### 新增（`tests/`）

| 檔案 | 方法數 |
|---|---|
| `tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php` | 17 |
| `tests/Integration/Infrastructure/WooCommerce/RenewalProfitHookTest.php` | 18 |

### 修改

| 檔案 | 變更 |
|---|---|
| `inc/classes/Domains/ProfitShop/Loader.php` | 6 個 import + class docblock 兩條 + 兩個 hook 的註冊（**各自獨立敘述，彼此不相鄰**，避免與其他工作項 merge 衝突） |
| `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php` | 新增 `_profit_refund_after_paid` 的讀寫（見 §1.1）+ 兩個窄寫入方法 |
| `tests/Integration/Infrastructure/Persistence/OrderItemSettlementRepositoryTest.php` | +5 method（旗標 round-trip / 缺鍵 / 兩個窄寫入方法） |

**其餘檔案零改動。** 特別確認未觸碰：`V2Api.php`、`Application/UseCase/Settlement/*`、
`WpSettlementSummaryProvider.php`、`OrderLineItemSettlementHook.php`、`CartPriceOverrideHook.php`、
`CartProfitMetaVerifier.php`、以及任何 `js/` 下的檔案。
`OrderItemSettlementRepository` 的 `save_to_item()`、`query_order_item_ids()` 的 SQL、
`ORDER_POST_TYPE` 常數**皆未改動**。

---

### 1.1　`is_refund_after_paid` 持久化（team lead 追加拍板）

**背景**：`SettlementRecord::$is_refund_after_paid` 是一個**設得起來但存不下去**的旗標
——`save()` 沒寫它、`find_by_order_item()` 讀回恆 `false`，全程沒有任何錯誤訊息。
只有 audit log 不夠：這種品項的 `_settlement_status` 仍是 `paid`（我方刻意不自動改），
結算頁**完全無從分辨**它與一筆正常已撥款品項的差異。

**`OrderItemSettlementRepository` 的四處改動**：

| 位置 | 內容 |
|---|---|
| 常數 | 新增 `private const META_REFUND_AFTER_PAID = '_profit_refund_after_paid'` |
| `save()` | `true` ⇒ 寫 `1`；`false` ⇒ **刪除 meta**（與 `settled_at`/`settled_by` 的 null ⇒ delete 一致；絕大多數紀錄旗標為 false，寫 `'0'` 等於替每一筆品項憑空多一列 itemmeta） |
| `find_by_order_item()` | 以 `wc_string_to_bool()` 讀取。缺鍵 ⇒ 空字串 ⇒ `false`，**不需要 migration**（同 Phase 9 `inflated_count` 缺鍵讀為 0 的做法） |
| 新方法 ×2 | `update_profit_amount()` / `flag_refund_after_paid()`（見下） |

**🔴 改用「具名的窄寫入方法」，並把 `META_PROFIT_AMOUNT` 改回 `private`**

前一輪為了讓 `SettlementReversalHook` 能只改寫 `_profit_amount`，把該常數開成 `public`。
本輪加入旗標時同一個需求再度出現（也要只寫一個欄位、也不能走 `save()`），若照舊就會變成
**兩個 public 常數 + 呼叫端自己組 `wc_update_order_item_meta()`**。改為兩個具名方法：

```php
public function update_profit_amount( int $order_item_id, string $amount ): void
public function flag_refund_after_paid( int $order_item_id ): void
```

三個理由：

1. **meta key 的字面值回到 Repository 獨佔**（`META_PROFIT_AMOUNT` 已改回 `private`），
   呼叫端不再需要知道「寫哪個 key」也不需要知道「怎麼寫」
2. 同一個 commit 內若一個用 public 常數、一個用方法，下一個人會挑錯 —— 一致性本身有價值
3. 順帶發現：`WpAdminSettlementQuery` 與 `WpSettlementSummaryProvider` **各自已有**一份
   private `META_PROFIT_AMOUNT = '_profit_amount'`（唯讀 SQL 消費端，且在禁改清單內），
   所以「開成 public 就有單一真相」這個說法本來就站不住 —— 收斂寫入路徑才是有效的做法

**🔴 為什麼兩個都不能走 `save()`**（同一條紀律，見 D-3）：`save()` 會把
`_settlement_status` / `_settled_at` / `_settled_by` 用呼叫端讀取當下的舊值寫回去。
標記旗標時若站長剛在結算頁把該筆人工沖回成 `refunded`，就會被推回 `paid` ⇒ 重複撥款。

**`save_to_item()` 不需要改**：結帳當下的新紀錄永遠不會是 `refund_after_paid`，
缺鍵由 `find_by_order_item()` 正規化為 `false`。

**`SettlementReversalHook` 的連帶改動**：`report_refund_after_paid()` 現在留下**三種痕跡**，
三者語意不同、缺一不可 ——

| 去處 | 語意 | 去重 |
|---|---|---|
| `_profit_refund_after_paid` order itemmeta | **這一筆的狀態**（結算頁靠它標紅） | 冪等覆寫 |
| `_partner_settled_log` | **事件歷史**（對帳依據） | 不去重 |
| `RefundAfterPaidNotice` 佇列 | **待辦清單**（站長看得到才會處理） | 依品項去重 |

只有 meta ⇒ 站長不知道要去看；只有稽核 ⇒ 結算頁分辨不出來；只有通知 ⇒ 一被清掉就查不回來。

---

## 2. 測試結果（實測，2026-08-06）

### 2.1 新增測試（`--testdox`）

```
Settlement Reversal Hook
 ✔ Hooks are registered by loader
 ✔ Full refund flips pending to refunded
 ✔ Full refund on paid record keeps paid and writes audit log
 ✔ Full refund on paid record queues admin notice
 ✔ Partial refund recalculates profit amount and keeps pending
 ✔ Partial refund does not change frozen price and rate
 ✔ Partial refund on paid record does not change amount
 ✔ Accumulated partial refunds reaching full quantity become full refund
 ✔ Amount only refund without quantity does not reverse
 ✔ Order cancelled flips pending to cancelled
 ✔ Order cancelled does not touch paid record
 ✔ Terminal status is not rewritten by cancel
 ✔ Refund on order without profit meta is noop
 ✔ Multiple line items are reversed independently
 ✔ Non shop order type is ignored

Renewal Profit Hook
 ✔ Filter registration matches subscriptions availability
 ✔ Renewal freezes shop id and partner term id
 ✔ Draft shop still carries attribution and rate
 ✔ Renewal reads current rate from shop
 ✔ Renewal falls back to inherited rate when shop deleted
 ✔ Renewal recalculates actual price and profit amount
 ✔ Renewal recalculates profit amount for multiple quantity
 ✔ Zero rate yields zero profit amount
 ✔ Unit price division is rounded half up
 ✔ Renewal resets paid status to pending
 ✔ Renewal clears settled at and settled by
 ✔ Any inherited status is reset to pending
 ✔ Hook returns the renewal order object
 ✔ Hook returns order even when shop repository throws
 ✔ Non order input is returned untouched
 ✔ Item without profit meta is untouched
 ✔ Only items with profit meta are processed
 ↩ Real wcs renewal order resets paid status      ← wp-env 未裝 WCS，skip（見 §5）

Tests: 33, Assertions: 100, Skipped: 1.
```

### 2.2 R-13 假綠燈檢查（`@group` 只標 class-level 會被靜默跳過）

| 檔案 | `--testdox` 筆數 | `grep -c 'public function test_'` | 判定 |
|---|---|---|---|
| `SettlementReversalHookTest` | 17 | 17 | ✅ 相符 |
| `RenewalProfitHookTest` | 18（17 執行 + 1 skip） | 18 | ✅ 相符 |
| `OrderItemSettlementRepositoryTest` | 12（原 7 + 新增 5） | 12 | ✅ 相符 |

每個 `test_` 方法都各自標了 `smoke` / `happy` / `error` / `edge` / `security` 其中之一。

`is_refund_after_paid` 持久化新增的 5 條：

```
 ✔ Is refund after paid survives save and find round trip        （round-trip，happy）
 ✔ Is refund after paid false removes the meta row               （false ⇒ delete，edge）
 ✔ Legacy record without refund after paid meta reads as false   （缺鍵讀為 false，edge）
 ✔ Flag refund after paid does not touch status or settled fields（窄寫入，security）
 ✔ Update profit amount only changes the amount                  （窄寫入，security）
```

`SettlementReversalHookTest` 另加 2 條端到端（正反向各一）：
`Full refund on paid record persists refund after paid flag`（security）、
`Full refund on pending record does not set refund after paid flag`（security）。

### 2.3 全套測試 vs baseline

Baseline 為 commit `866fbbd` 當下的實測值（由 team lead 提供）。

| 層級 | Baseline | 完成後（實測） | 判定 |
|---|---|---|---|
| Unit | 503 tests / 1160 assertions / **1 failure** | **503 / 1160 / 1** | 完全持平（本工作項未新增 Unit 測試） |
| Integration | 359 tests / 1759 assertions / **15 failures** | **399 / 1904 / 15**（+4 skipped） | tests **+40**（= 17 + 18 + 5 新增數）、failures **未增加** |

**Integration 的 15 條 failure 逐條核對，全部 pre-existing、無一在本次改動的檔案內：**

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
```

Unit 的唯一 failure 同樣是 pre-existing：`LoginRateLimiterIpDimensionTest::test_ip_is_hashed_in_transient_key`（TD-2）。

### 2.4 phpcs

```
$ vendor/bin/phpcs inc/
167 / 167 (100%)   ← 全綠（新增 3 檔，Stage 1 完成時為 164）
Time: 39.74 secs; Memory: 22MB
```

`php -l` 對全部異動檔案（含測試）全過。

---

## 3. 🔴 Red-green 探針與清除證明

新測試第一次跑就全綠，因此**必須**證明它們真的抓得到回歸。三個探針同時注入（各自指向不同測試，輸出可區分），跑完**立即**還原：

| 探針 | 注入內容 | 預期紅燈 | 實測 |
|---|---|---|---|
| PROBE-A | 拿掉 `resolve_shop_order()` 的訂單型別守門 | `Non shop order type is ignored` | ✅ 紅 |
| PROBE-B | `status: SettlementRecord::STATUS_PENDING` → `status: $inherited->status` | `Renewal resets paid status to pending`、`Any inherited status is reset to pending` | ✅ 紅（連帶 `Only items with profit meta are processed` 也紅，該測試同樣斷言 pending） |
| PROBE-C | 拿掉部分退款的 `recalculate_remaining_amount()` 呼叫 | `Partial refund recalculates profit amount and keeps pending`、`Accumulated partial refunds reaching full quantity become full refund` | ✅ 紅 |

```
探針注入後：Tests: 33, Assertions: 88, Failures: 6, Skipped: 1.
還原  後：Tests: 33, Assertions: 100, Failures: 0, Skipped: 1.
```

**第二輪（`is_refund_after_paid` 持久化，同樣注入 → 跑 → 立即還原）**：

| 探針 | 注入內容 | 預期紅燈 | 實測 |
|---|---|---|---|
| PROBE-D | `save()` 不寫旗標（**寫端失效**） | round-trip | ✅ 紅 |
| PROBE-G | `find_by_order_item()` 對缺鍵回 `true`（**讀端失效**） | round-trip、false ⇒ delete、缺鍵讀為 false | ✅ 紅 |
| PROBE-F | `flag_refund_after_paid()` 變 no-op | `Flag refund after paid does not touch status or settled fields`、`Full refund on paid record persists refund after paid flag` | ✅ 紅 |

⚠️ **PROBE-F 必須單獨再跑一次**：第一次 D/F/G 同時注入時，PROBE-G 讓
`is_refund_after_paid` 恆為 `true`，**遮蔽**了 PROBE-F 想驗的那條 `assertTrue`
（4 failures 中沒有 `Flag refund after paid…`）。單獨注入 PROBE-F 後才確認它會紅
（2 failures）。**多個探針同時注入時，要確認彼此沒有互相遮蔽**——否則會誤以為某條測試
有效，實際上它從頭到尾沒被驗證過。

```
D + F + G 同時注入：Tests: 29, Assertions: 130, Failures: 4.   ← PROBE-F 被 G 遮蔽
只注入 PROBE-F    ：Tests: 29, Assertions: 137, Failures: 2.   ← 補驗
還原後            ：全綠（見 §2.3）
```

**清除驗證**（每一輪都是：探針注入 → 跑測試 → 立即 `cp` 還原，中間未插入任何其他工作）：

```
$ grep -rn "PROBE-A\|PROBE-B\|PROBE-C\|PROBE-D\|PROBE-E\|PROBE-F\|PROBE-G\
\|OR 1=1\|OR TRUE\|1 = 1\|if ( false )\|暫時版本" inc/
（零命中）

$ grep -c "PROBE" inc/.../OrderItemSettlementRepository.php \
                  inc/.../SettlementReversalHook.php inc/.../RenewalProfitHook.php
inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php:0
inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/SettlementReversalHook.php:0
inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/RenewalProfitHook.php:0
```

⇒ **探針樣式零命中**。還原後全套測試數字回到 §2.3 的值，`phpcs` 167/167 全綠。

> 第一輪的 grep 額外把 `TEMP` 也納入 pattern，唯二命中是
> `Domain/Exception/TooManyAttempts.php` 的 `'TOO_MANY_ATTEMPTS'`（`ATTEMPTS` 含 `TEMP`
> 子字串），是 Phase 3-A 的既有檔案，與本次改動無關。

---

## 4. 關鍵決策與理由

### D-1　Hook priority

| Hook | priority | 理由 |
|---|---|---|
| `woocommerce_order_refunded` | 10 | 該掛載點沒有任何既有 power-shop hook 參與；本 hook 只讀 order item meta 與退款單，不依賴其他擴充的執行順序 |
| `woocommerce_order_status_cancelled` | 10 | 同上 |
| `wcs_renewal_order_created` | **20** | WCS 自己在 10 掛續訂單的處理（`add_order_note` 之類），讓它先跑完再動 meta，行為較可預期 |

**🔴 三個 callback 一律不加 `is_admin() && ! wp_doing_ajax()` guard**（R-5）。cart 端三個 hook
都有那道守門，照抄會讓整個功能不執行且沒有任何錯誤訊息 —— 退款與取消**必然**發生在
wp-admin。`test_hooks_are_registered_by_loader` 刻意在 `set_current_screen('shop_order')`
的 admin context 下驗證掛載仍成立。

### D-2　部分退款的重算公式（D-3 拍板）

```
remaining_qty = max( 0, ordered_qty − Σ|refunded_qty| )
_profit_amount = MoneyFormatter::to_store(
                     ProfitCalculator::calculate( _actual_price, remaining_qty, _profit_rate )
                 )
```

`_actual_price` 與 `_profit_rate` **不動** —— 它們是交易當下凍結的事實，退掉幾件不會改變
當初的成交單價與比例。全程 bcmath（`ProfitCalculator` 的 `bcmul`/`bcdiv` → `WcDecimalRounding`
的 half-up），零浮點。

**累計判定以 `qty` 為準**（team lead 拍板）：用
`WC_Order::get_qty_refunded_for_item()`（**跨全部退款單累加**，WooCommerce 10.1.4：
`includes/class-wc-order.php:2281`，回傳負數所以取 `abs()`）與原始 `$item->get_quantity()`
比較。`test_accumulated_partial_refunds_reaching_full_quantity_become_full_refund` 鎖住
「分兩次各退一半＝全退」。

### D-3　🔴 部分退款只改寫 `_profit_amount` 一個欄位，**不走** `save()`

`OrderItemSettlementRepository::save()` 會把 `_settlement_status` / `_settled_at` /
`_settled_by` 一起用**讀取當下的舊值**寫回去。若在那個瞬間有人剛把該筆標記成已撥款，
就會被靜默還原成 `pending` ⇒ **重複撥款** —— 正是 D-4 引入 CAS 要消滅的場景。

因此改為 `wc_update_order_item_meta( $item_id, OrderItemSettlementRepository::META_PROFIT_AMOUNT, ... )`，
並在寫入前用 `SettlementStatusMutatorInterface::current_status()`（直接讀 DB、不走快取）
再確認一次狀態。這也是 §1 那個 `private` → `public` 常數變更的唯一理由。

**殘留競態（已知、刻意接受）**：`current_status()` 與寫入之間若剛好被標記為 paid，
該筆金額會被下修。後果是「已撥款金額與紀錄金額不一致」的對帳落差，**不是**重複撥款；
要根治需要一個「條件式更新任意欄位」的新 mutator 方法（會動到 `WpSettlementStatusMutator`，
超出本工作項範圍）。已寫進實作 docblock。

### D-4　`paid` 一律不自動改（設計 §7.9）

錢已經匯給夥伴了。程式自動改狀態會讓站長失去「這筆需要處理」的線索；自動向夥伴追討
更是程式無權決定的業務行為。改為：

1. **稽核紀錄**：複用既有的 `SettlementAuditRecorder::record( [...], TYPE_REFUND_AFTER_PAID, get_current_user_id() )`
   → 寫進 `_partner_settled_log`（與 `ReversePaidSettlementsUseCase` 同一個型別，形狀一致）
2. **後台待辦**：`RefundAfterPaidNotice`（新檔）

站長後續走既有的 `POST profit-settlements/reverse-paid` 人工沖回。

**兩者刻意不同語意，不可為了 DRY 併成一份：**

| 對象 | 語意 | 去重 |
|---|---|---|
| `_partner_settled_log` | **事件歷史**（對帳依據），每次退款事件各留一筆 | 不去重 |
| `RefundAfterPaidNotice` 佇列 | **待辦清單**，同一筆品項只需處理一次 | **依 `order_item_id` 去重，保留最新** |

把待辦也做成不去重，站長對同一張訂單做三次部分退款就會看到三條一樣的提示；
把稽核做成去重則會弄丟退款事件的次數與時間，對帳時對不上。

### D-5　訂單取消**不寫**稽核紀錄

`_partner_settled_log` 記的是「站長把多少錢標記成撥給誰」。被取消的分潤從來沒有撥出去過，
對帳上沒有東西需要扣抵。（`MarkSettlementsCancelledUseCase` 會寫 `TYPE_CANCEL`，但那是
**站長的明確操作**、有 operator；訂單狀態驅動的自動取消沒有操作者。）

### D-6　訂單型別過濾

`resolve_shop_order()` 兩道守門，各自負責不同的東西：

1. `$order instanceof \WC_Order` —— `WC_Order_Refund extends WC_Abstract_Order`
   （WooCommerce 10.1.4：`includes/class-wc-order-refund.php:17`），**不是** `WC_Order`
   的子類，在這裡就被擋下、根本走不到第二道
2. `OrderItemSettlementRepository::ORDER_POST_TYPE !== $order->get_type()` ——
   `WC_Subscription::get_type()` 回 `'shop_subscription'`（WCS 6.1.0：
   `class-wc-subscription.php:129`），在這裡被擋下

判準沿用 `ORDER_POST_TYPE` 常數（結算查詢的五處 SQL 用的是同一個），**不另寫一份 `'shop_order'`**。

**wp-env 沒有 WCS，如何真的走到第二道守門**：用 WooCommerce 自己的
`woocommerce_order_class` filter（`includes/class-wc-order-factory.php:286`）把
`wc_get_order()` 回傳的物件換成一個 `get_type()` 回 `'shop_subscription'` 的 `WC_Order`
子類。實際執行到的就是實作裡那一行型別比對，不是繞過它的假綠燈（PROBE-A 已證明）。

### D-7　續訂 hook 的職責是「重置」不是「複製」

已逐行查證 WCS 6.1.0：`wcs_create_renewal_order()`（`wcs-renewal-functions.php:29`）→
`wcs_create_order_from_subscription()`（`wcs-order-functions.php:165`）→
`wcs_copy_order_item()`（同檔 `:758-764`）對 `$from_item->get_meta_data()` 逐一
`update_meta_data()`，**只排除 `_reduced_stock`** ⇒ 11 個 `_profit_*` 全部照抄，
含 `_settlement_status='paid'` / `_settled_at` / `_settled_by`。

也已確認**續訂單品項在 filter 觸發時已有 ID**（`:206` `$order_item->save()`、`:225`
重新 `wc_get_order()` 才 `apply_filters`），因此可以直接用 `Repository::save()` 寫入。

| 欄位 | 動作 |
|---|---|
| `_profit_shop_id` / `_profit_partner_term_id` | 凍結（沿用複製過來的值） |
| `_profit_rate` | 重讀賣場 CPT；賣場已刪 → fallback 用凍結值 + error log |
| `_actual_price` | 重算 = `bcdiv( $item->get_subtotal(), qty, 6 )` → half-up 收斂 |
| `_profit_amount` | 重算 |
| `_settlement_status` | **強制 `pending`** |
| `_settled_at` / `_settled_by` | **強制刪除**（`save()` 對 null 會 `wc_delete_order_item_meta()`） |

**取 `get_subtotal()` 而非 `get_total()`**：與 `OrderLineItemSettlementHook` 對首期的處理
一致（那裡用的是簽章保護過的賣場覆寫單價，也是折扣前）。站長另外發的折價券由站長吸收。
兩邊若一個算折扣前、一個算折扣後，同一個訂閱的首期與續訂會出現無法解釋的落差。

**`_profit_rate` 不檢查賣場 `status`**：賣場只是被下架（draft）時，這位夥伴的既有訂閱仍在
收費、仍該拿分潤。「還能不能被買」與「已經在收費的訂閱怎麼記帳」是兩件事（與
`OrderLineItemSettlementHook::freeze()` 同一條紀律）。`test_draft_shop_still_carries_attribution_and_rate` 鎖住。

### D-8　`wcs_renewal_order_created` 是 **filter**（R-6）

callback **所有分支**（含例外路徑、含輸入不是 `WC_Order` 時）都以 `return $renewal_order` 收尾。
漏了 return，`wcs_create_renewal_order()` 會拿到 `null` ⇒ **整條訂閱續訂鏈路壞掉**
（顧客的訂閱停止續期），影響範圍遠大於分潤。三條測試鎖住：
`test_hook_returns_the_renewal_order_object`、
`test_hook_returns_order_even_when_shop_repository_throws`（注入一個必拋例外的 Repository）、
`test_non_order_input_is_returned_untouched`。

### D-9　WCS 未安裝時的行為（R-17）

守門放在**兩處**：

1. `RenewalProfitHook::is_supported()`（`function_exists( 'wcs_get_subscriptions_for_renewal_order' )`）
   在**建構子內**判斷，未安裝就完全不 `add_filter` —— 這讓「未安裝不可能註冊」是結構保證，
   與呼叫端怎麼寫無關
2. `Loader` 也檢查一次 —— 讓 Loader 讀起來就看得出這是選用相依

用 `wcs_get_subscriptions_for_renewal_order()` 當探針而非 `class_exists( 'WC_Subscriptions' )`：
我們依賴的是 subscriptions-core 的續訂函式庫，而 class 名稱在 WCS / subscriptions-core
兩種安裝形態下不一定一致。

`test_filter_registration_matches_subscriptions_availability` **兩條分支都是真斷言**
（有裝 ⇒ 必須已註冊；沒裝 ⇒ 就算 `new` 出實例也不得註冊），不是「沒裝就跳過」。

### D-10　訂單型別過濾**不放寬**（team lead 明示）

續訂單本身是一張真正的 `shop_order`（`wcs_create_order_from_subscription()` 走
`wc_create_order()`），本來就該被結算查詢計入 —— 這正是我們要的。
五處 SQL 的 `shop_order` 過濾是為了排除 `shop_subscription`（同一筆分潤的副本），
**沒有動它**，並在 `RenewalProfitHook` 的 class docblock 寫明「不要因為這是訂閱相關的東西
就去放寬那五處過濾」。

---

## 5. ⚠️ 尚未驗證 / 已知落差 / 後續注意

### 5.1 🔴 `test_real_wcs_renewal_order_resets_paid_status` 在 wp-env 恆 skip

`.wp-env.json` 的 `plugins` 只有 `woocommerce` / `powerhouse` / `.`，**沒有
WooCommerce Subscriptions**。因此「真的走 `wcs_create_renewal_order()`」這一條
**只有真實站台驗得到**，本次**未執行**（同 TD-64 的先例）。

⚠️ **不得因為它沒紅燈就宣稱已驗證。** `markTestSkipped` 的條件刻意只看
「函式存在與否」，不把任何行為斷言包進去，否則會退化成 TD-64 那種假綠燈。

**緩解**：其餘 17 條測試手工造出「已被 `wcs_copy_order_item()` 複製過 meta」的續訂單
（用 `Repository::save()` 寫入與它完全相同的 11 個 meta），再**直接呼叫** `carry_profit()`
—— 凍結 / 動態 / 重算 / 強制重置四類行為全部真的跑過，只有「WCS 的 filter 接線」與
「WCS 的複製行為本身」沒有端到端驗證。後者已逐行讀過原始碼（見 D-7）。

**建議 Stage 7 端到端驗收在真實站台補一次**：建立一個訂閱商品的賣場訂單 → 撥款 →
手動觸發一次續訂（`wp eval` 呼叫 `wcs_create_renewal_order()` 或等排程）→ 確認續訂單品項
`_settlement_status = pending`、`_settled_at` / `_settled_by` 為空、`_profit_rate` 是賣場當下值。

### 5.2 已知邊界：只退金額、不退數量的退款不會回沖

團隊已拍板「累計判定以 qty 為準」。因此 `qty = 0` 但 `total > 0` 的退款單
（例如部分金額補償、運費退款）**不會**觸發任何回沖。

已用 `test_amount_only_refund_without_quantity_does_not_reverse` **把現況鎖成明示的斷言**，
日後若改判準會立刻紅燈，讓人有機會重新評估而不是意外變動。實作與測試的 docblock 都
標為「已知邊界（刻意接受）」。

**建議登記技術債**（本工作項未改 `specs/tech-debt.md`，留給 Stage 7）：
> 分潤回沖以退款數量判定，只退金額不退數量的退款單不會回沖。站長若用「部分金額補償」
> 的方式退款，該筆分潤仍會以原始數量計算。

### 5.3 部分退款重算的殘留競態

見 D-3 末段。後果是對帳落差、**不是**重複撥款。根治需要新增
`SettlementStatusMutatorInterface` 的條件式欄位更新方法（超出本工作項範圍）。

### 5.4 `is_refund_after_paid` 已持久化（本輪追加完成）

初版交付時這是一條落差；team lead 追加拍板後已完成，見 §1.1 與 §2.2。
`ReversePaidSettlementsUseCase` 的 docblock 目前仍寫著「`OrderItemSettlementRepository`
目前**不持久化這個欄位**（讀回時一律 false）」——**那段敘述現在已經過時**。
該檔在禁改清單內，本輪未動；**請在 Stage 7 文件同步時一併更正**，否則會變成
TD-20 / TD-54 / TD-67 同型的「註解與實際行為相反」缺陷（`phpcs` / PHPUnit 都攔不到）。

需要改的字句在
`inc/classes/Domains/ProfitShop/Application/UseCase/Settlement/ReversePaidSettlementsUseCase.php`
的 class docblock 末段（「⚠️ 已知落差」那一段）。

### 5.5 重複的稽核紀錄（刻意）

同一筆 paid 紀錄若經歷多次部分退款，每次退款事件都會寫一筆 `refund_after_paid` 稽核。
這是**正確的**（每次都是一個獨立的退款事件），與待辦佇列的去重是兩件事（見 D-4 的表）。

### 5.6 `RefundAfterPaidNotice` 的通知連結

通知裡的「前往分潤結算頁」連到 `admin.php?page=power-shop#/profit-settlements`
—— 該路由由 Stage 5（結算頁 SPA）提供。若最終路由名稱與規劃書不同，需同步這一行。
（純字串，不構成程式相依；路由不存在時 SPA 會自行導回 dashboard。）

### 5.7 `RefundAfterPaidNotice` 放在 `Infrastructure/WooCommerce/`

它其實比較偏 WordPress admin 而非 WooCommerce。放這裡是因為本工作項的檔案範圍限定在
`Infrastructure/WooCommerce/`，且它的觸發來源就是 WooCommerce 的退款事件。
日後若要搬到 `Infrastructure/WordPress/`，只是 namespace 變更，無行為影響。

---

## 6. 未執行的閘門

| 項目 | 狀態 |
|---|---|
| `npx tsc --noEmit` / `pnpm build` | **未執行** —— 本工作項零 `js/` 檔案改動，且另一個 agent 正在同時修改前端 |
| `npx eslint` | **未執行** —— 同上，且 TD-31（`unrs-resolver` native binding）使其在本機無法執行 |
| 真實站台端到端 | **未執行** —— 屬 Stage 7 範圍；§5.1 的 WCS 續訂驗證特別需要在那裡補 |
