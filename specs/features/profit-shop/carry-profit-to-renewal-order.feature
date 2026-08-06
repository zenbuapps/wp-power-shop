@ignore @command
Feature: 訂閱續訂單帶入分潤結算資訊

  訂閱商品每次自動續扣都會產生一張新的續訂單，那張訂單同樣是一筆真實成交，
  夥伴同樣該拿到分潤。但續訂單不經過購物車，因此沒有任何購物車 meta 可以驗章——
  歸屬必須從母訂單繼承。

  # ── 🔴 本 Feature 的職責是「重置」，不是「複製」──────────────
  # WooCommerce Subscriptions 建立續訂單時，會把母訂單品項的 meta **整組複製**過去
  # （其 docblock 逐字寫著會複製 "order items and order item meta"）。
  # 也就是說續訂單一出生就可能帶著：
  #   _settlement_status = 'paid'（母訂單那期的分潤早就撥過款了）
  #   _settled_at / _settled_by（上一期的撥款時間與操作者）
  # ⇒ 站長會在結算頁看到一筆「已撥款」的新分潤，於是**永遠不會撥款給夥伴**，
  #    或者更糟：以為已經付過，實際上夥伴這期一毛都沒拿到。
  # 因此本 hook 真正要做的是把那些不該繼承的欄位清乾淨。
  #
  # ── 凍結 vs 動態 ────────────────────────────────────────────
  #   凍結（沿用母訂單）：_profit_shop_id、_profit_partner_term_id
  #     賣場改名、夥伴改名都不影響已成立的歸屬。
  #   動態（重讀賣場）：_profit_rate
  #     站長隨時可調整分潤比例，續訂應該用當下的最新值。
  #   重算：_actual_price、_profit_amount
  #     續訂的實付金額由 WooCommerce Subscriptions 決定（可能與首期不同）。
  #   強制重置：_settlement_status = 'pending'
  #   強制刪除：_settled_at、_settled_by
  #
  # ── 🔴 這個 hook 是 filter 不是 action ──────────────────────
  # wcs_renewal_order_created 是以 apply_filters 發出的，callback **必須回傳續訂單物件**。
  # 漏了 return，建立續訂單的函式會拿到 null ⇒ **整條訂閱續訂鏈路壞掉**，
  # 影響範圍遠大於分潤（顧客的訂閱會停止續期）。
  #
  # ── 未安裝 WooCommerce Subscriptions 時 ─────────────────────
  # 整段功能不註冊。若無條件註冊並引用外掛函式，會在未安裝的站台造成致命錯誤，
  # 整個 power-shop 外掛掛掉。

  Background:
    Given 站台已安裝並啟用 WooCommerce Subscriptions
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932 且分潤比例為 20
    And 已存在母訂單 8001 其品項 7001 的分潤結算 meta 為
      | key                     | value  |
      | _profit_shop_id         | 1932   |
      | _profit_partner_term_id | 5      |
      | _profit_rate            | 20     |
      | _actual_price           | 240.00 |
      | _profit_amount          | 48.00  |
      | _settlement_status      | paid   |
      | _settled_at             | 1754400000 |
      | _settled_by             | 1      |

  Rule: 後置（凍結）- 賣場與夥伴的歸屬沿用母訂單不重新判定

    Example: 續訂單沿用母訂單的賣場與夥伴
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_shop_id 應為 1932
      And 續訂單品項的 _profit_partner_term_id 應為 5

    Example: 賣場改名後續訂單仍歸屬同一個賣場
      Given 賣場 "夏季賣場" 已改名為 "秋季賣場"
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_shop_id 應為 1932

    Example: 賣場已下架時仍沿用歸屬
      Given 賣場 "夏季賣場" 的狀態為 "draft"
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_shop_id 應為 1932
      And 續訂單品項的 _profit_partner_term_id 應為 5

  Rule: 後置（動態）- 分潤比例重讀賣場的最新設定

    Example: 站長調高比例後續訂單採用新比例
      Given 賣場 "夏季賣場" 的分潤比例已改為 30
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_rate 應為 30

    Example: 賣場已被刪除時退回母訂單的凍結比例
      Given 賣場 "夏季賣場" 已被刪除
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_rate 應為 20
      And 系統應寫入一筆錯誤紀錄且訊息中不含換行字元

  Rule: 後置（重算）- 實際單價與分潤金額依續訂價格重新計算

    Example: 續訂價格與首期不同時重新計算分潤
      Given 該訂閱的續訂單價為 200 且數量為 1
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _actual_price 應為 "200.00"
      And 續訂單品項的 _profit_amount 應為 "40.00"

    Example: 續訂數量大於一時分潤金額按數量計算
      Given 該訂閱的續訂單價為 200 且數量為 3
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_amount 應為 "120.00"

    Example: 比例退回零時分潤金額為零
      Given 賣場 "夏季賣場" 的分潤比例已改為 0
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _profit_amount 應為 "0.00"

  Rule: 安全（重置）- 撥款狀態絕不可從母訂單繼承

    # 這是本 Feature 存在的主要理由。少了這一段，站長會對同一筆分潤重複撥款，
    # 或以為已撥款而讓夥伴永遠拿不到這期的錢——兩種結果都沒有任何錯誤訊息。

    Example: 續訂單的結算狀態強制為待結算
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _settlement_status 應為 "pending"

    Example: 續訂單不得帶有母訂單的撥款時間與操作者
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _settled_at 應為空
      And 續訂單品項的 _settled_by 應為空

    Scenario Outline: 母訂單為任何狀態時續訂單一律重置為待結算
      Given 母訂單 8001 的品項 7001 其 _settlement_status 為 "<parent_status>"
      When 系統為母訂單 8001 建立續訂單
      Then 續訂單品項的 _settlement_status 應為 "pending"

      Examples:
        | parent_status |
        | pending       |
        | paid          |
        | refunded      |
        | cancelled     |

  Rule: 後置（契約）- 掛載點是 filter，必須回傳續訂單物件

    Example: 處理完成後回傳續訂單本身
      When 系統為母訂單 8001 建立續訂單
      Then 掛載點應回傳該續訂單物件
      And 續訂流程應正常完成

    Example: 處理過程發生例外時仍回傳續訂單物件
      Given 重讀賣場的過程將拋出例外
      When 系統為母訂單 8001 建立續訂單
      Then 掛載點應回傳該續訂單物件
      And 續訂流程應正常完成
      And 系統應寫入一筆錯誤紀錄

  Rule: 後置（隔離）- 沒有分潤歸屬的續訂單不受影響

    Example: 母訂單沒有分潤 meta 時續訂單也不寫入
      Given 已存在母訂單 8002 其品項皆無分潤結算 meta
      When 系統為母訂單 8002 建立續訂單
      Then 續訂單品項不應包含任何分潤結算 meta

    Example: 同一張續訂單內只處理帶有分潤歸屬的品項
      Given 母訂單 8001 另有品項 7002 且無任何分潤結算 meta
      When 系統為母訂單 8001 建立續訂單
      Then 對應品項 7001 的續訂品項應包含完整分潤結算 meta
      And 對應品項 7002 的續訂品項不應包含任何分潤結算 meta

  Rule: 環境（相依）- 未安裝訂閱外掛時整段功能不註冊

    Example: 未安裝訂閱外掛時不掛載續訂處理
      Given 站台未安裝 WooCommerce Subscriptions
      Then 系統不應註冊續訂單分潤處理的掛載點
      And 外掛應正常載入
