@ignore @command
Feature: 變更分潤賣場的上下架狀態與複製賣場

  店舖管理者在賣場列表或編輯頁執行上架、下架、複製與刪除。
  賣場狀態直接決定「公開網址開不開得起來」與「加購時分潤歸屬成不成立」。

  # ── 狀態的下游效果（不可只當成後台欄位看待）─────────────────
  #   publish —— 公開網址對所有人開放；加購時分潤歸屬成立
  #   draft   —— 公開網址僅對有該筆編輯權限者開放預覽；加購時分潤歸屬**不**成立
  #   trash   —— 公開網址一律 404
  #
  # draft 賣場常被站長當作促銷預覽使用，其覆寫價不得洩漏到顧客購物車。

  Background:
    Given 管理者 "Admin" 已登入後台
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932 且狀態為 "draft"

  Rule: 後置（上架）- 上架把狀態改為 publish 且為冪等操作

    Example: 草稿賣場上架後狀態為 publish
      When 管理者 "Admin" 上架賣場 "夏季賣場"
      Then 賣場 "夏季賣場" 的狀態應為 "publish"

    Example: 已上架的賣場再次上架不報錯
      Given 賣場 "夏季賣場" 的狀態為 "publish"
      When 管理者 "Admin" 上架賣場 "夏季賣場"
      Then 系統應回傳成功
      And 賣場 "夏季賣場" 的狀態應為 "publish"

    Example: 上架不存在的賣場回 404
      When 管理者 "Admin" 上架 id 為 999999 的賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（下架）- 下架把狀態改為 draft 且為冪等操作

    Example: 已上架的賣場下架後狀態為 draft
      Given 賣場 "夏季賣場" 的狀態為 "publish"
      When 管理者 "Admin" 下架賣場 "夏季賣場"
      Then 賣場 "夏季賣場" 的狀態應為 "draft"

    Example: 已是草稿的賣場再次下架不報錯
      When 管理者 "Admin" 下架賣場 "夏季賣場"
      Then 系統應回傳成功

  Rule: 後置（下架）- 下架後公開網址關閉但已在購物車的品項不受追溯影響

    # 「加入購物車 = 價格已鎖定」，活動結束那秒靜默清空所有顧客的購物車比放行更糟。
    # 唯一例外是「只靠賣場覆寫價才有價格」的品項，見
    # authorize-priceless-cart-item.feature 的「授權必須可撤銷」Rule。

    Example: 下架後一般訪客開啟公開網址得到 404
      Given 賣場 "夏季賣場" 的狀態為 "publish"
      When 管理者 "Admin" 下架賣場 "夏季賣場"
      And 未登入的訪客開啟賣場 "夏季賣場" 的公開網址
      Then 系統應回傳 404

    Example: 下架後新的加購不再成立分潤歸屬
      Given 賣場 "夏季賣場" 的狀態為 "draft"
      When 顧客帶著賣場 1932 的識別加購該賣場的商品
      Then 該購物車品項不應歸屬於任何賣場

    Example: 下架前已加入購物車且商品自身有價格的品項仍保留覆寫價
      Given 顧客已從 publish 狀態的賣場 1932 加購自身有價格的商品
      When 管理者 "Admin" 下架賣場 "夏季賣場"
      And 顧客重新載入購物車
      Then 該品項應仍在購物車中且維持覆寫價

  Rule: 後置（複製）- 複製產生一份草稿副本且不影響來源賣場

    Example: 複製後產生新的草稿賣場
      Given 賣場 "夏季賣場" 的狀態為 "publish" 且分潤比例為 15
      When 管理者 "Admin" 複製賣場 "夏季賣場"
      Then 應產生一個新的賣場
      And 新賣場的狀態應為 "draft"
      And 新賣場的分潤比例應為 15
      And 新賣場的分潤夥伴應與來源相同

    Example: 複製後的名稱與永久連結加上可辨識的後綴
      When 管理者 "Admin" 複製賣場 "夏季賣場"
      Then 新賣場的名稱應以來源名稱為基礎並加上副本標記
      And 新賣場的永久連結應以來源永久連結為基礎並加上副本標記

    Example: 複製後商品列表與順序完整帶過去
      Given 賣場 "夏季賣場" 的商品順序為 103、101
      When 管理者 "Admin" 複製賣場 "夏季賣場"
      Then 新賣場的商品順序應為 103、101

    Example: 複製不影響來源賣場
      When 管理者 "Admin" 複製賣場 "夏季賣場"
      Then 賣場 "夏季賣場" 的狀態與內容應維持不變

    Example: 複製不存在的賣場回 404
      When 管理者 "Admin" 複製 id 為 999999 的賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（刪除）- 刪除為移至垃圾桶，之後公開網址一律 404

    Example: 刪除賣場後公開網址回 404
      Given 賣場 "夏季賣場" 的狀態為 "publish"
      When 管理者 "Admin" 刪除賣場 "夏季賣場"
      And 訪客開啟賣場 "夏季賣場" 的公開網址
      Then 系統應回傳 404

    Example: 刪除不存在的賣場回 404
      When 管理者 "Admin" 刪除 id 為 999999 的賣場
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

    Example: 刪除後該永久連結釋放回池
      Given 賣場 "夏季賣場" 的永久連結為 "summer-sale"
      When 管理者 "Admin" 刪除賣場 "夏季賣場"
      And 管理者 "Admin" 驗證永久連結 "summer-sale"
      Then 回應的 available 應為 true

  Rule: 前端（列表）- 操作欄依賣場狀態與模式呈現不同的主要動作

    # ⚠️ 各動作的停用條件刻意分開判斷，不為了 DRY 併成一個函式——
    #    它們看起來高度相似，但差異點正是關鍵。

    Example: 獨立頁面模式的賣場提供「開啟」前台網址
      Given 賣場 "夏季賣場" 的模式為 "page" 且已設定永久連結
      When 管理者 "Admin" 檢視賣場列表
      Then 該列的主要動作應為「開啟」

    Example: 短碼模式的賣場提供「複製」短碼
      Given 賣場 "夏季賣場" 的模式為 "shortcode"
      When 管理者 "Admin" 檢視賣場列表
      Then 該列的主要動作應為「複製」

    Scenario Outline: 各動作的停用條件互相獨立
      Given <condition>
      When 管理者 "Admin" 檢視賣場列表
      Then 「開啟」應為 <open>
      And 「複製」應為 <copy>
      And 「查看報表」應為 <report>

      Examples:
        | condition                | open | copy | report | 說明                              |
        | 賣場未設定永久連結         | 停用  | 可用  | 可用    | 短碼以 id 定位，沒 slug 也複製得出   |
        | 賣場已被刪除              | 停用  | 停用  | 可用    | 報表屬於夥伴，與賣場狀態無關         |
        | 賣場的分潤夥伴已被刪除     | 可用  | 可用  | 停用    | 報表網址不存在                     |

    Example: 停用的動作必須說明原因
      Given 賣場 "夏季賣場" 未設定永久連結
      When 管理者 "Admin" 將滑鼠移入停用的「開啟」
      Then 畫面應顯示停用原因

    Example: 停用的外開連結不得帶有可點擊的網址
      Given 賣場 "夏季賣場" 未設定永久連結
      When 管理者 "Admin" 檢視賣場列表
      Then 該列的「開啟」不應帶有任何連結網址

    Example: 夥伴資料載入中時不得顯示「夥伴已被刪除」
      Given 夥伴資料仍在載入中
      When 管理者 "Admin" 檢視賣場列表
      Then 「查看報表」不應顯示夥伴已被刪除的訊息
</content>
