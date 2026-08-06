# 結帳寫入分潤結算 hook — 實作紀錄（2026-08-06）

> 對應規格：`specs/2026-05-06-profit-shop-design.md` §2.5 / §5.2 / §5.7
> 上游計畫：`specs/plan/2026-08-06-profit-settlement-chain-plan.md` §1.2 / §3
> 分支：`refactor/one-page`

補上整條分潤結算鏈路的第一環：顧客結帳成立訂單時，把已通過 HMAC 驗章的 cart item
`_profit_*` meta 凍結成 `wc_order_itemmeta` 快照。在此之前這一段完全不存在——
顧客付了分潤價，訂單卻零分潤紀錄，夥伴報表永遠是空的，而且沒有任何錯誤訊息。

同批修掉 `OrderItemSettlementRepository` 查詢 SQL **缺少訂單型別過濾**的既有缺陷
（結帳寫入一上線就會引爆，見 §3.6）。

---

## 1. 檔案清單

### 新增

| 檔案 | 內容 |
|---|---|
| `inc/.../Infrastructure/WooCommerce/OrderLineItemSettlementHook.php` | 主體。掛 `woocommerce_checkout_create_order_line_item`(20)：驗章 → 讀賣場 rate → 算金額 → 寫快照 |
| `inc/.../Infrastructure/WooCommerce/WcDecimalRounding.php` | `Domain\Service\RoundingStrategy` 的**第一個** production 實作（bcmath half-up，全程不轉 float） |
| `inc/.../Infrastructure/WooCommerce/MoneyFormatter.php` | `to_store()`：把計算結果 half-up 收斂到 `wc_get_price_decimals()` |
| `tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php` | 15 條 IT |

### 修改

| 檔案 | 改動 |
|---|---|
| `inc/.../Infrastructure/Persistence/OrderItemSettlementRepository.php` | ① 新增 `save_to_item()`（寫進**尚未持久化**的 line item）② `query_order_item_ids()` 兩條 SQL 補訂單型別過濾（`ORDER_POST_TYPE` 常數）③ 修正兩處過時 docblock |
| `inc/.../Loader.php` | 註冊新 hook（**單行**，方便平行 agent 稍後在此追加、避免 merge 衝突）；把 `CartPriceSignatureService` 抽成共用實例同時給 cart hook 與結帳 hook（無狀態，行為不變） |
| `tests/Integration/Infrastructure/Persistence/OrderItemSettlementRepositoryTest.php` | +2 條 `@group security`（`find_by_partner` / `find_by_shop` 各一）+ `create_subscription_line_item()` helper |

### 未動（硬性約束 / 分工）

- `AddToCartHook` / `CartPriceOverrideHook` / `SingleProductPriceHook` / `PricelessRescuePolicy`：`git diff` 完全空白
- `WpSettlementSummaryProvider.php`：依分工由 `settlement-api` agent 負責，**本輪未碰**
- `ProfitCalculator`：未改（已有 Mockery 測試鎖住行為）

---

## 2. 驗證結果

| 項目 | 結果 |
|---|---|
| `vendor/bin/phpcs inc/` | **exit 0**，零錯誤 |
| `php -l` × 5 檔 | 全過 |
| `OrderLineItemSettlementHookTest` | **OK (15 tests, 100 assertions)** |
| `OrderItemSettlementRepositoryTest` | **OK (7 tests, 50 assertions)**（原 5 條 + 新增 2 條） |
| `@group` 陷阱檢查 | hook 測試檔內 `public function test_` **15** 個；`--testdox` 列出 **15** 條 ⇒ 無靜默跳過 |
| Unit testsuite（全跑） | 494 tests / 1133 assertions / **1 failure**（TD-2 pre-existing） |
| Integration testsuite（全跑） | 329 tests / **15～16 failures**（見下方說明） |

### `--testdox` 輸出（hook）

```
 ✔ Hook is registered by loader at priority 20
 ✔ Classic checkout writes all settlement meta
 ✔ Store api checkout writes settlement meta
 ✔ Tampered signature writes no settlement meta
 ✔ Missing signature writes no settlement meta
 ✔ Plain cart item is untouched
 ✔ Profit amount multiplies quantity
 ✔ Profit amount uses bcmath without float artifacts
 ✔ Profit amount rounds half up
 ✔ Zero rate still records attribution
 ✔ Deleted shop still records attribution with zero rate
 ✔ Unpublished shop still freezes rate
 ✔ Legacy v1 signature is accepted
 ✔ Legacy v2 signature is accepted
 ✔ V3 signature bound to wrong product is rejected
```

### 🔴 SQL 型別過濾的 red-green 實證（不是只憑推理）

把 `AND o.type = %s` / `AND p.post_type = %s` 兩行暫時中性化後重跑，兩條新測試**確實轉紅**：

```
✘ Find by partner excludes shop subscription line items
  Failed asserting that actual size 2 matches expected size 1.
✘ Find by shop excludes shop subscription line items
  Failed asserting that actual size 2 matches expected size 1.
```

`2 vs 1` 就是「訂閱商品分潤金額 ×2」的具體形狀。驗證後已還原並重跑 phpcs（exit 0）。

測試另有兩道**反假綠燈**保護，否則過濾被拿掉也會永遠綠：
1. helper 內直接讀 `wp_posts` 斷言該列真的是 `post_type='shop_subscription'` / `post_status='wc-active'`
2. 測試內先斷言 `find_by_order_item( $subscription_item_id )` **讀得回** `SettlementRecord`
   ⇒ 排除「fixture 根本沒寫進資料」這個假通過路徑

### Integration 的 failure 全部 pre-existing，且**會浮動**

實測連跑三次：**15 / 16 / 32** failures。穩定的那一群是既有的
`PartnerChangePasswordEndpointTest` ×8、`PartnerPasswordRotationRevokesTokenTest` ×1、
`PartnerReportsScopeTest` ×2、`CptRegistrarTest` ×1、`RewriteRulesTest` ×2、
`CptProfitShopRepositoryTest::test_save_round_trip_with_chinese_characters` ×1；
浮動的是 `ProfitShopCrudTest` / `DeletePartnerOnTrashedShopTest` 等——屬
`specs/tech-debt.md` TD-1 / TD-36 記載的 order-dependent flaky 家族。

**沒有任何一條落在本次改動的檔案內。** 兩個被懷疑的檔案已單獨隔離驗證：

- `WpSettlementSummaryProviderRealAggregationTest` → 單獨跑 **OK (19 tests, 248 assertions)**
- `CartPriceOverrideHookTest` → 單獨跑 **OK (50 tests, 219 assertions)**

⚠️ 那次 32 failures 的極端值有兩個外部成因，與程式碼無關：(a) 平行 agent 當下正在改
`V2Api.php` / `api.yml` 且新增 `ProfitSettlementsEndpointTest`，檔案在測試執行期間變動；
(b) 我一度同時跑兩個 phpunit 行程，MySQL 出現 `Deadlock found when trying to get lock`。
之後改為序列執行即回到 15。

---

## 3. 關鍵決策與理由

### 3.1 hook 選 `woocommerce_checkout_create_order_line_item`，priority **20**

它是**唯一**同時拿得到「cart item 陣列（含 `_profit_*` meta 與簽章）」與「對應 line item」的
擴充點。WooCommerce 10.1.4 逐行確認：`includes/class-wc-checkout.php:571` 觸發，而
Store API / Block 結帳（`src/StoreApi/Utilities/OrderController.php:789`）呼叫的是
`wc()->checkout->create_order_line_items()` ⇒ **三條結帳路徑共用同一個 hook**。

priority 20 的三個理由：與既有四個 hook 零衝突（它們掛在完全不同的擴充點）／晚於絕大多數
用預設 10 的擴充，因此 `get_quantity()` 讀到最終數量／不用 `PHP_INT_MAX`，留下 >20 給
「想在我方之後讀分潤 meta」的擴充。

### 3.2 ✅ 更正 1 已套用：`save_to_item()` + `add_meta_data( ..., true )`

`do_action(...)` 的**下一行**才是 `$order->add_item( $item )`，所以 `$item->get_id()` 恆為 0，
`save()` 走的 `wc_update_order_item_meta()` 會把 11 筆 meta 全寫到 `order_item_id = 0`
——永遠讀不到，而且不拋任何錯。

⇒ Repository 新增 `save_to_item( \WC_Order_Item_Product, SettlementRecord, string, string, string )`，
改用 `WC_Order_Item::add_meta_data( $key, $value, true )`（WooCommerce 自己在同一個方法內的
`set_backorder_meta()` 也是這樣做），meta 在 `$order->save()` 時一併落地。

- **`$unique = true` 未省略**，理由已寫進該方法 docblock：Store API 的
  `update_line_items_from_cart()`（`OrderController.php:786`）在 cart hash 變動時
  `remove_order_items('line_item')` 後重建，同一張草稿單可能觸發多次；沒有 unique 會累積重複
  meta，而 `WpSettlementSummaryProvider` 的三段 SQL 假設 `_settlement_status` 是 1:1
  INNER JOIN（該檔 `:226` 自己註明）⇒ **金額翻倍**。
- **未加進 `SettlementRepositoryInterface`**：Domain 層不得出現 `WC_Order_Item_Product`。
  因此 hook 注入的是**具體** `OrderItemSettlementRepository`（Infrastructure → Infrastructure，
  不違反分層）；其餘四個相依仍走 interface / Domain service。

### 3.3 ✅ 更正 2 已套用：`WcDecimalRounding` + `MoneyFormatter`

`RoundingStrategy` 在此之前 production 零實作，`ProfitCalculator` 因此無法實例化。

- `WcDecimalRounding`：**忠實遵守** interface 的 `$decimals` 參數，half-up，純 bcmath
  （`$value + 0.5×10⁻ᵈ` 後截斷；加法在「同時容得下兩者小數位」的 scale 下做，避免雙重截斷偏差；
  `-0.00` 正規化為 `0.00`）。非數字輸入 **fail-fast 拋例外**——這個值最終是付給夥伴的錢，
  悄悄變成 0 等於少付且查不出來。
- `MoneyFormatter::to_store()`（採納藍圖 D-2 建議）：hook 寫入前再 half-up 收斂到
  `wc_get_price_decimals()`。理由是 `WpSettlementSummaryProvider::format_money()` 加總走
  `bcadd( $v, '0', 2 )`——那是**無條件捨去**，單筆多存兩位會在加總時被靜默截掉，
  跨大量訂單累積成方向固定偏低的系統性少算；而且「逐列相加 ≠ 總計」是站長對帳時最先炸出來的。
- 位置放在 `Infrastructure/WooCommerce/`（不是 `Infrastructure/` 根目錄），因為「站台貨幣小數位」
  本身就是 WooCommerce 設定。⚠️ **平行 agent 若也要用，請 import 這個路徑，不要另建一份。**

### 3.4 ✅ 拍板已套用：賣場已刪除 → 寫完整歸屬 + `_profit_rate=0` + `_profit_amount='0.00'`

**不是**「完全不寫」（那是我上一版的做法，已改掉）。`CartPriceOverrideHook::apply_override()`
（priority 999）不重讀賣場，只驗簽章並對 frozen 的 `_profit_price_override` 套價
⇒ **顧客確實已經付了分潤價**；零紀錄會製造「顧客付了分潤價、系統查無此筆」。

- `_profit_shop_id` / `_profit_partner_term_id` / `_actual_price` 照實寫入（歸屬完整保留）
- `_profit_rate` 記 0：rate 只存在於賣場 CPT（cart meta 沒有、HMAC 也沒保護），
  賣場消失就沒有可信來源；0 是保守且**明顯異常**的值
- 同時寫一筆 `WC::logger( ..., 'error', [...], 'profit_shop' )`，訊息為
  「賣場 %d 在結帳當下已不存在，分潤比例以 0 記錄，需人工設定比例」，context 帶
  shop_id / partner_term_id / order_id / product_id / variation_id / actual_price，
  供結算頁標紅
- 回歸鎖：`test_deleted_shop_still_records_attribution_with_zero_rate`

**賣場只是被下架（draft）時走正常路徑**（`test_unpublished_shop_still_freezes_rate`）：
交易在賣場還上架時成立、rate 也讀得到，此時歸零等於讓「下架賣場」變成「抹掉夥伴已成交的分潤」。

### 3.5 `_actual_price` 取**簽章保護過的單價**，不是 `line_total / qty`

只有這個值有 HMAC 保護（用未受保護的衍生值等於讓 cart 操作影響撥款金額）／它是夥伴賣場
當時對外標示的成交依據／spec §5.2 的資料流本來就這樣寫。

⚠️ **仍需 team lead 拍板的取捨**：站長另外發的折價券會讓顧客實付低於此價，而分潤仍以此價計算
（目前視為站長吸收）。若要改成「以實付金額計算」，必須連同「那個值未受簽章保護」一起處理。

### 3.6 ✅ 追加範圍已套用：`query_order_item_ids()` 兩條 SQL 補訂單型別過濾

新增 `private const ORDER_POST_TYPE = 'shop_order'`，HPOS 分支加 `AND o.type = %s`、
legacy 分支加 `AND p.post_type = %s`（沿用既有 `p` alias，避免無謂 churn；兩者都走
`$wpdb->prepare` placeholder）。

成因與 red-green 實證見 §2。⚠️ **本輪只覆蓋 legacy 那條 SQL**——wp-env 的 `afterStart`
顯式把 `woocommerce_custom_orders_table_enabled` 設為 `no`，HPOS 分支的同款過濾目前只有
程式碼審查，未被自動化測試覆蓋（已寫進測試 docblock）。

### 3.7 `_override_signup_fee` 恆寫空字串

cart meta 沒有它、HMAC 沒簽過它 ⇒ 沒有可信來源；從賣場 `OverrideItem` 重讀則需要複製
`OverridePriceResolver` 的「變體優先、整組採用」分層邏輯，兩份實作分歧的代價是靜默寫錯欄位。
訂閱分潤是獨立工作項，屆時連同續訂價一起處理才有完整語意。欄位仍照 spec §2.5 寫出來。

### 3.8 整個 hook body 包 `try/catch(\Throwable)`

本 hook 在 `WC_Checkout::create_order_line_items()` 內執行，此刻顧客的款項流程已經啟動。
結算記帳失敗**絕不可以**讓例外冒出去中斷建單——那是「顧客付不了款」，比「少一筆結算紀錄」
嚴重得多。失敗一律寫 `error_log`（過濾 `\r \n \t` 防 log injection）。
`WcDecimalRounding` 的 fail-fast 例外正是靠這層收斂成「不寫任何 meta」。

---

## 4. 待處理 / 後續注意

### 4.1 🔴 驗章邏輯目前是**孿生實作**（最高優先的後續項）

`OrderLineItemSettlementHook::verify_cart_item()` 與 `CartPriceOverrideHook::verify_item()`
是兩份各自維護的版本分派 + HMAC 驗證。後者是 private，而該檔案是本次**不得改動**的安全核心，
無法直接複用。

兩者若分歧，症狀是「購物車套了覆寫價、訂單卻沒有分潤紀錄」，而且**兩邊都『正常運作』、
沒有任何錯誤訊息**——正是本 codebase 反覆吃虧的失效模式（Phase 13 的
`OverridePriceResolver` 就是為了消滅同型分歧而生）。

建議後續獨立一輪：抽出 `CartProfitMetaVerifier`（Infrastructure），讓兩個 hook 都委派同一份，
並保留現有兩邊的測試當回歸鎖。

### 4.2 HPOS 分支未被自動化測試覆蓋

見 §3.6。若 CI 之後加上 HPOS matrix，`test_find_by_partner_excludes_shop_subscription_line_items`
與 `test_find_by_shop_excludes_shop_subscription_line_items` 應會自動涵蓋兩條分支
（fixture 是直接寫 `wp_posts`，HPOS 開啟時需改寫成寫 `wc_orders`，屆時要調整 helper）。

### 4.3 本次**只做了「訂單建立」**這一段

`_settlement_status` 恆為 `pending`。退款回沖（spec §5.3）、取消回沖、訂閱續訂帶入（§5.4）、
撥款（§5.5）都是各自獨立的工作項。

⚠️ **訂閱續訂那一輪要特別注意**：本輪的 `ORDER_POST_TYPE = 'shop_order'` 過濾會把
`shop_subscription` 上的 line item 完全排除在結算查詢之外。續訂分潤的正確做法是讓
`wcs_renewal_order_created` 在**續訂產生的新 `shop_order`** 上寫一筆新的結算紀錄，
**不是**放寬這道過濾。

### 4.4 v1 / v2 簽章**不做**商品身分交叉比對

與 `CartPriceOverrideHook` 的取捨一致：那兩版的 payload 從未包含商品身分，簽章沒有保護過它，
比對零安全增益卻會誤殺既有購物車（TD-58 對 v2 仍成立）。v3 有做，且比對對象是**實際落進
訂單的那一筆 line item** 的 `product_id` / `variation_id`。

### 4.5 未做真實站台端到端驗證

本輪只有 wp-env 的整合測試（含 Store API 路徑）。真實站台（含 WooCommerce Subscriptions、
折價券、稅金、HPOS 開啟）的端到端下單驗證尚未執行。

### 4.6 測試對 Loader 註冊的依賴

`test_hook_is_registered_by_loader_at_priority_20` 依賴外掛 bootstrap 已跑過 `Loader`；
其餘 14 條也是靠全域註冊的 hook 生效（刻意不在測試內另建實例，避免重複註冊）。
這讓測試同時涵蓋「接線正確」，但也代表 Loader 若被改壞，這一整檔會一起紅燈——這是想要的行為。
