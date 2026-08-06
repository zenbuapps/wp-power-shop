@ignore @command
Feature: 管理分潤夥伴

  店舖管理者在後台建立、修改與刪除分潤夥伴。夥伴是分潤的收款對象，
  其 slug 同時是自助報表頁的公開網址（`/profit-report/{slug}/`）。

  # ── 權限雙軌 ─────────────────────────────────────────────
  # 讀取（列表 / 單筆）：manage_woocommerce
  # 寫入（建立 / 更新 / 刪除）：manage_options —— 因為涉及密碼。
  #
  # ── 夥伴圖片只持久化 attachment ID ────────────────────────
  # 圖片網址是**唯讀衍生欄位**，儲存時一律忽略傳入的網址。
  # 換網域 / 換 CDN / 換圖片尺寸設定後網址會失效，attachment ID 不會。

  Background:
    Given 管理者 "Admin" 已登入後台且具備 manage_options 能力
    And 已存在分潤夥伴 "夥伴A" 其 term_id 為 5 且 slug 為 "partner-a"

  Rule: 權限（寫入）- 建立、更新與刪除夥伴需要 manage_options

    Scenario Outline: 僅具備 manage_woocommerce 者不得寫入夥伴
      Given 使用者僅具備 manage_woocommerce 能力
      When 該使用者<action>
      Then 系統應拒絕該請求

      Examples:
        | action     |
        | 建立夥伴    |
        | 更新夥伴    |
        | 刪除夥伴    |

    Example: 僅具備 manage_woocommerce 者可讀取夥伴列表
      Given 使用者僅具備 manage_woocommerce 能力
      When 該使用者查詢夥伴列表
      Then 系統應回傳夥伴列表

  Rule: 前置（slug）- slug 必須合法且不得與既有夥伴重複

    Example: slug 格式不合法時拒絕建立
      When 管理者 "Admin" 以 slug "Partner A!" 建立夥伴
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

    Example: slug 與既有夥伴重複時回傳衝突
      When 管理者 "Admin" 以 slug "partner-a" 建立夥伴
      Then 系統應回傳錯誤
        | status | code          |
        | 409    | slug_conflict |
      And 回應的 conflicts 應包含一筆 conflict_kind 為 "profit_partner" 的項目

  Rule: 後置（建立）- 建立成功後回傳夥伴資料與報表網址

    Example: 建立夥伴後可取得其報表網址
      When 管理者 "Admin" 以名稱 "夥伴C" 與 slug "partner-c" 建立夥伴
      Then 系統應回傳建立成功
      And 回應應包含 report_url 且其路徑為 "/profit-report/partner-c/"

    Example: 建立時可一併設定聯絡 email
      When 管理者 "Admin" 建立夥伴並填入聯絡 email
      Then 該夥伴的聯絡 email 應被保存

  Rule: 後置（更新）- 更新不存在的夥伴回 404

    Example: 更新不存在的夥伴
      When 管理者 "Admin" 更新 term_id 為 999999 的夥伴
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（刪除）- 仍被賣場引用的夥伴不得刪除

    Example: 夥伴仍掛在賣場上時拒絕刪除
      Given 已存在分潤賣場 "夏季賣場" 其分潤夥伴為 "夥伴A"
      When 管理者 "Admin" 刪除夥伴 "夥伴A"
      Then 系統應回傳錯誤
        | status | code           |
        | 409    | partner_in_use |
      And 夥伴 "夥伴A" 應仍然存在

    Example: 沒有任何賣場引用時允許刪除
      Given 沒有任何賣場引用夥伴 "夥伴A"
      When 管理者 "Admin" 刪除夥伴 "夥伴A"
      Then 系統應回傳刪除成功

    Example: 刪除不存在的夥伴回 404
      When 管理者 "Admin" 刪除 term_id 為 999999 的夥伴
      Then 系統應回傳錯誤
        | status | code      |
        | 404    | not_found |

  Rule: 後置（圖片）- 只持久化 attachment ID，網址為唯讀衍生欄位

    Example: 儲存時忽略傳入的圖片網址
      When 管理者 "Admin" 更新夥伴 "夥伴A" 並同時傳入 image_id 為 88 與一個偽造的 image_url
      And 管理者 "Admin" 重新查詢夥伴 "夥伴A"
      Then 夥伴 "夥伴A" 的 image_id 應為 88
      And 夥伴 "夥伴A" 的 image_url 應由 attachment 88 即時解析而得
      And 夥伴 "夥伴A" 的 image_url 不應為傳入的偽造網址

    Scenario Outline: 非正整數的 image_id 一律視為移除圖片
      When 管理者 "Admin" 更新夥伴 "夥伴A" 且 image_id 為 <image_id>
      And 管理者 "Admin" 重新查詢夥伴 "夥伴A"
      Then 夥伴 "夥伴A" 的 image_id 應為 null

      Examples:
        | image_id |
        | null     |
        | 0        |
        | -1       |

    Example: attachment 已被刪除時保留 ID 但網址為 null
      # 讀取路徑不可有寫入副作用；站長從媒體庫救回檔案後圖片能自動復原。
      Given 夥伴 "夥伴A" 的 image_id 為 88 且 attachment 88 已被刪除
      When 管理者 "Admin" 查詢夥伴 "夥伴A"
      Then 夥伴 "夥伴A" 的 image_id 應為 88
      And 夥伴 "夥伴A" 的 image_url 應為 null

    Example: 圖片遺失時前台不得顯示破圖
      Given 夥伴 "夥伴A" 的 image_id 非 null 但 image_url 為 null
      When 管理者 "Admin" 進入夥伴 "夥伴A" 的編輯頁
      Then 畫面應呈現預設頭像而非破圖

  Rule: 後置（列表）- 夥伴列表可依關鍵字與圖片有無篩選

    Example: 以關鍵字篩選夥伴
      When 管理者 "Admin" 以關鍵字 "partner-a" 篩選夥伴列表
      Then 列表應只顯示夥伴 "夥伴A"

    Example: 列表的名稱欄可點擊進入編輯頁
      When 管理者 "Admin" 檢視夥伴列表
      Then 每一列的名稱應同時呈現名稱與 #term_id
      And 點擊名稱應進入該夥伴的編輯頁
</content>
