@ignore @command
Feature: 建立與更新分潤賣場

  店舖管理者在後台建立分潤賣場，設定名稱、永久連結、呈現模式、分潤夥伴、
  分潤比例與賣場商品，並在編輯頁隨時修改。

  # 賣場商品的價格覆寫細節見 save-shop-item-overrides.feature（商品層）
  # 與 save-variation-overrides.feature（變體層）；本 Feature 只涵蓋賣場本體欄位。
  #
  # ── 呈現模式 ─────────────────────────────────────────────
  #   page      —— 獨立頁面模式，賣場有自己的公開網址
  #   shortcode —— 短碼模式，站長把短碼貼進任意頁面
  # ⚠️ 兩種模式的**公開網址都開得起來**——渲染流程完全不檢查 mode
  #    （specs/tech-debt.md TD-13）。因此編輯頁對 shortcode 模式的據實告知警語
  #    不可移除：UI 入口有無，與網址本身是否存在，是兩件事。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name        | type     | status  |
      | 101 | MacBook Pro | simple   | publish |
      | 103 | iPad Air    | variable | publish |
    And 商品 103 具有變體 1031

  Rule: 前置（參數）- 分潤比例必須介於 0 到 100

    Scenario Outline: 超出範圍的分潤比例被拒絕
      When 管理者 "Admin" 以分潤比例 <rate> 建立賣場
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

      Examples:
        | rate |
        | -1   |
        | 101  |

    Example: 邊界值 0 與 100 皆為合法
      When 管理者 "Admin" 以分潤比例 0 建立賣場
      Then 系統應回傳建立成功

  Rule: 前置（參數）- 分潤夥伴必須存在

    Example: 指定不存在的夥伴時拒絕建立
      When 管理者 "Admin" 以 partner_term_id 999999 建立賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 前置（參數）- 賣場商品的商品與變體必須存在且對應正確

    Example: 商品不存在時拒絕儲存
      When 管理者 "Admin" 建立賣場且商品列表包含 product_id 999999
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

    Example: 變體不屬於該商品時拒絕儲存
      When 管理者 "Admin" 建立賣場且為商品 101 設定變體 1031 的覆寫
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

  Rule: 前置（衝突）- 建立時必須檢查永久連結衝突

    Example: 永久連結與既有資源衝突時拒絕建立
      Given 站台已存在 slug 為 "summer-sale" 的頁面
      When 管理者 "Admin" 以 slug "summer-sale" 建立賣場
      Then 系統應回傳錯誤
        | status | code          |
        | 409    | slug_conflict |
      And 回應應包含 conflicts 清單

  Rule: 前置（衝突）- 更新時只有在永久連結真的改動才檢查衝突

    # 否則賣場會跟自己衝突。

    Example: 未改動永久連結的更新不觸發衝突檢查
      Given 已存在 slug 為 "summer-sale" 的賣場 "夏季賣場"
      When 管理者 "Admin" 只修改賣場 "夏季賣場" 的名稱後儲存
      Then 系統應回傳更新成功

    Example: 改動永久連結為已被佔用的值時拒絕更新
      Given 已存在 slug 為 "summer-sale" 的賣場 "夏季賣場"
      And 站台已存在 slug 為 "winter-sale" 的頁面
      When 管理者 "Admin" 將賣場 "夏季賣場" 的 slug 改為 "winter-sale"
      Then 系統應回傳錯誤
        | status | code          |
        | 409    | slug_conflict |

  Rule: 後置（持久化）- 更新採整體覆寫語意且必須可原樣讀回

    Example: 更新後重新查詢應還原相同內容
      Given 已存在分潤賣場 "夏季賣場"
      When 管理者 "Admin" 更新賣場 "夏季賣場" 為
        | title      | slug        | mode | partner_term_id | rate |
        | 夏季大促銷  | summer-2026 | page | 5               | 15   |
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then 回應應為
        | title      | slug        | mode | partner_term_id | rate |
        | 夏季大促銷  | summer-2026 | page | 5               | 15   |

    Example: 更新不存在的賣場回 404
      When 管理者 "Admin" 更新 id 為 999999 的賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（順序）- 賣場商品的陣列順序即前台顯示順序

    # 順序本身就是業務資料，不是實作細節。
    # 任何一層改用 product_id 當鍵、或中途排序，拖曳排序就會靜默失效
    # （存檔回 200、查詢也拿得到資料，只是順序跑掉）。

    Example: 送出的順序必須原樣保存
      When 管理者 "Admin" 以下列順序儲存賣場商品
        | product_id |
        | 103        |
        | 101        |
      And 管理者 "Admin" 重新查詢該賣場
      Then 賣場商品列表的順序應為
        | product_id |
        | 103        |
        | 101        |

    Example: 重新排序後新順序取代舊順序
      Given 賣場商品順序為 101、103
      When 管理者 "Admin" 將順序調整為 103、101 後儲存
      And 管理者 "Admin" 重新查詢該賣場
      Then 賣場商品列表的第一筆應為商品 103

  Rule: 後置（設定）- settings 為自由欄位，送出時必須以既有內容為底合併

    # settings 內同時放著前台顯示開關、排程與 legacy 匯入帶進來的未知鍵。
    # 只送部分欄位會把其餘的整批清掉，而且沒有任何錯誤訊息。

    Example: 只更新排程欄位時其餘設定必須保留
      Given 賣場 "夏季賣場" 的 settings 已包含 showStock 與 showBuyerCount
      When 管理者 "Admin" 只修改開賣時間後儲存
      And 管理者 "Admin" 重新查詢賣場 "夏季賣場"
      Then settings 應仍包含 showStock 與 showBuyerCount

  Rule: 前端（表單）- 多分頁表單的驗證失敗必須導向出錯的分頁

    # 非作用中的分頁是隱藏的，欄位驗證有跑、紅字有渲染，但使用者看不到。
    # 若父層只是靜默 return，畫面上完全沒有任何回饋——使用者會回報「按了建立沒反應」。

    Example: 驗證失敗時自動切換到出錯的分頁並提示
      Given 管理者 "Admin" 停留在「分潤設定」分頁
      And 「基本設定」分頁的永久連結欄位未填
      When 管理者 "Admin" 點擊「建立」
      Then 畫面應切換到「基本設定」分頁
      And 畫面應顯示尚有欄位未填寫的提示

  Rule: 已知落差（驗證）- 永久連結在後端沒有必填與格式驗證

    # ⚠️ specs/tech-debt.md TD-14：前端 required、後端不驗。
    #   繞過前端直接呼叫 API 可建出 slug 為空的賣場，其公開網址會是打不開的
    #   `/profit-shop//`。目前不影響後台操作，但違反「後端驗證才是唯一真相」的紀律。

    Example: 直接以 API 送出空的永久連結會被接受
      When 直接以 API 建立賣場且 slug 為空字串
      Then 系統應回傳建立成功

  Rule: 已知落差（數量）- 賣場商品數量上限只在前端把關

    # ⚠️ 單次批次 100 筆與賣場總數 500 筆都只在前端擋，後端無對應驗證。
    #   繞過前端直接呼叫 API 可以送出超過 500 筆的商品陣列而不被拒絕。

    Example: 直接以 API 送出超過 500 筆商品會被接受
      When 直接以 API 儲存含 600 筆商品的賣場
      Then 系統應回傳成功
</content>
