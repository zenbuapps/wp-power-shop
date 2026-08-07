@ignore @query
Feature: 商品單頁套用賣場覆寫價

  顧客從賣場卡片點進 WooCommerce 商品單頁時，單頁看到的價格必須與賣場卡片、
  以及之後購物車實際收取的價格一致。

  # ── 為什麼需要這個 Feature ────────────────────────────────
  # 在此之前顧客的動線存在落差：賣場卡片顯示覆寫價 → 點進單品頁顯示**商品原始價格**
  # → 選變體加入購物車 → 購物車又變回覆寫價。開放變體層覆寫之後，
  # 這個「單品頁一個價、購物車另一個價」的跳動就變成顧客可見的信任問題。
  #
  # ── 作用範圍刻意收斂 ─────────────────────────────────────
  # 只在「是商品單頁」且「網址帶合法賣場識別」且賣場通過四道閘門時才註冊價格 filter。
  # 🔴 不可改成常駐註冊：那等於抵銷「購物車驗章失敗時刻意退回商品原價」這道安全保護
  #    ——那個 fallback 存在的意義就是「簽章對不上就不能相信這個覆寫價」，
  #    若單品頁的 filter 不看簽章、無條件套用，這道防線會被繞過。
  #
  # ── 不帶賣場識別時顯示原價是刻意的設計意圖 ────────────────
  # 「未經賣場直接開商品頁看到原始價格」不是缺口，是為了讓覆寫價不脫離賣場外洩。
  # 不要把它當成 bug 修掉。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 term_id 為 5
    And 系統中已存在以下商品
      | id  | name     | type     | regular_price | sale_price |
      | 101 | MacBook  | simple   | 1000          |            |
      | 103 | iPad Air | variable |               |            |
    And 商品 103 具有變體 1031（原價 45 特價 42）與 1032（原價 45）
    And 已存在 status 為 publish 且在販售期間內的分潤賣場 "夏季賣場" 其 id 為 1932
    And 賣場 "夏季賣場" 的商品列表包含商品 101 與 103

  Rule: 前置（範圍）- 只有商品單頁且帶合法賣場識別時才套用覆寫價
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_without_query_string_no_filter_is_registered
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_non_single_product_page_registers_no_filter
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_nonexistent_shop_leaves_price_untouched
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_draft_shop_leaves_price_untouched
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_product_not_in_shop_leaves_price_untouched
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_closed_shop_registers_no_filter_and_keeps_priceless_variation_unpurchasable

    Scenario Outline: 依情境決定是否套用覆寫價
      Given 賣場 "夏季賣場" 對商品 101 設定覆寫原價 "500" 與特價 "450"
      When 顧客在 <context> 檢視商品 101 的價格
      Then 顯示的價格應為 <price>

      Examples:
        | context                             | price | 說明                    |
        | 帶賣場 1932 識別的商品單頁            | 450   | 正常路徑                 |
        | 不帶任何賣場識別的商品單頁            | 1000  | 刻意的設計意圖            |
        | 帶不存在賣場識別的商品單頁            | 1000  | 閘門①                   |
        | 帶 draft 賣場識別的商品單頁          | 1000  | 閘門②                   |
        | 帶已結束販售賣場識別的商品單頁         | 1000  | 閘門③                   |
        | 帶賣場 1932 識別但商品不屬於該賣場     | 1000  | 閘門④                   |
        | 帶賣場 1932 識別的商店彙整頁          | 1000  | 非商品單頁               |

    Example: 不帶賣場識別時價格零殘留
      Given 顧客先以帶賣場識別的網址開啟商品 101 的單頁
      When 顧客改以不帶賣場識別的網址開啟商品 101 的單頁
      Then 顯示的價格應完整回到商品原本的 "1000"

  Rule: 後置（變體）- 單一變體價格與變體價格區間都必須套用
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_simple_product_price_is_overridden_on_single_product_page
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_variation_override_applies_to_that_variation_only
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_regular_only_override_clears_original_sale_price

    # 兩者是兩組不同的 filter：WooCommerce 讀取變體價格區間時走的是 edit context，
    # 單一變體的實際結帳價走的是 view context。漏掉任一組就會出現
    # 「單品頁顯示的價格區間」與「選定某個變體後看到的價格」不一致。

    Example: 變體價格區間隨覆寫價位移
      Given 賣場 "夏季賣場" 對變體 1031 設定覆寫原價 "999" 與特價 "888"
      When 顧客帶著賣場 1932 的識別開啟商品 103 的單頁
      Then 顯示的價格區間應為 "45 – 888"

    Example: 逐變體的顯示價格正確套用
      Given 賣場 "夏季賣場" 對變體 1031 設定覆寫原價 "999" 與特價 "888"
      And 賣場 "夏季賣場" 對變體 1032 設定覆寫原價 "777" 且不設定特價
      When 顧客帶著賣場 1932 的識別檢視商品 103 的各變體價格
      Then 各變體的價格應為
        | variation_id | price | regular_price | sale_price |
        | 1031         | 888   | 999           | 888        |
        | 1032         | 777   | 777           |            |

    Example: 只設覆寫原價的變體不得渲染出假的劃線特價
      Given 賣場 "夏季賣場" 對變體 1032 只設定覆寫原價 "777"
      When 顧客帶著賣場 1932 的識別檢視變體 1032
      Then 該變體不應呈現「原價劃掉→特價」的樣式

    Example: 未覆寫的變體維持原本價格
      Given 賣場 "夏季賣場" 只對變體 1031 設定覆寫
      When 顧客帶著賣場 1932 的識別檢視變體 1032
      Then 變體 1032 的價格應維持原本的 "45"

  Rule: 快取（隔離）- 變體價格快取必須依賣場與覆寫內容分桶
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_variation_prices_hash_differs_per_shop
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_variation_prices_hash_untouched_for_other_products
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_variation_prices_hash_changes_when_override_value_changes

    # WooCommerce 把變體價格陣列存在暫存快取，其快取鍵只納入掛在價格 filter 上的
    # **callback 名稱**，不含賣場識別，也不含覆寫值本身。

    Example: 兩個賣場對同一商品的不同覆寫價不得互相污染
      Given 另有賣場 "秋季賣場" 其 id 為 2000 且對商品 103 設定不同的覆寫價
      When 顧客先後帶著賣場 1932 與賣場 2000 的識別開啟商品 103 的單頁
      Then 兩次看到的價格區間應各自對應其賣場的覆寫價

    Example: 站長改動覆寫價後單品頁即時反映
      # 站長改的是**賣場**的資料，不會觸發 WooCommerce 對**商品**價格快取的任何清除。
      # 快取鍵因此必須混入覆寫內容的指紋，否則會出現「已刪除的覆寫價仍顯示在區間裡」
      # 這種幽靈商品。
      Given 顧客已載入過帶賣場 1932 識別的商品 103 單頁
      When 管理者清除賣場 "夏季賣場" 對商品 103 的所有覆寫價
      And 顧客重新開啟帶賣場 1932 識別的商品 103 單頁
      Then 顯示的價格區間應回到商品原本的區間

    Example: 快取分桶不影響其他商品
      When 顧客帶著賣場 1932 的識別開啟商品 103 的單頁
      Then 不屬於賣場 1932 的其他商品其價格快取不應受影響

  Rule: 隔離（購物車）- 單品頁的價格 filter 永遠不得影響購物車品項
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_disarm_removes_every_filter_and_restores_original_prices

    # 🔴 這道隔離不能靠「請求類型」論證——filter 一旦掛上就整個請求不解除，
    #    而購物車與商品頁**在同一個請求裡並存**。
    #    完整說明見 authorize-priceless-cart-item.feature 的「不變量（隔離）」Rule。

    Example: 渲染單品頁期間重新計算購物車不得污染無簽章品項
      Given 購物車中有一筆完全沒有分潤 meta 的商品 101 品項
      When 顧客開啟帶賣場 1932 識別的商品 101 單頁
      And 佈景主題在渲染期間重新計算購物車總額
      Then 該購物車品項的小計應維持商品原價 "1000"

  Rule: 政策（無定價）- 商品層覆寫不得救起沒有變體層覆寫價的無定價變體
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_parent_level_override_does_not_rescue_priceless_variation
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/OverridePriceFiltersTest.php::test_priceless_variation_without_override_stays_unpurchasable

    # 顯示層與加購層共用同一份政策，見 authorize-priceless-cart-item.feature。

    Example: 只設商品層覆寫時無定價變體不出現在單品頁的可選變體
      Given 商品 105 為可變商品且其變體 1051 自身無價格
      And 賣場 "夏季賣場" 對商品 105 只設定商品層覆寫價
      When 顧客帶著賣場 1932 的識別開啟商品 105 的單頁
      Then 變體 1051 不應出現在可選變體中

  Rule: 已知落差（父商品）- 無可購買變體的可變商品在單品頁仍顯示覆寫價
    # @covered-by: tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php::test_known_gap_parent_of_unpurchasable_variations_still_shows_override

    # ⚠️ specs/tech-debt.md TD-68：刻意不修。
    #   顧客加不進購物車（WooCommerce 對可變父商品直接拒絕），
    #   且賣場前台不會連到這裡（該商品的卡片已是「暫無可購買規格」且不印 Modal），
    #   顧客只能自己手動在網址加上賣場識別才會看到。
    #   修法唯一的落點會遞迴回正在執行的那組價格 filter。

    Example: 所有變體皆不可購買時父商品仍顯示覆寫價
      Given 商品 105 的所有變體皆不可購買
      And 賣場 "夏季賣場" 對商品 105 設定商品層覆寫價 "450"
      When 顧客手動以帶賣場識別的網址開啟商品 105 的單頁
      Then 顯示的價格應為 "450"
      And 顧客仍無法將商品 105 加入購物車

  Rule: 文案（一致）- 後台的據實揭露文案必須與實際作用範圍一致
    # @covered-by: NOT-COVERED VariationOverrideEditor 的據實揭露 Alert 文案，屬 React 元件內容（B9 待補）。⚠️ TD-20 即為此類文案與實際行為脫節的前例

    # ⚠️ 這條是流程規則，不是執行期行為。
    #   曾經發生：規劃階段先寫了「單品頁仍顯示原價」的緩解文案，
    #   同一次改動後來把單品頁套價做出來了，但**沒有人回頭改那句話**。
    #   這類落差 lint / 型別檢查 / 測試一個都攔不到。
    #   往後任何一次改動本 Feature 的作用範圍，都必須一併檢查後台
    #   帶有「單品頁是否顯示覆寫價」語意的說明文字是否仍然成立。

    Example: 變體覆寫編輯區的說明必須涵蓋四條顧客動線
      When 管理者展開賣場商品的變體設定
      Then 說明文字應分別交代賣場卡片、從賣場進入的單品頁、購物車結帳
      And 說明文字應交代「未經賣場直接開商品頁看到原始價格」是刻意的設計
</content>
