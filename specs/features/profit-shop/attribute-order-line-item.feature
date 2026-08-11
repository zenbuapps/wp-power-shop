@ignore @command
Feature: 結帳時把購物車的分潤歸屬寫進訂單品項

  顧客完成結帳的那一刻，購物車上那組受 HMAC 簽章保護的分潤 meta 必須被轉寫成
  訂單品項（line item）上的 11 筆結算 meta。這是分潤結算鏈路**唯一的資料來源**——
  這一步沒寫，夥伴報表永遠是空的、站長永遠無法撥款，而顧客**確實已經付了分潤價**。

  # ── 🔴 cart meta 與 order meta 不同名，不是直接複製 ─────────
  # 這是本 Feature 最高風險的一段。三個欄位改了名、三個欄位 cart 上根本沒有：
  #   cart _profit_price_override         → order _actual_price          （改名）
  #   cart _profit_regular_price_override → order _override_regular_price（改名）
  #   cart _profit_sale_price_override    → order _override_sale_price   （改名）
  #   order _profit_rate          ← 重讀賣場 CPT（cart 從未攜帶）
  #   order _profit_amount        ← 計算得出（cart 從未攜帶）
  #   order _settlement_status    ← 固定 'pending'（cart 從未攜帶）
  # 抄錯的症狀是「金額全 0 或欄位空白」，而且沒有任何錯誤訊息。
  #
  # ── 🔴 必須獨立重新驗章，不可假設「結算前已經驗過了」──────────
  # 有一種說法是：定價階段（before_calculate_totals priority 999）已經把偽造的 meta
  # strip 掉，所以結帳當下看到的一定是乾淨的。**這個論證的前提是「請求流程順序」，
  # 而流程順序不是隔離邊界**——同型的失效模式已經發生過（顯示層 filter 曾用
  # 「購物車請求不會走到顯示層」自我保證，實測被打臉）。獨立驗章是唯一的結構性保證。
  #
  # ── 🔴 寫入當下訂單品項還沒有 id ────────────────────────────
  # WooCommerce 發出這個 hook 的時機，早於把品項存進資料庫的那一行
  # （其 docblock 逐字寫著 "Action hook to adjust item before save"）。
  # 因此 $item->get_id() 為 0，必須用「往品項物件上掛 meta」的寫法，
  # 不能用「依 item id 更新 meta」的寫法——後者會把 meta 全部寫到 order_item_id=0，
  # 之後永遠讀不回來，而且不會拋任何錯。
  #
  # ── 🔴 同一筆 meta 只能存在一份 ─────────────────────────────
  # Block 結帳在購物車內容變動時會把草稿訂單的品項整批移除後重建，同一張訂單可能
  # 觸發本 hook 多次。結算查詢假設 _settlement_status 與品項是 1:1 關係，
  # 重複 meta 會讓 INNER JOIN 變成 1:N ⇒ 夥伴報表與結算金額直接翻倍。
  #
  # ── 已知且刻意的取捨 ────────────────────────────────────────
  # _override_signup_fee 讀的是「結帳當下」賣場設定的值，不是「加購當下」的值
  # （購物車從未攜帶這個欄位，無從凍結）。訂閱商品的 signup fee 若在顧客加購之後
  # 被站長改動，這個凍結值會與顧客實付金額不符。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name        | type   | regular_price |
      | 101 | MacBook Pro | simple | 300           |
      | 205 | 一般商品     | simple | 500           |
    And 已存在分潤賣場 "夏季賣場" 其設定為
      | id   | status  | partner_term_id | rate |
      | 1932 | publish | 5               | 20   |
    And 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "300" 與覆寫特價 "240"
    And 站台的價格小數位數為 2

  Rule: 前置（驗章）- 只有通過簽章驗證的購物車品項才寫入分潤 meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_tampered_signature_writes_no_settlement_meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_missing_signature_writes_no_settlement_meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_v3_signature_bound_to_wrong_product_is_rejected

    # 驗章邏輯必須與定價階段共用**同一份實作**，不各寫一份。
    # 兩份靠人維持一致的驗證邏輯一定會分歧，而分歧時兩邊都「正常運作」、沒有錯誤訊息。
    # 分歧的具體症狀：定價階段放行、結帳階段判為竄改 ⇒ 顧客付了分潤價、訂單零紀錄。

    Example: 簽章有效時寫入完整的分潤 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101 且簽章有效
      When 顧客完成結帳
      Then 該訂單品項應包含分潤結算 meta

    Example: 簽章被竄改時完全不寫入任何分潤 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      And 該購物車品項的 _profit_price_override 被竄改為 "1"
      When 顧客完成結帳
      Then 該訂單品項不應包含任何分潤結算 meta
      And 訂單仍應成立

    Example: 沒有簽章卻帶著分潤 meta 時完全不寫入
      Given 第三方外掛直接在購物車品項塞入 _profit_shop_id 為 1932 但未附簽章
      When 顧客完成結帳
      Then 該訂單品項不應包含任何分潤結算 meta

    Scenario Outline: 分潤 meta 不齊全時視同驗章失敗
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101 但缺少 <missing> 欄位
      When 顧客完成結帳
      Then 該訂單品項不應包含任何分潤結算 meta

      Examples:
        | missing                        |
        | _profit_shop_id                |
        | _profit_partner_term_id        |
        | _profit_price_override         |
        | _profit_signature              |

  Rule: 後置（對映）- 11 筆 order meta 的來源與命名必須逐欄位正確
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_classic_checkout_writes_all_settlement_meta

    # 這張表是本 Feature 的核心契約。驗收時必須**逐 key 斷言字面值**，
    # 不可用「常數比對常數」——那樣抄錯名字也會通過。

    Example: 有效的 v3 簽章寫入全部 11 筆 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101 且數量為 3
      And 該購物車品項的簽章版本為 "v3"
      When 顧客完成結帳
      Then 該訂單品項應包含以下 meta
        | key                     | value  |
        | _profit_shop_id         | 1932   |
        | _profit_partner_term_id | 5      |
        | _profit_rate            | 20     |
        | _override_regular_price | 300.00 |
        | _override_sale_price    | 240.00 |
        | _actual_price           | 240.00 |
        | _profit_amount          | 144.00 |
        | _settlement_status      | pending |
      And 該訂單品項的 _settled_at 應為空
      And 該訂單品項的 _settled_by 應為空

    Example: 實際扣款價取自驗章後的回傳值而非直接讀取 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      When 顧客完成結帳
      Then _actual_price 應等於驗章程序回傳的生效價格
      And 系統不應直接把購物車 meta 的字面值寫進 _actual_price

    Example: 分潤比例重新讀取賣場而非沿用購物車
      Given 顧客加購商品 101 之後站長把賣場 "夏季賣場" 的分潤比例改為 30
      When 顧客完成結帳
      Then 該訂單品項的 _profit_rate 應為 30

    Example: 訂閱加入費取自結帳當下的賣場設定
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫加入費 "99"
      When 顧客完成結帳
      Then 該訂單品項的 _override_signup_fee 應為 "99"

  Rule: 後置（金額）- 分潤金額必須以十進位運算求得
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_profit_amount_multiplies_quantity
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_profit_amount_uses_bcmath_without_float_artifacts
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_profit_amount_rounds_half_up

    # 公式：actual_price × qty × rate / 100
    # 🔴 禁止寫成浮點乘除。19.99 × 3 × 0.15 這種組合在浮點下就會漂，
    #    而金額漂掉幾分錢正是站長對帳時最先炸出來的東西。

    Scenario Outline: 分潤金額等於實際單價乘數量乘比例
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      And 該品項的實際單價為 "<price>" 且數量為 <qty>
      And 賣場 "夏季賣場" 的分潤比例為 <rate>
      When 顧客完成結帳
      Then 該訂單品項的 _profit_amount 應為 "<amount>"

      Examples:
        | price | qty | rate | amount |
        | 240   | 1   | 20   | 48.00  |
        | 240   | 3   | 20   | 144.00 |
        | 19.99 | 3   | 15   | 9.00   |
        | 100   | 1   | 0    | 0.00   |
        | 240   | 0   | 20   | 0.00   |

    Example: 分潤金額的小數位數與站台設定一致
      Given 站台的價格小數位數為 2
      When 顧客完成結帳且該品項可分潤
      Then _profit_amount 應為兩位小數的字串
      And 單筆金額的精度應與結算頁加總的精度相同

  Rule: 後置（版本）- 舊版簽章只還原它自己保護過的欄位
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_legacy_v1_signature_is_accepted
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_legacy_v2_signature_is_accepted

    # 簽章有 v1 / v2 / v3 三個版本並存。v1 的 payload 從未包含原價與特價，
    # 因此即使購物車上出現這兩筆 meta（可能是攻擊者注入），一律不予採信。

    Example: v1 簽章的覆寫原價與覆寫特價寫成空字串
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101 且簽章版本為 "v1"
      When 顧客完成結帳
      Then 該訂單品項的 _override_regular_price 應為 ""
      And 該訂單品項的 _override_sale_price 應為 ""
      And 該訂單品項的 _actual_price 仍應為 "240.00"

    Example: v1 簽章時注入的假原價不得被寫進訂單
      Given 顧客購物車內有一筆 v1 簽章的品項並被額外注入 _profit_regular_price_override 為 "99999"
      When 顧客完成結帳
      Then 該訂單品項的 _override_regular_price 應為 ""

    Example: v2 簽章寫入覆寫原價與覆寫特價
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101 且簽章版本為 "v2"
      When 顧客完成結帳
      Then 該訂單品項的 _override_regular_price 應為 "300.00"
      And 該訂單品項的 _override_sale_price 應為 "240.00"

  Rule: 後置（時機）- 訂單品項尚未持久化時仍必須寫入成功
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_classic_checkout_writes_all_settlement_meta

    Example: 寫入當下訂單品項的 id 為 0
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      When 系統在訂單品項尚未存檔前寫入分潤 meta
      Then 當下的訂單品項 id 應為 0
      And 訂單存檔後應能依 order_item_id 讀回完整的結算紀錄

    Example: 同一個訂單品項被重複處理時 meta 不重複累積
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      When 系統對同一個訂單品項連續寫入分潤 meta 兩次
      Then 每一個 meta key 在該訂單品項上皆應只有一筆
      And 結算查詢的金額不應變成兩倍

  Rule: 路徑（結帳）- 傳統結帳與 Block 結帳必須產生逐字相同的結果
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_classic_checkout_writes_all_settlement_meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_store_api_checkout_writes_settlement_meta

    # 目前這兩條路徑共用同一個 WooCommerce 方法，因此單一 hook 即可涵蓋。
    # 🔴 但「共用同一方法」是 WooCommerce 目前的**實作細節**，不是它承諾的契約。
    #    若日後被拆成兩份實作，分潤 meta 會在 Block 結帳（現代 WooCommerce 的預設
    #    結帳方式）**靜默消失**——那是「顧客付了分潤價、夥伴零紀錄」的無歸屬銷售，
    #    屬於安全等級的缺陷。因此這條 Rule 兩邊都要各驗一次。

    Example: 傳統結帳路徑寫入分潤 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      When 顧客以傳統結帳完成訂單
      Then 該訂單品項應包含 11 筆分潤結算 meta

    Example: Block 結帳路徑寫入完全相同的分潤 meta
      Given 顧客購物車內有一筆歸屬於賣場 1932 的商品 101
      When 顧客以 Block 結帳完成訂單
      Then 該訂單品項的 11 筆分潤結算 meta 應與傳統結帳逐欄位相同

  Rule: 錯誤（賣場已刪）- 賣場消失時仍必須留下歸屬紀錄
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_deleted_shop_still_records_attribution_with_zero_rate
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_zero_rate_still_records_attribution
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_unpublished_shop_still_freezes_rate

    # 決策 D-1：定價階段不重讀賣場（只驗簽章對凍結價格），所以顧客**確實付了分潤價**。
    # 「完全不寫」會造成「顧客付了分潤價、系統零紀錄」——那正是本鏈路要消滅的東西。
    # 因此改為寫入完整歸屬 + 比例 0，讓站長在結算頁看得到並人工處理。

    Example: 結帳前賣場被刪除時仍寫入歸屬但比例為零
      Given 顧客加購商品 101 之後賣場 "夏季賣場" 已被刪除
      When 顧客完成結帳
      Then 該訂單品項應包含以下 meta
        | key                     | value   |
        | _profit_shop_id         | 1932    |
        | _profit_partner_term_id | 5       |
        | _profit_rate            | 0       |
        | _profit_amount          | 0.00    |
        | _settlement_status      | pending |
      And 系統應寫入一筆錯誤紀錄且訊息中不含換行字元

    Example: 比例為零的紀錄在結算頁需要人工處理
      Given 存在一筆比例為 0 的結算紀錄
      When 站長開啟結算頁
      Then 該筆紀錄應被標示為需人工設定比例

  Rule: 錯誤（降級）- 任何失敗一律靜默降級，永不中斷顧客結帳
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_exception_during_freeze_never_breaks_checkout

    Example: 寫入分潤 meta 失敗時訂單仍然成立
      Given 寫入分潤 meta 的過程將拋出例外
      When 顧客完成結帳
      Then 訂單應正常成立
      And 系統應寫入一筆錯誤紀錄
      And 顧客不應看到任何錯誤訊息

  Rule: 環境（後台）- 後台情境同樣必須寫入分潤 meta
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_hook_still_writes_meta_in_admin_context

    # 🔴 不可照抄購物車 hook 的「後台且非 AJAX 就不掛載」守門。
    #    退款一定發生在後台；站長手動建單也在後台。照抄的症狀是
    #    「後台手動建立的訂單完全沒有分潤紀錄」，而且沒有任何錯誤訊息。

    Example: 後台建立訂單時仍寫入分潤 meta
      Given 目前為 WordPress 後台的訂單編輯畫面
      And 訂單品項來自一筆歸屬於賣場 1932 的購物車品項
      When 系統建立該訂單品項
      Then 該訂單品項應包含 11 筆分潤結算 meta

  Rule: 後置（隔離）- 非分潤商品不受任何影響
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OrderLineItemSettlementHookTest.php::test_plain_cart_item_is_untouched

    Example: 一般商品的訂單品項零副作用
      Given 顧客購物車內有一筆未歸屬任何賣場的商品 205
      When 顧客完成結帳
      Then 該訂單品項不應包含任何以 _profit_ 或 _settlement_ 開頭的 meta
      And 該訂單品項不應包含 _actual_price

    Example: 同一張訂單內分潤與非分潤品項各自獨立
      Given 顧客購物車內同時有商品 101（歸屬賣場 1932）與商品 205（未歸屬）
      When 顧客完成結帳
      Then 商品 101 的訂單品項應包含完整分潤結算 meta
      And 商品 205 的訂單品項不應包含任何分潤結算 meta
