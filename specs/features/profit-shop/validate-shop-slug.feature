@ignore @query
Feature: 即時驗證賣場永久連結

  賣場的永久連結會成為站台的一段公開網址前綴，因此必須在使用者輸入當下就
  檢查格式與衝突，避免建立出打不開、或蓋掉站台既有頁面的賣場。

  # ── 七類衝突來源（依嚴重度）─────────────────────────────
  #   wp_reserved     WordPress 保留字（wp-admin / wp-json / feed / category …）
  #   wc_page         WooCommerce 核心頁面 slug（shop / cart / checkout / my-account …）
  #   cpt             其他已註冊 CPT 的 rewrite slug
  #   page            既有頁面的 post_name
  #   rewrite         其他自訂 rewrite rule 的前綴
  #   powershop       既有分潤賣場的 slug（含 draft，排除 trash）
  #   profit_partner  既有分潤夥伴的 term slug
  #
  # 第 6 類必須涵蓋 draft：草稿賣場對有編輯權限者開放預覽，其 slug 已實際被佔用。
  # 第 6 類必須排除 trash：已刪除賣場的 slug 釋放回池。
  #
  # ⚠️ conflict_kind 是 machine code，與 specs/api/api.yml 的 enum 鎖死同步；
  #    前端據以做在地化文案，改動任一邊都要同步另一邊。

  Background:
    Given 管理者 "Admin" 已登入後台

  Rule: 前置（格式）- 永久連結必須為 1 至 60 字元的小寫英數、底線與連字號

    Scenario Outline: 格式不合法的永久連結被拒絕
      When 管理者 "Admin" 驗證永久連結 "<slug>"
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

      Examples:
        | slug                | 說明          |
        |                     | 空字串        |
        | Summer-Sale         | 含大寫        |
        | summer sale         | 含空白        |
        | 夏季賣場             | 含非 ASCII    |
        | summer/sale         | 含斜線        |

    Example: 超過 60 字元的永久連結被拒絕
      When 管理者 "Admin" 驗證一個 61 字元的永久連結
      Then 系統應回傳錯誤
        | status | code              |
        | 400    | validation_failed |

    Example: 合法格式的永久連結通過格式檢查
      When 管理者 "Admin" 驗證永久連結 "summer_sale-2026"
      Then 系統不應回傳格式錯誤

  Rule: 後置（可用）- 沒有任何衝突時回報可用

    Example: 未被佔用的永久連結回報可用
      When 管理者 "Admin" 驗證永久連結 "brand-new-shop"
      Then 回應的 available 應為 true
      And 回應的 conflicts 應為空

  Rule: 後置（衝突）- 命中任一類衝突時回報不可用並附衝突來源

    Scenario Outline: 各類衝突來源皆被偵測
      Given 站台存在 <existing>
      When 管理者 "Admin" 驗證永久連結 "<slug>"
      Then 回應的 available 應為 false
      And 回應的 conflicts 應包含一筆 conflict_kind 為 "<kind>" 的項目

      Examples:
        | existing                        | slug        | kind           |
        | WordPress 保留字 wp-admin        | wp-admin    | wp_reserved    |
        | WooCommerce 商店頁 slug 為 shop  | shop        | wc_page        |
        | 一個 rewrite slug 為 course 的 CPT | course      | cpt            |
        | slug 為 about-us 的頁面          | about-us    | page           |
        | slug 為 summer-sale 的分潤賣場    | summer-sale | powershop      |
        | slug 為 jerry 的分潤夥伴          | jerry       | profit_partner |

    Example: 衝突項目必須帶足夠資訊供前端說明原因
      Given 站台存在 slug 為 summer-sale 的分潤賣場「夏季活動」
      When 管理者 "Admin" 驗證永久連結 "summer-sale"
      Then 回應的每一筆 conflict 應包含以下欄位
        | field             |
        | conflict_kind     |
        | conflicting_slug  |
        | conflicting_id    |
        | conflicting_label |

    Example: 同時命中多類衝突時全部列出
      Given 站台同時存在同名的頁面與分潤賣場
      When 管理者 "Admin" 驗證該永久連結
      Then 回應的 conflicts 應包含 2 筆項目

  Rule: 後置（草稿）- 草稿賣場佔用的永久連結視為衝突

    Example: 草稿賣場的永久連結不可被再次使用
      Given 站台存在 slug 為 "draft-shop" 且狀態為 draft 的分潤賣場
      When 管理者 "Admin" 驗證永久連結 "draft-shop"
      Then 回應的 available 應為 false

    Example: 已刪除賣場的永久連結釋放回池
      Given 站台存在 slug 為 "trashed-shop" 且已被刪除的分潤賣場
      When 管理者 "Admin" 驗證永久連結 "trashed-shop"
      Then 回應的 available 應為 true

  Rule: 前端（即時）- 輸入時延遲驗證，且編輯自己時不與自己衝突

    Example: 輸入停止後才發出驗證查詢
      When 管理者 "Admin" 連續輸入永久連結的每一個字元
      Then 系統應在輸入停止後才發出一次驗證查詢

    Example: 編輯賣場時不驗證自己目前的永久連結
      Given 管理者 "Admin" 正在編輯 slug 為 "summer-sale" 的賣場
      When 欄位值維持為 "summer-sale"
      Then 系統不應發出驗證查詢

    Example: 清空欄位時不得殘留先前的驗證結果
      Given 欄位曾顯示「已被佔用」
      When 管理者 "Admin" 清空永久連結欄位
      Then 畫面不應顯示任何驗證結果
</content>
