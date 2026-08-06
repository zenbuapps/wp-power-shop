# 分潤結算全鏈路 — TDD 協調藍圖

> **產出者**：`@zenbu-powers:tdd-coordinator`（sub-agent，不自行 spawn 下游 agent）
> **日期**：2026-08-06
> **分支**：`refactor/one-page`
> **上游需求**：把分潤賣場尚未實作的部分全部實作完，核心是**分潤結算的整條鏈路**——
> 目前顧客加購後訂單完全沒有分潤紀錄，夥伴報表永遠是空的，站長無法撥款。
> **設計來源**：`specs/2026-05-06-profit-shop-design.md` §2.5 / §3.5 / §3.9 / §4.3 / §5.2～§5.7 / §6.9
> **使用方式**：主窗口讀完本檔後，依 §8「Agent 指派總表」逐一 spawn 下游 agent。

---

## 0. 一句話結論

**整條鏈路的技術阻塞點已全部查證完畢，可直接照辦；但必須先處理「三個既有缺陷」（§1.2）——
其中兩個會在 Stage 1 一開始寫入資料的那一刻同時引爆（訂閱 line item 雙倍計算、
`save()` 在 hook 裡拿不到 order_item_id），不先處理會讓整個結算金額系統性錯誤，
而且全程沒有任何錯誤訊息。**

---

## 1. 前置調查結論

### 1.1 已驗證前提（逐行查證，**不需要**再派調查任務）

> ⚠️ 行號一律以**本機站台的 WooCommerce 10.1.4** 為準（見 `.claude/CLAUDE.md` Pitfall #34）。
> **wp-env 容器裝的是另一個版本**（先前實測 11.0.0-rc.1），行號會位移。
> 對不上時先確認 WC 版本，不要直接認定註解寫錯。

| # | 問題 | 結論與證據 |
|---|------|-----------|
| **I-1** | Block 結帳（Store API）會不會觸發 `woocommerce_checkout_create_order_line_item`？ | **會，單一 hook 涵蓋兩條路徑。** `src/StoreApi/Utilities/OrderController.php:781-789` 的 `update_line_items_from_cart()` 直接呼叫 `wc()->checkout->create_order_line_items( $order, $cart )`；該方法在 `includes/class-wc-checkout.php:571` 發出 action。`grep -rn "woocommerce_checkout_create_order_line_item"` 對**整個 WooCommerce plugin** 只有 `class-wc-checkout.php` 兩處命中（`:537` 是替換 item class 的 `_object` filter，與本任務無關；`:571` 是我們要的 action）——**不存在任何平行實作**。⇒ 不需要 `woocommerce_store_api_checkout_update_order_from_request` 之類的補充路徑。 |
| **I-2** | hook 觸發時 `$item` 有沒有 id？ | **沒有，`$item->get_id()` 為 0。** `class-wc-checkout.php:571` 的 `do_action` 在 `:573` 的 `$order->add_item( $item )` **之前**；WC 自己的 docblock 逐字寫 `Action hook to adjust item before save`。 |
| **I-3** | 訂閱續訂單會不會走 cart？ | **不會。** `wcs-renewal-functions.php:29` 的 `wcs_create_renewal_order()` → `wcs_create_order_from_subscription()`，docblock 逐字寫 *"creates an order with the same post meta, **order items and order item meta** as the subscription"*。⇒ (a) `create_order_line_item` 不觸發，確實需要 `wcs_renewal_order_created`（`:38`，**是 `apply_filters` 不是 action**）；(b) **`_profit_*` meta 會被整組複製過去**，含 `_settlement_status='paid'` 這種髒值。 |

### 1.2 🔴 三個必修的既有缺陷（非新功能，是地基）

#### F-1　`OrderItemSettlementRepository::save()` 在結帳 hook 裡**用不了**

`save()` 呼叫 `wc_update_order_item_meta( $record->order_item_id, ... )`，而 I-2 證明此時 id = 0
⇒ meta 全部寫到 `order_item_id=0`，`find_by_order_item()` 永遠讀不到，**而且不會拋任何錯**。

**修法**：在 `OrderItemSettlementRepository`（Infrastructure）**新增**具體方法。
🔴 **不可**加進 `SettlementRepositoryInterface`——Domain 層不得出現 `WC_Order_Item` 型別，
加了會讓 `tests/Unit/**`（不啟 WP）直接 fatal。

```php
/**
 * 把結算紀錄寫進「尚未持久化」的 line item
 *
 * 結帳當下 item id 仍為 0：includes/class-wc-checkout.php:571 的 do_action
 * 早於 :573 的 $order->add_item()（WooCommerce 10.1.4；wp-env 容器版本行號會位移）。
 * 因此必須用 add_meta_data()，不能用 wc_update_order_item_meta()。
 */
public function save_to_item(
    \WC_Order_Item_Product $item,
    SettlementRecord $record,
    string $override_regular,
    string $override_sale,
    string $override_signup
): void {
    $item->add_meta_data( self::META_SHOP_ID, $record->shop_id, true );
    // …其餘 10 個 key，$unique 一律 true
}
```

**`$unique = true` 是關鍵**：Store API 的 `update_line_items_from_cart()`（`:786`）在 cart hash
變動時會 `remove_order_items('line_item')` 後重建，同一張草稿單可能觸發多次。沒有 unique 旗標
會累積重複 meta，而 `WpSettlementSummaryProvider` 的三段 SQL 都假設
`_settlement_status` 是 **1:1 INNER JOIN**（該檔 `:226` 自己註明），重複 meta 會讓金額直接翻倍。

#### F-2　`RoundingStrategy` **全專案零實作**

`grep -rln "RoundingStrategy" inc/` 只命中 interface 自己與 `ProfitCalculator`。
`ProfitCalculator` 建構子必填一個實作 ⇒ **目前任何人都無法實例化它**。
Stage 0 必須新增 `Infrastructure/WooCommerce/WcDecimalRounding implements RoundingStrategy`。

#### F-3　🔴 結算 SQL **完全沒有過濾訂單型別** ⇒ 訂閱商品金額會雙倍

四處 SQL 都是 `INNER JOIN wc_orders o ON o.id = oi.order_id`，**沒有一處限定型別**：

| 檔案 | 行 |
|---|---|
| `Infrastructure/Persistence/OrderItemSettlementRepository.php` | `query_order_item_ids()` HPOS `:250` / legacy `:259` |
| `Infrastructure/Persistence/WpSettlementSummaryProvider.php` | KPI `:136-137` / trend `:255-256` / settlements `:345-346` |

結合 I-3：訂閱商品結帳後，`_profit_*` 會**同時存在於 parent order 與 `shop_subscription`**
的 line item，而 `shop_subscription` 的 status（`wc-active` / `wc-on-hold`）**不在
`EXCLUDED_ORDER_STATUSES` 內** ⇒ 兩筆都被撈進來 ⇒ **夥伴報表與結算列表金額 ×2**。

這個缺陷今天不發作，只因為目前沒有任何資料寫入——**Stage 1 上線的那一刻立刻引爆**。
四處都要補：

```sql
-- HPOS
INNER JOIN {prefix}wc_orders o ON o.id = oi.order_id
WHERE ... AND o.type = 'shop_order' AND o.status NOT IN (...)

-- legacy
INNER JOIN {posts} o ON o.ID = oi.order_id
WHERE ... AND o.post_type = 'shop_order' AND o.post_status NOT IN (...)
```

---

## 2. 🔴 最高風險細節：cart meta 與 order meta 命名不同，**不是直接複製**

### Cart item meta（`CartPriceOverrideHook` 寫的，Phase 13 後共 6 筆）

```
_profit_shop_id
_profit_partner_term_id
_profit_price_override          ← 實際生效價
_profit_regular_price_override
_profit_sale_price_override
_profit_signature               ← HMAC，v1/v2/v3 三版並存
```

### Order item meta（`OrderItemSettlementRepository` 的 private const，設計 §2.5，共 11 筆）

| order item meta | 來源 | 注意 |
|---|---|---|
| `_profit_shop_id` | cart `_profit_shop_id` | 同名 |
| `_profit_partner_term_id` | cart `_profit_partner_term_id` | 同名 |
| `_profit_rate` | **重讀賣場 CPT `$shop->rate->value()`** | ⚠️ cart 完全沒有這個欄位；賣場已刪見 D-1 |
| `_override_regular_price` | cart `_profit_regular_price_override` | ⚠️ **改名**；v1 簽章時 `verify_item` 回 `regular=null` → 寫 `''` |
| `_override_sale_price` | cart `_profit_sale_price_override` | ⚠️ **改名**；同上 |
| `_override_signup_fee` | 重讀 `OverrideItem` 的 `PriceOverride->signup_fee` | ⚠️ cart 從未攜帶；讀的是**結帳當下**值非加購當下值，登記 TD |
| `_actual_price` | cart `_profit_price_override`（**取驗章後的回傳值**，不直接讀 meta） | ⚠️ **改名** |
| `_profit_amount` | `ProfitCalculator::calculate( actual, $values['quantity'], rate )` | ⚠️ **需計算**；bcmath，禁浮點；精度見 D-2 |
| `_settlement_status` | 固定 `'pending'` | ⚠️ **需自己補** |
| `_settled_at` | 不寫（初始 null） | |
| `_settled_by` | 不寫（初始 null） | |

**三個必須自己補的欄位**：`_profit_rate`（讀賣場）、`_profit_amount`（算）、`_settlement_status`（固定 `'pending'`）。
**三個改了名的欄位**：`_actual_price` / `_override_regular_price` / `_override_sale_price`。

---

## 3. 需要 team lead 拍板的決策點（附建議；未拍板時照建議做）

| # | 議題 | 選項 | **建議與理由** |
|---|------|------|---------------|
| **D-1** | 結帳時賣場已被刪除（`rate` 讀不到） | (a) 完全不寫分潤 meta<br>(b) 寫入歸屬但 `rate=0` | **(b)**。`apply_override`(999) **不重讀賣場**（只驗簽章對 frozen `_profit_price_override`），所以顧客**確實付了分潤價**。(a) 會造成「顧客付了分潤價、系統零紀錄」——那正是本任務要消滅的東西。做法：寫入完整歸屬 meta + `_profit_rate=0` + `_profit_amount='0.00'` + `WC::logger(..., 'error', ..., 'profit_shop')`，結算頁標紅「賣場已刪除，需人工設定比例」。 |
| **D-2** | `_profit_amount` 儲存精度 | `ProfitCalculator` 硬寫 `round($raw, 4)`；`WpSettlementSummaryProvider::format_money()` 用 `bcadd($v,'0',2)`（**截斷**非四捨五入） | **`WcDecimalRounding` 忠實遵守 `$decimals`（half-up，純 bcmath 不用 float），但 hook 端寫入前再以 half-up 收斂到 `wc_get_price_decimals()`**（抽成 `Infrastructure` 的 `MoneyFormatter::to_store()`）。理由：單筆顯示與加總必須同精度，差一分錢是站長對帳時最先炸出來的東西。🔴 **不要**改 `ProfitCalculator`（已有 Mockery 測試鎖住行為）。 |
| **D-3** | 部分退款（§5.7「對應 line item 各自處理」） | (a) 任何退款 → refunded<br>(b) 全退才 refunded、部分退不動<br>(c) 全退→refunded；部分退**重算 `_profit_amount`** 並維持 pending | **(c)**。(a) 系統性少付夥伴、(b) 系統性多付站長，只有 (c) 兩邊都不錯。`SettlementRecord::$profit_amount` 是 readonly → 重建一個新 instance 即可。判準：`$refund->get_items()` 每筆的 `_refunded_item_id` + `qty` / `total` 對照原 line item。 |
| **D-4** | `mark-paid` 併發 | (a) 靠 `SettlementRecord::mark_paid()` 內建的 pending 檢查<br>(b) SQL 條件式 UPDATE（CAS） | **(b)**。(a) 是 read-check-write，兩個分頁同時按下會**雙重撥款**。新增 `OrderItemSettlementRepository::compare_and_set_status( int $item_id, string $from, string $to ): bool`，用 `$wpdb->update(... WHERE meta_key='_settlement_status' AND meta_value=$from)` 判 affected rows。⚠️ CAS 繞過 `wc_update_order_item_meta` 就繞過了它的 cache 清除，**必須**手動 `wp_cache_delete( $item_id, 'order_itemmeta' )`。 |
| **D-5** | CSV 匯出回傳形式 | (a) stream raw CSV + header<br>(b) `{code, message, data:{filename, content_base64}}` | **(b)**。維持 admin endpoint 的 `{code,message,data}` 慣例（`react.rule.md` §3 的 `useCustom` 剝兩層依賴這個形狀），前端自行 `Blob` 下載。 |
| **D-6** | 訂單列表「分潤賣場」過濾 | WC REST `orders` 無法依 order item meta 篩選 | 新增 `GET profit-settlements/order-ids?shop_id=`（回 order_id 陣列），前端拿去當 `wc-rest` 的 `include` filter。⚠️ `include` 有長度上限，v1 先 cap 200 筆並在 UI 據實告知，登記 TD。 |

---

## 4. 實作順序與依賴圖

```
                 ┌──────────────────────────────────────┐
                 │ Stage 0  前置修補（F-1 / F-2 / F-3） │  ← 所有 stage 的地基
                 └──────────────┬───────────────────────┘
                                ↓
                 ┌──────────────────────────────────────┐
                 │ Stage 1  結帳寫入 LineItemMetaHook   │  ← P0，唯一資料來源
                 └──┬────────────┬───────────────┬──────┘
                    ↓            ↓               ↓
        ┌───────────────┐ ┌──────────────┐ ┌──────────────────────┐
        │ Stage 2 退款  │ │ Stage 3 續訂 │ │ Stage 4 Application  │
        │ /取消回沖     │ │（WCS 守門）  │ │ + 6 個 REST endpoint │
        └───────────────┘ └──────────────┘ └──┬──────────────┬────┘
              （2 / 3 / 4 三者互相獨立，可平行）  ↓              ↓
                                        ┌─────────────┐ ┌──────────────┐
                                        │ Stage 5 SPA │ │ Stage 6 訂單 │
                                        │ 結算頁      │ │ 頁分潤面板   │
                                        └─────────────┘ └──────────────┘
                                              （5 / 6 可平行）
                                                    ↓
                                        ┌──────────────────────────┐
                                        │ Stage 7 端到端驗收 + 文件 │
                                        └──────────────────────────┘
```

### 平行化總結（§4 對應「藍圖要求」第 4 項）

| 可平行組 | 理由（無共享狀態） |
|---|---|
| **Stage 2 / 3 / 4** | 三者各自新增獨立檔案：`SettlementReversalHook` / `RenewalProfitHook` / `Application/UseCase/Settlement/*` + `V2Api::$apis`。⚠️ 唯一交會點是 `Loader.php`（三者都要註冊）與 `V2Api.php`（只有 Stage 4 動）→ **Stage 2 / 3 的 Loader 註冊行必須各自獨立一行，避免 merge 衝突**。 |
| **Stage 5 / 6** | 分屬 `pages/admin/ProfitSettlement/` 與 `pages/admin/Orders/`，唯一交會是 `resources/index.tsx` + `App1.tsx`（只有 Stage 5 動）。 |
| **`.feature` ×7** | 純規格檔，可與 Stage 0 同時進行。 |

**必須序列**：Stage 0 → Stage 1 → （2/3/4）→ （5/6）→ 7。

---

## 5. 各 Stage 的 Red → Green → Refactor

### Stage 0　前置修補（F-1 / F-2 / F-3）

**Agent**：Red = `@zenbu-powers:test-creator`／Green = `@zenbu-powers:ddd-architect`

#### Red — 測試檔與 method

| 檔案 | test method | `@group` |
|---|---|---|
| `tests/Unit/Infrastructure/WooCommerce/WcDecimalRoundingTest.php`（新） | `test_rounds_half_up_to_given_decimals` | happy |
| | `test_does_not_use_float_for_long_decimals` | edge |
| | `test_zero_decimals_returns_integer_string` | edge |
| | `test_negative_value_rounds_away_from_zero` | edge |
| `tests/Integration/Infrastructure/Persistence/OrderItemSettlementRepositoryTest.php`（新） | `test_save_to_item_writes_meta_before_item_is_persisted` | happy |
| | `test_save_to_item_uses_unique_meta_so_repeated_calls_do_not_duplicate` | edge |
| | `test_query_excludes_shop_subscription_line_items` | **security** |
| | `test_compare_and_set_status_returns_true_when_current_status_matches` | happy |
| | `test_compare_and_set_status_returns_false_when_current_status_mismatches` | edge |
| | `test_compare_and_set_status_flushes_order_itemmeta_cache` | edge |
| `tests/Integration/Infrastructure/Persistence/WpSettlementSummaryProviderRealAggregationTest.php`（既有，+3） | `test_kpi_excludes_subscription_line_items` | **security** |
| | `test_trend_excludes_subscription_line_items` | **security** |
| | `test_settlements_exclude_subscription_line_items` | **security** |

#### Green — 要實作的檔案

| 檔案 | 動作 |
|---|---|
| `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/WcDecimalRounding.php` | **新增**（`final class` + `SingletonTrait` + `declare(strict_types=1)` + 繁中 docblock） |
| `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/MoneyFormatter.php` | **新增**（D-2 的 `to_store()`） |
| `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php` | +`save_to_item()` +`compare_and_set_status()` + 兩處 SQL 補型別過濾 |
| `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpSettlementSummaryProvider.php` | 三處 SQL 補型別過濾 |

🔴 **`SettlementRepositoryInterface` 的 `git diff` 必須為空**（Domain 層不得出現 WC 型別）。

#### Refactor
把 `'shop_order'` 型別過濾片段抽成兩個 repository 共用的 private helper（HPOS / legacy 各一），
避免「四份逐字一致」——這正是 `OverridePriceResolver`（Phase 13）的同一條教訓。

---

### Stage 1　結帳寫入（P0，其餘全部的前提）

**Agent**：Red = `@zenbu-powers:test-creator`／Green + Refactor = `@zenbu-powers:ddd-architect`

#### 要實作的檔案

| 層 | 檔案 | 內容 |
|---|---|---|
| Application | `Application/Service/CartItemProfitVerifier.php`（**新**） | 把 `CartPriceOverrideHook::verify_item()` / `has_all_profit_meta()` / `normalize_price_for_signature()` 抽成共用實作，注入 `CartPriceSignatureService` |
| Infrastructure | `Infrastructure/WooCommerce/LineItemMetaHook.php`（**新**） | `woocommerce_checkout_create_order_line_item`，priority **10**，4 參數 |
| Loader | `Loader.php` | 註冊 `LineItemMetaHook::instance( $shop_repo, $verifier, $calculator, $settlement_repo, $money )` |

#### 🔴 為什麼要抽 `CartItemProfitVerifier`，而不是複製一份驗章邏輯

`verify_item()` 自己的 docblock 寫著：*「那是閘門 ③ 與本方法共用的**唯一實作**。
兩處各寫一份會分歧，而分歧時兩邊都『正常運作』、沒有任何錯誤訊息」*。
複製第三份是這個 codebase 已經吃過三次虧的模式（BUG-A / cart signature normalize /
Phase 13 的 regular price）。抽共用是 Phase 13 `OverridePriceResolver` 的既有先例。

**驗收標準（等同 Phase 13 的做法）**：
`CartPriceOverrideHookTest` 的 **27 個 method 零修改全綠**，
且 `git diff` 對該測試檔為空——這是「委派重構未改變既有行為」的直接證據。

**低風險退路**（若 team lead 認為 1400 行安全核心檔不宜動）：
只把 `verify_item` 的 visibility 改成 `public`（不改行為、不搬程式碼），
`LineItemMetaHook` 直接呼叫。代價是耦合到 hook class。

#### 🔴 必須重新驗章，不可假設「999 已經驗過了」

有人會說「`apply_override`(999) 已經把偽造的 meta strip 掉了，checkout 時看到的一定是乾淨的」。
**這個論證的前提是「請求流程順序」，而流程順序不是隔離邊界**——與 TD-62
（`OverridePriceFilters` 曾用「購物車請求不會走到顯示層」自我保證，實測被打臉 1000→27）
是**同一種失效模式**。獨立驗章是唯一結構性保證。

#### Red — 測試檔與 method

`tests/Integration/Infrastructure/WooCommerce/LineItemMetaHookTest.php`（新，14 method）

| test method | `@group` | 鎖住什麼 |
|---|---|---|
| `test_writes_full_profit_meta_set_for_valid_v3_cart_item` | smoke | 11 個 key 一次到位，**逐 key 斷言字面值**（不用常數比對常數） |
| `test_profit_amount_equals_actual_times_qty_times_rate` | happy | `240 × 3 × 20%` = `144.00` |
| `test_profit_amount_uses_bcmath_not_float` | edge | `19.99 × 3 × 15%` 類組合不漂移 |
| `test_forged_signature_writes_no_profit_meta` | **security** | 竄改 `_profit_price_override` → 零 meta |
| `test_cart_item_without_signature_writes_no_profit_meta` | **security** | 第三方直塞 `_profit_shop_id` |
| `test_v1_signature_writes_empty_override_regular_and_sale` | edge | v1 分支的 null → `''` |
| `test_v2_signature_writes_override_regular_and_sale` | happy | |
| `test_meta_is_written_before_item_is_saved` | edge | I-2：`$item->get_id() === 0` 時仍成功 |
| `test_repeated_hook_invocation_does_not_duplicate_meta` | edge | Store API 草稿重建（F-1） |
| `test_deleted_shop_writes_zero_rate_and_logs_error` | error | D-1 |
| `test_non_profit_cart_item_is_untouched` | smoke | 一般商品零副作用 |
| `test_settlement_record_is_readable_back_after_order_save` | smoke | 端到端接回 `find_by_order_item()` |
| **`test_classic_checkout_path_writes_profit_meta`** | happy | 直接呼叫 `WC()->checkout->create_order_line_items( $order, WC()->cart )` |
| **`test_store_api_checkout_path_writes_identical_profit_meta`** | **security** | 走 `StoreApi\Utilities\OrderController::create_order_from_cart()`，斷言 **11 個 meta 與 classic 路徑逐字相同** |

`tests/Unit/Application/Service/CartItemProfitVerifierTest.php`（新，10 method）

| test method | `@group` |
|---|---|
| `test_v3_signature_passes_and_returns_price_triplet` | happy |
| `test_v2_signature_ignores_product_identity` | happy |
| `test_v1_signature_returns_null_regular_and_sale` | edge |
| `test_v1_signature_ignores_injected_regular_meta` | **security** |
| `test_tampered_price_fails_verification` | **security** |
| `test_tampered_regular_fails_v2_verification` | **security** |
| `test_v3_identity_mismatch_fails_verification` | **security** |
| `test_missing_meta_returns_null` | edge |
| `test_unknown_signature_prefix_returns_null` | edge |
| `test_price_is_normalized_before_comparison` | edge |

#### 🔴 兩條結帳路徑各鎖一條 IT 的理由

「共用同一方法」是 WooCommerce **目前的實作細節**，不是它承諾的契約。
若日後 WooCommerce 把 Store API 拆成獨立實作，分潤 meta 會在 **Block 結帳
（現代 WooCommerce 的預設結帳）靜默消失**，那是「顧客付了分潤價、夥伴零紀錄」
的無歸屬銷售，屬於安全等級——所以 Store API 那條標 `@group security`。

**測試檔頭必須寫這段**（防止下一個人看到紅燈就去改測試）：

```php
/**
 * LineItemMetaHook 整合測試
 *
 * ⚠️ 本檔刻意對「傳統結帳」與「Store API / Block 結帳」各跑一次完整斷言。
 *
 * 目前（WooCommerce 10.1.4）兩條路徑共用同一個方法：
 *   src/StoreApi/Utilities/OrderController.php:789
 *     → wc()->checkout->create_order_line_items( $order, $cart )
 *     → includes/class-wc-checkout.php:571 do_action( 'woocommerce_checkout_create_order_line_item' )
 *   （全 WooCommerce 只有這一處 do_action，Store API 沒有平行實作）
 *
 * 但「共用同一方法」是實作細節，**不是 WooCommerce 承諾的契約**。
 *
 * 🔴 若 test_store_api_checkout_path_writes_identical_profit_meta 紅燈：
 *    那是**有效訊號**，代表新版 WooCommerce 拆了實作 ⇒ Block 結帳（現代預設）
 *    的分潤 meta 會靜默消失 ⇒ 顧客付了分潤價、夥伴零紀錄的無歸屬銷售。
 *    **不要**為了讓它變綠而修改本測試，必須回報並補掛 Store API 專屬 hook。
 *
 * ⚠️ 行號以本機站台的 WooCommerce 10.1.4 為準；wp-env 容器裝的是另一個版本
 *    （先前實測 11.0.0-rc.1），行號會位移。本檔所有斷言只依賴行為，不依賴行號。
 */
```

Store API 路徑的實作要點：

```php
// 不打 HTTP，直接用 OrderController，避免依賴 REST 認證與 nonce
if ( ! class_exists( \Automattic\WooCommerce\StoreApi\Utilities\OrderController::class ) ) {
    $this->markTestSkipped( 'Store API OrderController 不存在（WooCommerce 版本過舊）' );
}
$controller = new \Automattic\WooCommerce\StoreApi\Utilities\OrderController();
$order      = $controller->create_order_from_cart();
// 斷言：$order->get_items() 每筆的 11 個 meta
```

⚠️ **`markTestSkipped` 的條件必須是「class 不存在」，不是「斷言失敗」**。
把行為斷言包進 skip 條件，就會退化成 TD-64 那種「恆 skip 的假綠燈」。

#### Green — hook 骨架

```php
// priority 10；classic 與 Store API 兩條路徑共用
// （WooCommerce 10.1.4：OrderController.php:789 → class-wc-checkout.php:571；
//  wp-env 容器版本行號會位移，本註解只描述行為）
public function write_profit_meta( $item, $cart_item_key, $values, $order ): void {
    if ( ! $item instanceof \WC_Order_Item_Product || ! is_array( $values ) ) {
        return;
    }
    // 🔴 獨立驗章。不可假設 apply_override(999) 已經清乾淨
    //    ——請求流程順序不是隔離邊界（TD-62 同型失效）
    $prices = $this->verifier->verify( $values, $values['data'] ?? null );
    if ( null === $prices ) {
        return;   // 靜默降級，顧客結帳不受影響
    }
    // …組 SettlementRecord → $this->settlements->save_to_item( $item, $record, … )
    // ⚠️ 此時 $item->get_id() === 0，所以是 add_meta_data()，不是 wc_update_order_item_meta()
}
```

`_profit_amount` 的正確算法：

```php
$amount = $this->calculator->calculate(
    $prices['price'],                    // '240.00'
    (int) ( $values['quantity'] ?? 0 ),  // 3
    $shop->rate                          // ProfitRate(20)
);                                       // → '144.0000'（ProfitCalculator 硬寫 4dp）
$amount = $this->money->to_store( $amount ); // → '144.00'（D-2，half-up 到 wc_get_price_decimals()）
```

🔴 **禁止**寫成 `$actual * $qty * $rate / 100`——浮點在 `19.99 × 3 × 0.15` 這種組合就會漂。

#### Refactor
- 🔴 `LineItemMetaHook` **不可**照抄 cart hook 的 `is_admin() && ! wp_doing_ajax()` guard（見 R-5）
- 錯誤一律 `WC::logger(..., 'error', ..., 'profit_shop')`，**永不** throw（拋出會中斷顧客結帳）

---

### Stage 2　退款 / 取消回沖（§5.3）

**Agent**：Red = `@zenbu-powers:test-creator`／Green = `@zenbu-powers:ddd-architect`

#### 要實作的檔案

| 檔案 | 內容 |
|---|---|
| `Infrastructure/WooCommerce/SettlementReversalHook.php`（新） | `woocommerce_order_refunded`（prio 10, 2 params）+ `woocommerce_order_status_cancelled`（prio 10） |
| `Application/UseCase/Settlement/ReverseSettlementOnRefundUseCase.php`（新） | |
| `Application/UseCase/Settlement/CancelSettlementOnOrderCancelUseCase.php`（新） | |
| `Loader.php` | 註冊（獨立一行，避免與 Stage 3 merge 衝突） |

#### Red — `tests/Integration/Infrastructure/WooCommerce/SettlementReversalHookTest.php`

| test method | `@group` |
|---|---|
| `test_full_refund_flips_pending_to_refunded` | happy |
| `test_full_refund_on_paid_record_keeps_paid_and_writes_audit_log` | **security** |
| `test_full_refund_on_paid_record_emits_admin_notice` | happy |
| `test_partial_refund_recalculates_profit_amount_and_keeps_pending`（D-3） | edge |
| `test_partial_refund_on_paid_record_does_not_change_amount` | edge |
| `test_order_cancelled_flips_pending_to_cancelled` | happy |
| `test_order_cancelled_does_not_touch_paid_record` | **security** |
| `test_refund_on_order_without_profit_meta_is_noop` | smoke |
| `test_multiple_line_items_are_reversed_independently` | edge |
| `test_refund_of_shop_subscription_line_item_is_ignored`（F-3 連帶） | **security** |

**要點**：退款 line item 對照靠 `$refund->get_items()` 每筆的 `_refunded_item_id` meta；
`audit log type='refund_after_paid'` 對應 `SettlementRecord::$is_refund_after_paid`。

#### Refactor
`SettlementRecord::mark_refunded()` 已內建 `paid → refunded` 時自動設 `is_refund_after_paid`，
**不要**在 UseCase 裡再判一次狀態；狀態機的唯一真相在 Entity。

---

### Stage 3　訂閱續訂帶入（§5.4）

**Agent**：Red = `@zenbu-powers:test-creator`／Green = `@zenbu-powers:ddd-architect`

#### 要實作的檔案

| 檔案 | 內容 |
|---|---|
| `Infrastructure/WooCommerce/RenewalProfitHook.php`（新） | `wcs_renewal_order_created`，priority **20**（WCS 自己的 `add_order_note` 在 10） |
| `Loader.php` | **`function_exists( 'wcs_get_subscriptions_for_renewal_order' )` 守門**，未安裝 WCS 時完全不註冊 |

#### 🔴 兩個必記事項

1. **`wcs_renewal_order_created` 是 `apply_filters`，不是 action**
   （`wcs-renewal-functions.php:38`）。callback **必須 `return $renewal_order`**；
   漏了會讓 `wcs_create_renewal_order()` 回 `null`，**整條訂閱續訂鏈路壞掉**（不只分潤）。

2. **這個 hook 的主要職責是「重置」，不是「複製」。**
   I-3 已證明 WCS 會**自動複製** order item meta，所以續訂單一出生就可能帶著
   `_settlement_status='paid'`、`_settled_at`、`_settled_by`
   ⇒ **站長會對同一筆分潤重複撥款**。

| meta | 動作 |
|---|---|
| `_profit_shop_id` / `_profit_partner_term_id` | **凍結**（沿用複製過來的值，不動） |
| `_profit_rate` | **重讀賣場 CPT**；賣場已刪 → fallback 用複製過來的 parent rate |
| `_actual_price` | **重算**（WC 算的續訂 line total ÷ qty） |
| `_profit_amount` | **重算** |
| `_settlement_status` | **強制 `'pending'`** |
| `_settled_at` / `_settled_by` | **強制刪除** |

#### Red — `tests/Integration/Infrastructure/WooCommerce/RenewalProfitHookTest.php`

| test method | `@group` |
|---|---|
| `test_renewal_order_freezes_shop_id_and_partner_term_id` | happy |
| `test_renewal_order_reads_current_rate_from_shop` | happy |
| `test_renewal_order_falls_back_to_parent_rate_when_shop_deleted` | error |
| `test_renewal_order_resets_paid_status_to_pending` | **security** |
| `test_renewal_order_clears_settled_at_and_settled_by` | **security** |
| `test_renewal_order_recalculates_profit_amount_from_renewal_price` | happy |
| `test_hook_returns_the_renewal_order_object` | smoke |
| `test_renewal_order_without_profit_meta_is_untouched` | smoke |

⚠️ wp-env 若未裝 WCS，這批測試會 skip——**必須用 `markTestSkipped` 顯式跳過並在報告中據實說明**
（前例 TD-64：`variable-subscription` 的兩條 IT 在 wp-env 恆 skip，只有真實站台驗過）。
**不得**因為 skip 就宣稱「已驗證」。

---

### Stage 4　結算 Application 層 + 6 個 REST endpoint（§4.3 / §5.5）

**Agent**：Red = `@zenbu-powers:test-creator`／Green = `@zenbu-powers:ddd-architect`

#### 要實作的檔案

```
Application/UseCase/Settlement/
├── ListSettlementsUseCase.php
├── GetSettlementSummaryUseCase.php
├── MarkSettlementsPaidUseCase.php
├── MarkSettlementsCancelledUseCase.php
├── ReversePaidSettlementsUseCase.php
└── ExportSettlementsUseCase.php
Application/DTO/
├── SettlementListInput.php / SettlementListOutput.php
├── SettlementSummaryOutput.php
└── SettlementBatchResultOutput.php     ← {succeeded[], failed[{order_item_id, reason}]}
Application/Service/
└── CsvFormulaEscaper.php               ← §6.9
Infrastructure/Rest/V2Api.php           ← $apis 新增 6 條 + 6 個 callback
```

#### Endpoint 清單（permission **一律 `$admin_only`（`manage_options`）**，涉金流）

| Method | endpoint |
|---|---|
| GET | `profit-settlements`（filter: partner_id / shop_id / status / date range） |
| GET | `profit-settlements/summary` |
| POST | `profit-settlements/mark-paid` |
| POST | `profit-settlements/mark-cancelled` |
| POST | `profit-settlements/reverse-paid` |
| POST | `profit-settlements/export` |
| GET | `profit-settlements/order-ids`（D-6，Stage 6 用） |

回應一律 `{code, message, data}`（admin endpoint 慣例，非 partner 裸 payload）。
body 一律走 `read_json_body()`（會過 `WP::sanitize_text_field_deep`）。

#### 🔴 批次操作必須回「逐筆結果」，不可 all-or-nothing

站長勾 50 筆按撥款，其中 3 筆已被別的分頁改成 paid。
整批失敗會讓站長無所適從；靜默略過會讓他以為 50 筆都成功。
`SettlementBatchResultOutput` 回 `{succeeded: [...], failed: [{order_item_id, reason}]}`，
前端逐筆呈現。

#### CSV 公式注入（§6.9）

```php
// CsvFormulaEscaper：儲存格以 = + - @ TAB CR 開頭時前置單引號
private const DANGEROUS_PREFIXES = [ '=', '+', '-', '@', "\t", "\r" ];
```

⚠️ 商品名稱、賣場名稱、夥伴名稱三個欄位都是站長／顧客可控字串，
**三個都要跑**，不是只跑「看起來危險」的那個。

#### Red — Unit（`tests/Unit/Application/UseCase/Settlement/`，用 fake repository）

| 檔案 | test method | `@group` |
|---|---|---|
| `MarkSettlementsPaidUseCaseTest` | `test_marks_pending_records_as_paid` | happy |
| | `test_skips_already_paid_record_and_reports_it_in_failed` | edge |
| | `test_records_settled_by_from_caller_not_from_request` | **security** |
| | `test_writes_partner_settled_log` | happy |
| | `test_empty_id_list_returns_empty_result` | edge |
| `ReversePaidSettlementsUseCaseTest` | `test_reverses_paid_to_refunded_with_flag` | happy |
| | `test_rejects_pending_record` | error |
| `MarkSettlementsCancelledUseCaseTest` | `test_cancels_pending_records` | happy |
| | `test_rejects_paid_record` | error |
| `ListSettlementsUseCaseTest` | `test_applies_status_filter` | happy |
| | `test_applies_date_range_filter` | happy |
| | `test_caps_per_page_at_100` | **security** |
| | `test_caps_page_at_10000` | **security** |
| `GetSettlementSummaryUseCaseTest` | `test_sums_pending_paid_refunded_separately` | happy |
| | `test_uses_bcadd_for_amount_sum` | edge |
| `CsvFormulaEscaperTest`（`tests/Unit/Application/Service/`） | `test_escapes_equals_prefix` | **security** |
| | `test_escapes_plus_minus_at_prefixes` | **security** |
| | `test_escapes_tab_and_cr_prefix` | **security** |
| | `test_leaves_safe_value_untouched` | happy |

#### Red — IT（`tests/Integration/Infrastructure/Rest/SettlementsEndpointTest.php`）

| test method | `@group` |
|---|---|
| `test_seven_settlement_routes_are_registered` | smoke |
| `test_list_returns_code_message_data_envelope` | smoke |
| `test_non_admin_user_gets_403_on_mark_paid` | **security** |
| `test_partner_token_cannot_access_settlement_endpoints` | **security** |
| `test_mark_paid_is_idempotent_under_concurrent_calls`（D-4 CAS） | **security** |
| `test_mark_paid_returns_per_item_result_not_all_or_nothing` | happy |
| `test_export_escapes_formula_injection_in_product_name` | **security** |
| `test_summary_excludes_cancelled_and_subscription_items` | **security** |

#### Refactor
`ExceptionMapper` 已涵蓋 `InvalidStatusTransition`，**不需要**新增 Domain Exception；
批次失敗理由統一用 Domain Exception 的 message，不自己編字串。

---

### Stage 5　結算頁 SPA `/profit-settlements`（§3.5）

**Agent**：`@zenbu-powers:react-master`

#### 要實作的檔案

```
js/src/pages/admin/ProfitSettlement/
├── List/index.tsx                  # 兩張 antd-toolkit Card（admin-list-ui.rule.md §1）
├── List/Filter.tsx                 # grid 2 / md:3 / 2xl:4，篩選 + 重置
├── components/SummaryCards.tsx     # 待撥款 / 已撥款 / 退款 三卡
├── components/MarkPaidModal.tsx    # ⚠ HIGH-RISK（不可逆金流）
├── components/ExportButton.tsx
├── hooks.ts / types.ts / utils.ts
js/src/resources/index.tsx          # 註冊 resource（掛 marketing parent）
js/src/App1.tsx                     # 加 Route /profit-settlements
```

#### 四條硬紀律

1. **路線 B**：`useCustom` / `useCustomMutation` + **明示 `dataProviderName: 'power-shop'`**
   （Pitfall #10），**禁** `useTable` / `useForm`
2. 快取失效用 **`useInvalidateProfitQueries('profit-settlements')`**，**禁** `useInvalidate`
   （後者 queryKey 命名空間對不上，一次都打不中；配上全域 `staleTime: 10min`，
   症狀是「撥款後列表還是 pending」且零錯誤訊息）
3. `MarkPaidModal` 是**不可逆金流**：套 `profit-shop.rule.md` §12.4 的二次確認模式——
   `closable={false}` / `maskClosable={false}` / `keyboard={false}` + 二次確認
   + 顯示「共 N 筆、總額 X」
4. 版型一律遵循 `admin-list-ui.rule.md`：兩張 **`antd-toolkit`** 的 `Card`（不是 antd 的）、
   `FilterTags` 掛在篩選卡片底部、`defaultTableProps` + `getDefaultPaginationProps({ label: '結算' })`、
   無獨立 ID 欄（名稱欄用 `<Link>` + `NameId`）

#### Red
本專案無前端測試框架 → 以 `npx tsc --noEmit`（baseline **127**，不得增加）
+ §7.C 的手動驗收清單把關。
⚠️ `npx eslint` 因 TD-31（`unrs-resolver` native binding）**無法執行**，
**據實回報「未執行」，不要卡在這裡、也不要謊稱通過**。

---

### Stage 6　訂單頁分潤資訊面板（§3.9）

**Agent**：`@zenbu-powers:react-master`

| 項目 | 內容 |
|---|---|
| `/orders/edit/:id` | 新增「分潤資訊」面板：逐 line item 列 賣場 / 夥伴 / 比例 / 應分潤 / 狀態 |
| 已刪資料呈現 | 賣場已刪 → `[已刪除賣場]`；夥伴已刪 → `[已停用夥伴]`（§5.7） |
| 訂單列表 | 「分潤賣場」過濾（D-6：`GET profit-settlements/order-ids?shop_id=` → `wc-rest` 的 `include` filter，v1 cap 200 筆並在 UI 據實告知） |
| 無分潤訂單 | **整個面板不顯示**（不要印一個空面板） |

---

### Stage 7　端到端驗收 + 文件同步

**Agent**：`@zenbu-powers:doc-updater`

| 要更新的檔案 | 內容 |
|---|---|
| `.claude/CLAUDE.md` | 新增「Phase 17（分潤結算全鏈路）」章節 + 若有新踩坑則加 Common Pitfall |
| `.claude/rules/profit-shop.rule.md` | §12 新增結算鏈路小節；§7 補「meta 命名對映」與「訂單型別過濾」兩條 invariant |
| `.claude/rules/architecture.rule.md` | hook priority 串聯圖補上 `LineItemMetaHook` / `SettlementReversalHook` / `RenewalProfitHook` |
| `.claude/rules/wordpress.rule.md` | endpoint 速查表新增 7 條 |
| `.claude/rules/react.rule.md` | §6.5 新增 `ProfitSettlement` 頁面 |
| `.claude/rules/admin-list-ui.rule.md` | §5 對齊現況表新增一列 |
| `specs/api/api.yml` | 7 個 endpoint + schema |
| `specs/tech-debt.md` | 登記 §9 列出的新 TD |

---

## 6. 風險登記表

| # | 風險 | 症狀（一律**無錯誤訊息**） | 偵測方式 |
|---|---|---|---|
| **R-1** | meta 命名對映抄錯（`_profit_price_override`→`_actual_price` 等 3 個改名欄位 + 3 個要自己補的欄位） | 金額全 0 或欄位空白；`find_by_order_item()` 回 null | `LineItemMetaHookTest::test_writes_full_profit_meta_set_for_valid_v3_cart_item` **逐 key 斷言字面值**，不用常數比對常數 |
| **R-2** | `save()` 用在 item id=0（F-1） | meta 寫到 `order_item_id=0`，永遠讀不回 | `test_meta_is_written_before_item_is_saved` |
| **R-3** | 訂閱 line item 雙倍計算（F-3） | 夥伴報表金額剛好 ×2 | 四條 `*_excludes_subscription_line_items` IT；真實站台以一張訂閱訂單對帳 |
| **R-4** | Store API 草稿重建造成重複 meta | `_settlement_status` 出現 2 筆 → 1:1 INNER JOIN 變 1:2 → 金額 ×2 | `test_repeated_hook_invocation_does_not_duplicate_meta` |
| **R-5** | 照抄 cart hook 的 `is_admin() && ! wp_doing_ajax()` guard | **後台手動建單 / 後台退款完全不寫分潤**（退款一定發生在 admin） | 明文禁止；IT 在 `set_current_screen('shop_order')` 下再跑一次 happy case |
| **R-6** | `wcs_renewal_order_created` 忘記 `return $renewal_order` | **整條訂閱續訂壞掉**（不只分潤） | `test_hook_returns_the_renewal_order_object` |
| **R-7** | 續訂單繼承 `paid` 狀態（I-3） | 站長對同一筆重複撥款 | `test_renewal_order_resets_paid_status_to_pending` |
| **R-8** | 簽章驗證被「999 已經驗過了」的論證省略 | 第三方外掛注入 `cart_item_data` → 偽造分潤訂單 | `test_forged_signature_writes_no_profit_meta` + `test_cart_item_without_signature_writes_no_profit_meta` |
| **R-9** | bcmath 精度：用 `*` `/` 或 `number_format` | 大額訂單分潤差幾分錢，對帳時炸 | grep 該檔不得出現裸算術運算子作用在金額變數上；`test_profit_amount_uses_bcmath_not_float` |
| **R-10** | `mark-paid` 併發雙重撥款 | 同一筆 `_settled_at` 被覆寫，站長付兩次 | D-4 CAS + `test_mark_paid_is_idempotent_under_concurrent_calls` |
| **R-11** | CAS 繞過 `wc_update_order_item_meta` 沒清 cache | 同請求後續讀到舊 status，UI 顯示未更新 | `test_compare_and_set_status_flushes_order_itemmeta_cache` |
| **R-12** | 抽 `CartItemProfitVerifier` 時改到既有行為 | 既有購物車分潤價靜默消失 | **`CartPriceOverrideHookTest` 27 method 零修改全綠 + `git diff` 對該測試檔為空** |
| **R-13** | `@group` 只標 class-level | 測試「全綠」但一個 method 都沒跑（**假綠燈**） | `--filter XxxTest --testdox` 筆數 == 檔內 `test_` 方法數 |
| **R-14** | CSV 只對「看起來危險」的欄位跳脫 | 商品名稱 `=cmd\|'/c calc'!A1` 在 Excel 執行 | `CsvFormulaEscaperTest` + IT 對 product_name 欄位斷言 |
| **R-15** | Domain 層誤引用 WC 型別（`save_to_item` 加進 interface） | `tests/Unit/**` 不啟 WP 直接 fatal | `SettlementRepositoryInterface` 的 `git diff` 必須為空 |
| **R-16** | 未來 WooCommerce 把 Store API 的 line item 建立拆成獨立實作 | **Block 結帳（預設結帳方式）分潤 meta 靜默消失**，classic 仍正常 → 只有部分訂單沒有分潤，極難察覺 | `test_store_api_checkout_path_writes_identical_profit_meta`；該測試在 wp-env 的較新 WC 上跑，自帶前瞻檢測 |
| **R-17** | WCS 未安裝時仍註冊 hook | `Loader` 引用不存在的函式 → fatal，**整個外掛掛掉** | `Loader` 的 `function_exists()` 守門 + `LoaderTest` 加一條「未裝 WCS 時不註冊」 |
| **R-18** | Stage 2 / 3 / 4 平行時 `Loader.php` merge 衝突 | git 衝突（會被發現，非靜默） | 三者的註冊各自獨立一行、彼此不相鄰 |

---

## 7. 端到端驗收條件

### A. 自動化閘門（每個 stage 結束都要跑）

```bash
# PHP lint
vendor/bin/phpcs inc/

# 測試（⚠️ Windows host 不能直跑，必須進 wp-env 容器；見 wordpress.rule.md §11.3）
./tests/e2e/node_modules/.bin/wp-env run tests-cli \
  --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --colors=never

./tests/e2e/node_modules/.bin/wp-env run tests-cli \
  --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration --colors=never

# @group 驗證（防 R-13 假綠燈）：筆數必須 == 檔內 test_ 方法數
./tests/e2e/node_modules/.bin/wp-env run tests-cli \
  --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --filter LineItemMetaHookTest --testdox

# 前端
npx tsc --noEmit    # baseline 127，不得增加
pnpm build
```

🔴 **Baseline 必須動工前實測一次，不可沿用文件數字**
（文件記載為 Unit 411 / 1 failure（TD-2）、Integration 243 / 15 failures，
但 Phase 8→9 曾出現文件數字與實測不符的先例）。
任何新增 failure 都必須逐條說明是不是自己造成的。

⚠️ `npx eslint` 因 TD-31 無法執行——**據實回報「未執行」**。

### B. 真實站台端到端動線（`agent-browser`，登入身分）

> ⚠️ 站台裝了 **WP Rocket**，未登入的 GET 會拿到快取版本（Pitfall #30）。
> 前台頁面驗證一律加 `?nocache=xxx`，或直接用 `agent-browser` 以登入身分開啟。

| # | 步驟 | 通過條件 |
|---|---|---|
| 1 | 建賣場（rate 20%）、加商品（原價 300 / 覆寫 240） | 賣場前台顯示 NT$240 |
| 2 | 未登入訪客從賣場 Modal AJAX 加購 1 件 | 購物車 `price=240` / `regular_price=300` |
| 3 | 完成結帳（**classic 與 Block 各跑一次**） | 兩次都寫入 11 個 meta，且**逐欄位比對相同** |
| 4 | `wp eval` 讀 order item meta | `_profit_rate=20`、`_actual_price=240.00`、`_profit_amount=48.00`、`_settlement_status=pending` |
| 5 | 夥伴登入 `/profit-report/{slug}/` | KPI「待結算」出現 48、趨勢圖有點、結算列表有該筆 |
| 6 | 站長進 `#/profit-settlements` | 摘要卡待撥款 48；勾選 → 標記已撥款 → 二次確認 |
| 7 | 重新整理列表 + 夥伴報表 | 兩邊都變 paid（**驗證 `useInvalidateProfitQueries` 真的打中**） |
| 8 | 站長對該訂單全額退款 | 該筆變 refunded + admin notice「已撥款後退款」+ audit log |
| 9 | CSV 匯出 | 下載成功、金額正確、含 `=` 開頭的商品名稱被前置單引號 |
| 10 | **負向**：不帶 `profit_shop_id` 直接從單品頁下單 | 該 line item **零 `_profit_*` meta**，結算列表看不到 |
| 11 | **負向**：訂閱商品下單一次 | 結算列表**只出現一筆**（不是 parent + subscription 兩筆） |
| 12 | **負向**：手工竄改 cart session 的 `_profit_price_override` 後結帳 | 訂單 line item **零分潤 meta** |

### C. 每個 stage 交付時必須附上的實測數字

Unit / Integration 的 tests / assertions / failures、`phpcs` 檔數、`tsc` 錯誤數、
`--testdox` 的 method 筆數對照、`git diff --stat` 對「不該動的檔案」為空的證明。

🔴 **凡是寫進 docblock 的行為斷言或量化宣稱，都必須有當次實測支撐**
（本專案已為此吃虧三次：TD-20 / TD-54 / TD-67；這類缺陷 `phpcs` / PHPUnit / `tsc`
一個都攔不到）。

---

## 8. Agent 指派總表

| Stage | 內容 | Agent | 平行 |
|---|---|---|---|
| `.feature` ×7 | 規格補齊（§9） | `@zenbu-powers:test-creator` | ✅ 可與 Stage 0 同時 |
| 0 Red | `WcDecimalRounding` / Repository / Summary 測試 | `@zenbu-powers:test-creator` | — |
| 0 Green + Refactor | F-1 / F-2 / F-3 修補 | `@zenbu-powers:ddd-architect` | — |
| 1 Red | `LineItemMetaHookTest` 14 + `CartItemProfitVerifierTest` 10 | `@zenbu-powers:test-creator` | — |
| 1 Green + Refactor | `CartItemProfitVerifier` + `LineItemMetaHook` | `@zenbu-powers:ddd-architect` | — |
| 2 Red / 3 Red / 4 Red | 各自測試 | `@zenbu-powers:test-creator` ×3 | ✅ 三者同時 |
| 2 Green / 3 Green / 4 Green | 各自實作 | `@zenbu-powers:ddd-architect` ×3 | ✅ 三者同時 |
| 5 | 結算頁 SPA | `@zenbu-powers:react-master` | ✅ 與 6 同時 |
| 6 | 訂單頁分潤面板 | `@zenbu-powers:react-master` | ✅ 與 5 同時 |
| 7 | 端到端驗收 + 文件同步 | `@zenbu-powers:doc-updater` | — |

### ⚠️ Reviewer 是 opt-in，不排進自動流程

`*-reviewer` 系列（`security-reviewer` / `react-reviewer`）依專案規範**僅在用戶顯式喚醒時上場**。
本任務同時觸及 **HMAC 授權邊界 + 真實金流撥款**，兩者都在 `security-reviewer` 的守備範圍——
**建議 team lead 在 Stage 4 完成後顯式喚醒一次**，但本藍圖不代為排入流程。

---

## 9. 需要補的 `.feature`（7 個，全部 `@ignore`，放 `specs/features/profit-shop/`）

| 檔名 | tag | 涵蓋 Rule |
|---|---|---|
| `attribute-order-line-item.feature` | `@command` | 驗章通過才寫 / 11 個 meta 的來源與命名對映 / `_profit_amount` 公式 / 賣場已刪的 D-1 行為 / classic 與 Block 兩條結帳路徑同一結果 / 重複觸發不重複寫 / 一般商品零副作用 |
| `reverse-settlement-on-refund.feature` | `@command` | pending→refunded / paid 不自動改 + audit log + admin notice / 部分退款重算（D-3）/ 取消訂單→cancelled / 多 line item 各自處理 |
| `carry-profit-to-renewal-order.feature` | `@command` | 凍結（shop_id / partner_term_id）vs 動態（rate）/ **狀態必須重置為 pending** / 賣場已刪 fallback / WCS 未安裝時整段不生效 |
| `query-settlements.feature` | `@query` | 四種 filter / 分頁上限（per_page 100、page 10000）/ summary 三卡 / 排除 cancelled 與 subscription line item / 已刪賣場顯示 `[已刪除賣場]` |
| `mark-settlement-paid.feature` | `@command` | 只有 pending 可撥款 / 逐筆結果不 all-or-nothing / 併發只成功一次 / `_partner_settled_log` / reverse-paid（paid→refunded）/ mark-cancelled |
| `export-settlements-csv.feature` | `@query` | 欄位順序 / 公式注入跳脫（`=` `+` `-` `@` TAB CR）/ 中文與 BOM / 匯出範圍等同當前篩選 |
| `view-order-profit-panel.feature` | `@query` | 逐 line item 呈現 / 已刪賣場與夥伴的標記 / 無分潤訂單不顯示面板 / 訂單列表賣場過濾與 200 筆上限 |

撰寫時對齊既有慣例（參考 `specs/features/profit-shop/attribute-cart-item-to-shop.feature`）：
檔頭 `@ignore @command` / `@ignore @query`、以 `Rule:` 分組、
用 `Background` 建立商品與賣場 fixture、註解區塊寫清楚「為什麼」而非「是什麼」。

---

## 10. 預期新增的技術債（Stage 7 登記進 `specs/tech-debt.md`）

| 項目 | 說明 |
|---|---|
| `_override_signup_fee` 讀取時機 | 從 `OverrideItem` 讀的是**結帳當下**值，非加購當下值（cart 從未攜帶此欄位）。訂閱商品的 signup fee 若在加購後被站長改動，凍結值會與顧客實付不符 |
| D-6 的 200 筆上限 | 訂單列表「分潤賣場」過濾靠 `wc-rest` 的 `include`，有長度上限；超過 200 筆的賣場只能看到前 200 張訂單 |
| D-1 的 rate=0 紀錄 | 賣場已刪時寫入的 `rate=0` 紀錄需要站長人工處理，目前只有結算頁標紅，無批次修正工具 |
| `ProfitCalculator` 硬寫 4dp | `calculate()` 內 `round($raw, 4)` 的小數位是寫死的，Domain 層無法得知站台的 `wc_get_price_decimals()`；目前靠 `MoneyFormatter::to_store()` 在 Infrastructure 收斂 |
| Stage 3 在 wp-env 恆 skip 的可能 | 若 wp-env 未裝 WCS，`RenewalProfitHookTest` 全部 skip，只有真實站台驗過（同 TD-64） |

---

## 11. 附錄：本藍圖引用的原始碼位置

> 版本標註見 §1.1 開頭的警告。

| 用途 | 絕對路徑 |
|---|---|
| 結算 Entity（狀態機） | `inc/classes/Domains/ProfitShop/Domain/Entity/SettlementRecord.php` |
| 結算寫入出口 | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/OrderItemSettlementRepository.php` |
| 分潤金額計算 | `inc/classes/Domains/ProfitShop/Domain/Service/ProfitCalculator.php` |
| Rounding 介面（**零實作**，F-2） | `inc/classes/Domains/ProfitShop/Domain/Service/RoundingStrategy.php` |
| 簽章服務（v1/v2/v3） | `inc/classes/Domains/ProfitShop/Application/Service/CartPriceSignatureService.php` |
| 待抽出的 `verify_item()` | `inc/classes/Domains/ProfitShop/Infrastructure/WooCommerce/CartPriceOverrideHook.php:865` |
| 報表 SQL（F-3 三處） | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/WpSettlementSummaryProvider.php:136 / :255 / :345` |
| REST 註冊點 | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php`（`$apis` 陣列 `:147` 起） |
| Loader 註冊點 | `inc/classes/Domains/ProfitShop/Loader.php:116` |
| WooCommerce hook 出處 | `wp-content/plugins/woocommerce/includes/class-wc-checkout.php:571`（WC 10.1.4） |
| Store API 委派點 | `wp-content/plugins/woocommerce/src/StoreApi/Utilities/OrderController.php:789`（WC 10.1.4） |
| WCS 續訂建立 | `wp-content/plugins/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/includes/wcs-renewal-functions.php:29 / :38` |
