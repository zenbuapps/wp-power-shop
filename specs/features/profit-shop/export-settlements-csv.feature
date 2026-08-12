@ignore @query
Feature: 匯出分潤結算明細為 CSV

  站長需要把結算明細帶出系統做對帳、報稅或轉交會計，因此結算頁提供 CSV 匯出。
  匯出範圍等同當下的篩選條件——所見即所得，避免站長以為匯出的是全部、
  結果拿到的是被篩選過的子集（或反之）。

  # ── 🔴 CSV 公式注入 ─────────────────────────────────────────
  # 試算表軟體會把以 = + - @ 開頭的儲存格當成公式執行。攻擊者只要把商品名稱
  # 取成 =cmd|'/c calc'!A1，站長在 Excel 打開這份匯出檔就會執行外部命令。
  # 這不是理論風險：商品名稱、賣場名稱、夥伴名稱三個欄位都是「有人可以輸入」的字串。
  #
  # ⚠️ 三個欄位**都要**跳脫，不是只跳脫「看起來危險」的那一個。
  #    只跳脫商品名稱，攻擊者就改用賣場名稱。
  #
  # ── 為什麼回傳 base64 而不是直接串流檔案 ────────────────────
  # 決策 D-5。後台端點一律回 {code, message, data} 外層，前端讀取回應時
  # 依賴這個固定形狀；改成串流原始 CSV 會讓這一條路徑變成唯一的例外。
  # 檔案由前端自行組出下載，也順便讓錯誤（權限不足、篩選無資料）能以
  # 一般的 JSON 錯誤格式回應，而不是下載到一個內容是錯誤訊息的 .csv。
  #
  # ── 編碼 ────────────────────────────────────────────────────
  # 內容為 UTF-8 並在檔頭加上 BOM。少了 BOM，Excel（尤其中文 Windows 環境）
  # 會以系統預設編碼開啟，中文的商品名稱與夥伴名稱全部變成亂碼。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 已存在分潤賣場 "夏季賣場" 其 id 為 1932
    And 系統中已存在以下分潤結算紀錄
      | order_item_id | order_id | product_name | partner_term_id | shop_id | actual_price | profit_amount | status  | order_date |
      | 7001          | 8001     | 筆記型電腦     | 5               | 1932    | 240.00       | 48.00         | pending | 2026-08-01 |
      | 7002          | 8002     | 無線滑鼠       | 5               | 1932    | 900.00       | 180.00        | paid    | 2026-08-02 |
    And 站長已登入且具有站台管理權限

  Rule: 安全（權限）- 匯出一律要求站台管理權限
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_guest_cannot_export
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_shop_manager_is_denied_on_every_settlement_endpoint

    Scenario Outline: 權限不足者無法匯出
      Given 目前使用者 <role>
      When 使用者請求匯出結算明細
      Then 系統應回傳 403
      And 回應不應包含任何結算資料

      Examples:
        | role            |
        | 未登入           |
        | 僅具商店管理權限  |

    Example: 夥伴 token 不能匯出結算明細
      Given 夥伴 "夥伴A" 已登入並持有有效 token
      When 夥伴 "夥伴A" 以自己的 token 請求匯出結算明細
      Then 系統應回傳 403

  Rule: 安全（公式注入）- 危險前綴的儲存格必須被中和
    # @covered-by: tests/Unit/Application/Service/CsvExporterTest.php::test_dangerous_prefixes_are_neutralised
    # @covered-by: tests/Unit/Application/Service/CsvExporterTest.php::test_dangerous_prefix_after_leading_whitespace_is_neutralised
    # @covered-by: tests/Unit/Application/Service/CsvExporterTest.php::test_neutralisation_preserves_original_text
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_all_three_user_input_columns_are_escaped
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_export_neutralises_formula_injection_end_to_end

    # 中和方式是在值前面加一個單引號，讓試算表把它當成純文字。

    Scenario Outline: 以危險字元開頭的商品名稱被前置單引號
      Given 存在一筆結算紀錄其商品名稱為 "<name>"
      When 站長匯出結算明細
      Then 該儲存格的內容應為 "<escaped>"

      Examples:
        | name              | escaped            |
        | =1+1              | '=1+1              |
        | +886912345678     | '+886912345678     |
        | -100              | '-100              |
        | @SUM(A1:A9)       | '@SUM(A1:A9)       |

    Example: 以定位字元開頭的值被中和
      Given 存在一筆結算紀錄其商品名稱以定位字元開頭
      When 站長匯出結算明細
      Then 該儲存格應被前置單引號

    Example: 以歸位字元開頭的值被中和
      Given 存在一筆結算紀錄其商品名稱以歸位字元開頭
      When 站長匯出結算明細
      Then 該儲存格應被前置單引號

    Example: 三個可被輸入的名稱欄位都要跳脫
      Given 商品名稱、賣場名稱與夥伴名稱皆為 "=cmd|'/c calc'!A1"
      When 站長匯出結算明細
      Then 商品名稱、賣場名稱與夥伴名稱三個儲存格皆應被前置單引號

    Example: 安全的值不被改動
      Given 存在一筆結算紀錄其商品名稱為 "筆記型電腦"
      When 站長匯出結算明細
      Then 該儲存格的內容應為 "筆記型電腦"
      And 該儲存格不應被前置單引號

    Example: 值中間出現危險字元時不被改動
      Given 存在一筆結算紀錄其商品名稱為 "A=B"
      When 站長匯出結算明細
      Then 該儲存格的內容應為 "A=B"

    Example: 含逗號與引號的名稱依 CSV 規則正確跳脫
      Given 存在一筆結算紀錄其商品名稱為 '筆電, 15" 螢幕'
      When 站長匯出結算明細
      Then 該列應能被標準 CSV 解析器還原為原始欄位值

  Rule: 後置（欄位）- 欄位順序與標題固定
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_header_matches_spec_exactly
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_data_row_matches_spec_example
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_order_date_is_date_only

    Example: 首列為標題列且欄位順序固定
      When 站長匯出結算明細
      Then 首列應依序為
        | column       |
        | 訂單編號      |
        | 訂單品項編號  |
        | 商品名稱      |
        | 分潤夥伴      |
        | 分潤賣場      |
        | 實際單價      |
        | 應分潤金額    |
        | 結算狀態      |
        | 訂單日期      |

    Example: 資料列的欄位順序與標題列一致
      When 站長匯出結算明細
      Then order_item_id 7001 那一列應依序為
        | 8001 | 7001 | 筆記型電腦 | 夥伴A | 夏季賣場 | 240.00 | 48.00 | pending | 2026-08-01 |

    Example: 已刪除的賣場與夥伴以佔位文字匯出
      Given 賣場 "夏季賣場" 與夥伴 "夥伴A" 皆已被刪除
      When 站長匯出結算明細
      Then 該列的分潤賣場欄應為 "[已刪除賣場]"
      And 該列的分潤夥伴欄應為 "[已停用夥伴]"

  Rule: 後置（範圍）- 匯出內容等同當下的篩選條件
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_export_paginates_through_all_results
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_non_pagination_filters_are_preserved
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_export_respects_filter

    Example: 未篩選時匯出全部結算紀錄
      When 站長不套用任何篩選匯出結算明細
      Then 匯出內容應包含 2 筆資料列

    Example: 依狀態篩選後只匯出符合的紀錄
      When 站長以狀態 "pending" 匯出結算明細
      Then 匯出內容應只包含 order_item_id 7001

    Example: 依日期區間篩選後只匯出區間內的紀錄
      When 站長以 2026-08-02 至 2026-08-31 匯出結算明細
      Then 匯出內容應只包含 order_item_id 7002

    Example: 篩選結果為空時仍產出只有標題列的檔案
      When 站長以 2026-01-01 至 2026-01-31 匯出結算明細
      Then 匯出內容應只有標題列
      And 系統不應回傳錯誤

    Example: 匯出與列表套用相同的排除規則
      Given 訂單 8001 的狀態為 "cancelled"
      When 站長匯出結算明細
      Then 匯出內容不應包含 order_item_id 7001

    Example: 訂閱物件上的品項不得被匯出
      Given 已存在訂閱物件 9001 其品項帶有與 order_item_id 7001 相同的分潤 meta
      When 站長匯出結算明細
      Then 匯出內容中 order_item_id 7001 應只出現一次

  Rule: 後置（格式）- 回應包含檔名與編碼過的內容
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_content_starts_with_utf8_bom
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_output_carries_base64_not_raw_content
    # @covered-by: tests/Unit/Application/UseCase/Settlement/ExportSettlementsUseCaseTest.php::test_filename_and_mime_type
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitSettlementsEndpointTest.php::test_export_returns_csv_with_bom_and_header

    Example: 匯出回應遵循後台端點的統一外層
      When 站長匯出結算明細
      Then 回應最外層應包含 code、message 與 data 三個欄位
      And 回應的 data 應包含以下欄位
        | field          |
        | filename       |
        | content_base64 |

    Example: 檔名包含日期以避免多次匯出互相覆蓋
      When 站長匯出結算明細
      Then 檔名應以 ".csv" 結尾
      And 檔名應包含匯出當日的日期

    Example: 內容為含 BOM 的 UTF-8
      When 站長匯出結算明細
      Then 解碼後的內容應以 UTF-8 BOM 開頭
      And 中文欄位應能被試算表軟體正確顯示

  Rule: 已知落差 - 目前未提供的匯出能力
    # @covered-by: NOT-COVERED 本 Rule 描述的是「目前未提供」的匯出能力，依定義沒有對應實作可測

    # 刻意未納入 v1 範圍，避免日後被當成 bug 修掉。

    Example: 匯出沒有筆數上限的分批機制
      When 站長匯出大量結算明細
      Then 系統一次產出整份檔案
      And 系統不提供背景排程或分批下載
