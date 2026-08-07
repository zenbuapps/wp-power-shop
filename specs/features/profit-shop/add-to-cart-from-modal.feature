@ignore @command
Feature: 從商品 Modal 非同步加入購物車

  顧客在商品 Modal 按下「加入購物車」時不換頁，按鈕顯示載入狀態，
  成功後關閉 Modal 並顯示成功通知。

  # ── 通道選擇是本 Feature 的核心決策 ──────────────────────
  # 走 **WooCommerce 原生的非同步加購端點**，本外掛零新增端點。五條判準：
  #   1. 必須填充 $_POST —— 送 form-urlencoded，分潤四道閘門讀得到賣場識別
  #   2. 不新增自訂端點 —— 是 WooCommerce 內建動作
  #   3. 變體商品可用 —— 端點自己把變體換算回父商品與屬性，換算**之後**才觸發
  #                      購物車資料 filter，因此賣場成員資格閘門仍對得上
  #   4. 未登入訪客可用 —— 在允許未登入的動作清單內且不驗 nonce
  #   5. 改動範圍最小 —— 零新端點、零新套件、零行分潤 hook 變更
  #
  # 🔴 **絕不可改送 JSON body**：PHP 不會填 $_POST，分潤會靜默失效。
  # 🔴 **絕不可改用加購函式的購物車資料參數傳賣場識別**：那會跳過全部四道閘門，
  #    變成任何人都能對任意商品套任意賣場的分潤價。
  #
  # ── 變體商品送的是「選中變體自己的 ID」 ───────────────────
  # 這條通道**不送也不需要**屬性欄位（端點自己從變體物件反查）。
  # Modal 內既有的變體與屬性隱藏欄位一律保留不動——它們是降級路徑所必需。

  Background:
    Given 已存在 status 為 publish 且在販售期間內的分潤賣場 "夏季賣場" 其 id 為 1932
    And 賣場 "夏季賣場" 的商品列表包含 simple 商品 101 與可變商品 103
    And 顧客為未登入的訪客

  Rule: 後置（成功）- 加購成功時不換頁、關閉 Modal 並顯示成功通知
    # @covered-by: tests/js/addToCartFromModal.test.ts::成功後關閉 Modal 並顯示 toast

    Example: simple 商品的非同步加購
      When 顧客在商品 101 的 Modal 按下「加入購物車」
      Then 頁面不應重新載入
      And 商品 101 應被加入購物車且歸屬於賣場 1932
      And 商品 101 的 Modal 應自動關閉
      And 畫面應顯示加入購物車成功的通知

    Example: 送出的內容必須是表單編碼且只含三個欄位
      When 顧客在商品 101 的 Modal 按下「加入購物車」
      Then 請求的內容型別應為表單編碼
      And 請求內容應只包含商品識別、數量與賣場識別

    Example: 變體商品送出的是選中變體的識別
      Given 顧客已在商品 103 的 Modal 選中變體 1031
      When 顧客按下「加入購物車」
      Then 送出的商品識別應為 1031
      And 加入購物車的品項應歸屬於賣場 1932

    Example: 成功後同步更新迷你購物車
      When 顧客完成一次非同步加購
      Then 站台的迷你購物車應反映最新的購物車內容

    Example: 成功通知必須浮在佈景主題的固定頁首之上
      When 顧客完成一次非同步加購
      Then 成功通知應顯示在畫面上方且不被頁首遮蔽

  Rule: 後置（載入）- 送出期間必須有明確的載入狀態
    # @covered-by: tests/js/addToCartFromModal.test.ts::送出後按鈕進入忙碌狀態

    Example: 送出期間按鈕停用並顯示載入指示
      When 顧客按下「加入購物車」且請求尚未回應
      Then 加購按鈕應為停用狀態
      And 加購按鈕應顯示載入指示

  Rule: 安全（重送）- 飛行中必須擋住第二次送出
    # @covered-by: tests/js/addToCartFromModal.test.ts::第一次請求還沒回來時，第二次送出不得再發 fetch
    # @covered-by: tests/js/addToCartFromModal.test.ts::飛行中送出的事件仍必須被 preventDefault（不得退回原生表單送出）

    # 原生表單沒有這個問題（送出後整頁導航、DOM 直接消失）。
    # 🔴 少了「切換變體時的解鎖也要尊重飛行中旗標」這一條，
    #    使用者在請求飛行中改選變體會觸發重繪 → 把按鈕解鎖 → 再按一次 → 兩筆都成功。

    Example: 連續點擊只會送出一次
      When 顧客連續點擊「加入購物車」3 次
      Then 系統應只送出 1 次加購請求
      And 購物車中該商品的數量應只增加 1

    Example: 飛行中切換變體不得解鎖按鈕
      Given 顧客的加購請求正在飛行中
      When 顧客改選另一個變體
      Then 加購按鈕應維持停用狀態

  Rule: 安全（重送）- 任何失敗都不得自動重送表單
    # @covered-by: tests/js/addToCartFromModal.test.ts::業務失敗回應不得觸發第二次 fetch
    # @covered-by: tests/js/addToCartFromModal.test.ts::HTTP 失敗不得觸發第二次 fetch
    # @covered-by: tests/js/addToCartFromModal.test.ts::fetch reject 不得觸發第二次 fetch

    # 訊息機制沒有去重，重送會讓同一則錯誤顯示兩次；
    # 邊界情況（第一次其實成功了）更會造成**重複加購、顧客被重複收費**。

    Example: 業務失敗時依官方契約導向商品頁而非重送
      Given 商品 101 的庫存不足
      When 顧客按下「加入購物車」
      Then 系統不應重新送出表單
      And 畫面應導向回應內指定的商品頁

    Example: 回應無法解析時凍結按鈕並提示狀態未知
      # 這種情況下商品**很可能已經在購物車裡**，慫恿重試就是慫恿重複付款。
      When 加購請求已送出但回應無法解析為預期格式
      Then 加購按鈕應被凍結
      And 畫面應提示無法確認加購結果並建議重新整理或查看購物車
      And 畫面不應提示「請再試一次」

    Example: 請求根本沒送出時允許重試
      When 加購請求因連線問題而完全沒有送出
      Then 畫面應提示連線問題
      And 加購按鈕應恢復為可用狀態

    Example: 成功後的附帶動作出錯不得被誤報為加購失敗
      # 成功後會同步觸發佈景主題與其他外掛掛在購物車事件上的處理，
      # 任一處理丟錯若冒泡到錯誤分支，就會把**已經成功**的加購誤報成連線問題。
      Given 佈景主題掛在購物車更新事件上的處理會拋出錯誤
      When 顧客完成一次加購
      Then 畫面仍應顯示加入購物車成功的通知

  Rule: 降級（漸進增強）- 四道關卡任一不成立就退回原生表單送出
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_open_shop_renders_ajax_dom_contract
    # @covered-by: tests/js/addToCartFromModal.test.ts::缺 addToCartUrl 時完全不攔截送出
    # @covered-by: tests/js/addToCartFromModal.test.ts::缺 profit_shop_id 時退回原生表單送出（不發 AJAX）
    # @covered-by: tests/js/addToCartFromModal.test.ts::商品型別不在 AJAX 白名單時退回原生表單送出
    # @covered-by: tests/js/addToCartFromModal.test.ts::variable 商品尚未選出變體時退回原生表單送出

    Scenario Outline: 依環境與商品狀態決定是否走非同步加購
      Given <condition>
      When 顧客按下「加入購物車」
      Then 加購方式應為 <method>

      Examples:
        | condition                    | method     | 說明                             |
        | 瀏覽器停用 JavaScript          | 原生表單    | 關卡 1                           |
        | 瀏覽器不支援原生對話框元素      | 原生表單    | 關卡 2，卡片動作生效               |
        | 瀏覽器缺少非同步請求能力        | 原生表單    | 關卡 3                           |
        | 商品類型不在支援清單內          | 原生表單    | 關卡 4，省一次無謂往返             |
        | 顧客尚未選滿變體屬性            | 原生表單    | 關卡 4                           |
        | 選中的變體含任意屬性            | 原生表單    | 關卡 4，見下方 Rule               |
        | 表單缺少賣場識別               | 原生表單    | 關卡 4，寧可換頁也不送無歸屬的加購  |

    Example: 降級時不得攔截瀏覽器的預設送出
      Given 組不出非同步加購所需的內容
      When 顧客按下「加入購物車」
      Then 瀏覽器應照常執行原生表單送出

  Rule: 降級（任意屬性）- 選中含任意屬性的變體必須走原生表單
    # @covered-by: tests/js/addToCartFromModal.test.ts::選中含 Any 屬性的變體時不發 AJAX
    # @covered-by: tests/js/addToCartFromModal.test.ts::全具體變體走 AJAX，且 product_id 送的是變體 ID
    # @covered-by: tests/js/addToCartFromModal.test.ts::POST body 必須是 form-urlencoded（否則 PHP 不會填 $_POST、分潤靜默失效）

    # 🔴 這是**正確**的降級，不是回歸。
    #   非同步端點全文不讀送來的屬性欄位，它一律自己從變體物件反查——
    #   對任意屬性拿回空字串後被 WooCommerce 判為缺少必填屬性而拒絕。
    #   原生表單送的是使用者實際選的值，不受影響。
    #   不要因為「明明做了非同步加購，怎麼這個商品還在換頁」就把這道降級拿掉：
    #   拿掉之後那筆加購會被直接拒絕，使用者看到的是「按了沒反應」。

    Example: 含任意屬性的變體加購會換頁且沒有成功通知
      Given 顧客選中的變體含有任意屬性
      When 顧客按下「加入購物車」
      Then 頁面應重新載入
      And 畫面不應顯示非同步的成功通知

  Rule: 安全（歸屬）- 非同步通道仍完整經過四道分潤閘門
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php::test_add_to_cart_with_nonexistent_profit_shop_id_falls_back_to_original_price

    Example: 帶合法賣場識別時套用覆寫價
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "300" 與特價 "240"
      When 顧客從賣場 1932 的 Modal 非同步加購商品 101
      Then 該購物車品項的價格應為 240 且劃線原價為 300

    Example: 不帶賣場識別時走商品原價
      When 顧客以不帶賣場識別的請求非同步加購商品 101
      Then 該購物車品項應以商品原價計價

    Example: 偽造賣場識別時走商品原價
      When 顧客以偽造的賣場識別 999999 非同步加購商品 101
      Then 該購物車品項應以商品原價計價

  Rule: 錯誤（就地）- 失敗訊息顯示在 Modal 內而非關閉 Modal
    # @covered-by: tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php::test_open_shop_renders_ajax_dom_contract

    Example: 失敗時 Modal 不關閉且就地顯示錯誤
      When 顧客的加購因故失敗
      Then 商品 Modal 應維持開啟
      And 錯誤訊息應顯示在加購按鈕下方
      And 錯誤區塊應提供「查看購物車」的連結

  Rule: 已知落差（訊息）- 業務失敗的原因必須離開賣場頁才看得到
    # @covered-by: NOT-COVERED 本 Rule 描述的正是 TD-41「業務失敗原因須離開賣場頁才看得到」這個已知落差，依定義沒有對應實作可測

    # ⚠️ specs/tech-debt.md TD-41：非同步端點的失敗分支只回「失敗」與商品頁網址，
    #   真正的原因寫在 session 訊息裡，回應之前沒有任何可夾帶訊息的擴充點。
    #   要「不換頁還能顯示原因」就必須自建一條讀取訊息的通道——
    #   那等於復活刻意刪除的自訂購物車 API 的一小塊。

    Example: 庫存不足時顧客必須到商品頁才看得到原因
      Given 商品 101 的庫存不足
      When 顧客按下「加入購物車」
      Then 畫面應導向商品 101 的商品頁
      And 賣場頁上不會顯示庫存不足的原文訊息

  Rule: 已知落差（第三方）- 購物車已有訂閱商品時非同步加購可能回傳非預期格式
    # @covered-by: NOT-COVERED 本 Rule 描述的正是 TD-42「WooCommerce Subscriptions 在 fragments 掛不存在的靜態方法」這個已知落差，依定義沒有對應實作可測

    # ⚠️ specs/tech-debt.md TD-42：第三方訂閱外掛在購物車片段事件上掛了一個
    #   不存在的方法，在特定情境會讓回應變成非 JSON。
    #   這條無法在本外掛內修（是第三方外掛的缺陷），
    #   且在改走非同步通道之前不會被踩到。
    #   緩解已內建於前端：歸類為「狀態未知」並凍結按鈕，
    #   因為加購在該事件之前就已完成，商品其實已經在購物車裡。

    Example: 購物車已有訂閱商品時加購一般商品的回應可能異常
      Given 購物車中已有訂閱商品且站台未啟用混合購買
      When 顧客從賣場 Modal 非同步加購一般商品
      Then 前端應歸類為狀態未知並凍結按鈕
      And 商品應已在購物車中
</content>
