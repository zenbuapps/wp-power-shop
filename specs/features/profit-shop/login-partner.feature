@ignore @command
Feature: 分潤夥伴登入自助報表

  分潤夥伴以 slug + 密碼登入 `/profit-report/{slug}/`，取得一組限時 token 後
  才能查詢自己的分潤報表。登入端點對外公開，因此防暴力破解與防帳號列舉
  是這個 Feature 的主要內容。

  # ── 統一錯誤訊息（防帳號列舉）─────────────────────────────
  # 「slug 不存在」與「密碼錯誤」一律回同一個 401 unauthorized，
  # 不得從錯誤訊息或耗時差異推斷某個 slug 是否存在。
  #
  # ── 雙維度速率限制 ───────────────────────────────────────
  # per-slug 與 per-IP 各自獨立計數，任一達 5 次即鎖定 15 分鐘。
  # 兩個維度都存在的理由：
  #   - 只有 per-slug → 攻擊者輪換 slug 就繞過
  #   - 只有 per-IP   → 分散式來源就繞過
  #
  # ── token 永不以明文落地 ─────────────────────────────────
  # 儲存的是 token 的雜湊；Cookie 為 HttpOnly + Secure + SameSite=Lax，
  # 且 Path 限定在本外掛的 REST 命名空間之下。前端讀不到 token（XSS 防護）。

  Background:
    Given 已存在分潤夥伴 "夥伴A" 其 slug 為 "partner-a" 且密碼為 "correct-pw1"
    And 已存在分潤夥伴 "夥伴B" 其 slug 為 "partner-b"

  Rule: 後置（成功）- 登入成功回傳 token、到期時間與夥伴基本資料

    Example: 以正確的 slug 與密碼登入
      When 訪客以 slug "partner-a" 與密碼 "correct-pw1" 登入
      Then 系統應回傳登入成功
      And 回應應包含以下欄位
        | field      |
        | token      |
        | expires_at |
        | partner    |
      And 回應的 partner 應包含 id / name / slug

    Example: 登入成功時簽發 HttpOnly Cookie
      When 訪客以 slug "partner-a" 與密碼 "correct-pw1" 登入
      Then 回應應設定名為 "profit_partner_token" 的 Cookie
      And 該 Cookie 應具備 HttpOnly、Secure 與 SameSite=Lax 屬性
      And 該 Cookie 的 Path 應限定在 "/wp-json/power-shop/"
      And 該 Cookie 的存活秒數應為 3600

    Example: 登入成功後清空該夥伴的失敗計數
      Given 夥伴 "夥伴A" 已累積 3 次登入失敗
      When 訪客以 slug "partner-a" 與正確密碼登入
      Then 夥伴 "夥伴A" 的登入失敗計數應歸零
      And 該來源 IP 的登入失敗計數應歸零

  Rule: 安全（列舉）- 帳號不存在與密碼錯誤必須回相同的錯誤

    Scenario Outline: 兩種登入失敗回相同的錯誤碼與訊息
      When 訪客以 slug "<slug>" 與密碼 "<password>" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

      Examples:
        | slug           | password    | 情境         |
        | partner-a      | wrong-pw    | 密碼錯誤      |
        | never-existed  | any-pw      | slug 不存在   |

    Example: 未知 slug 的失敗不在 slug 維度留下痕跡
      When 訪客以不存在的 slug "never-existed" 登入失敗
      Then 系統不應為該 slug 建立失敗計數

    Example: 未知 slug 的失敗仍累計來源 IP 的計數
      # 否則攻擊者可用同一 IP 對 1000 個不存在的 slug 各試一次而完全不被記錄。
      When 訪客以不存在的 slug "never-existed" 登入失敗
      Then 該來源 IP 的登入失敗計數應增加 1

  Rule: 安全（速率）- 任一維度達 5 次失敗即鎖定並回 429

    Example: 同一 slug 連續失敗 5 次後被鎖定
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      And 訪客再次以 slug "partner-a" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |
      And 回應應包含 Retry-After 標頭

    Example: 鎖定期間即使密碼正確也不放行
      Given 夥伴 "夥伴A" 已因連續失敗而被鎖定
      When 訪客以 slug "partner-a" 與正確密碼 "correct-pw1" 登入
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |

    Example: 同一 IP 輪換不同 slug 攻擊仍會被鎖定
      When 同一來源 IP 對 5 個不同的 slug 各嘗試登入失敗 1 次
      And 該 IP 再次嘗試登入任一 slug
      Then 系統應回傳錯誤
        | status | code         |
        | 429    | rate_limited |

    Example: 兩個維度都被鎖定時 Retry-After 取較長者
      Given slug 維度剩餘鎖定時間為 300 秒
      And IP 維度剩餘鎖定時間為 600 秒
      When 訪客嘗試登入
      Then 回應的 Retry-After 應為 600

    Example: 鎖定視窗過後計數自動歸零
      Given 夥伴 "夥伴A" 已因連續失敗而被鎖定
      When 經過 15 分鐘後訪客以正確密碼登入
      Then 系統應回傳登入成功

    Example: 視窗內的重複失敗不得延長視窗
      Given 夥伴 "夥伴A" 在 10 分鐘前開始累積失敗
      When 訪客再次以錯誤密碼登入
      Then 該計數的到期時間應維持原本的視窗結束時間

  Rule: 通知（警示）- slug 維度達門檻時通知站長，IP 維度不寄信

    # IP 維度不寄信是刻意的：攻擊者輪換 slug 時會把站長信箱洗版。

    Example: slug 失敗達 5 次時寄出警示信給站長
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      Then 系統應寄出一封暴力破解警示信
      And 系統應寫入一筆稽核紀錄

    Example: 寄信失敗不得阻擋登入流程
      Given 站台的寄信功能將失敗
      When 訪客以 slug "partner-a" 連續 5 次輸入錯誤密碼
      Then 系統仍應正常回傳 429
      And 系統應寫入一筆寄信失敗的紀錄

    Example: IP 維度達門檻時只寫稽核紀錄不寄信
      When 同一來源 IP 累積 5 次登入失敗
      Then 系統應寫入一筆稽核紀錄
      And 系統不應為 IP 維度寄出警示信

  Rule: 隱私（IP）- 來源 IP 永不以明文落地

    Example: 速率限制的儲存鍵與稽核紀錄皆不含明文 IP
      When 訪客以來源 IP "203.0.113.9" 登入失敗
      Then 速率限制的儲存鍵不應包含 "203.0.113.9"
      And 稽核紀錄不應包含 "203.0.113.9"

    Scenario Outline: 無效的來源 IP 一律退化為僅 slug 維度
      Given 取得的來源 IP 為 "<ip>"
      When 訪客登入失敗
      Then 系統不應建立任何 IP 維度的計數

      Examples:
        | ip        |
        |           |
        | not-an-ip |

    Example: 反向代理環境可由站台自行注入真實來源 IP
      Given 站台已透過 filter 提供真實來源 IP
      When 訪客登入失敗
      Then 速率限制應以該真實來源 IP 計數

  Rule: 後置（token）- token 永不以明文儲存且有存活上限

    Example: token 以雜湊形式儲存
      When 訪客登入成功並取得 token
      Then 儲存端不應存在該 token 的明文

    Example: token 於 3600 秒後失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 經過 3600 秒後夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

  Rule: 後置（撤銷）- 密碼變更後既有 token 立即失效

    # 「同秒簽發舊 token + 同秒改密」也必須被涵蓋：
    # token 的簽發時間**等於**密碼變更時間時視為已撤銷。

    Example: 密碼變更後舊 token 立即失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 夥伴 "夥伴A" 的密碼被變更
      And 夥伴以舊 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 同一秒內簽發的舊 token 在同秒改密後亦失效
      Given 夥伴 "夥伴A" 的 token 於時間 T 簽發
      When 夥伴 "夥伴A" 的密碼於同一個時間 T 被變更
      And 夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

  Rule: 後置（登出）- 登出必須是冪等的且永遠清除本機狀態

    Example: 登出後 token 立即失效
      Given 夥伴 "夥伴A" 已登入並取得 token
      When 夥伴 "夥伴A" 登出
      And 夥伴以該 token 查詢報表
      Then 系統應回傳錯誤
        | status | code         |
        | 401    | unauthorized |

    Example: 登出時清除 Cookie
      When 夥伴 "夥伴A" 登出
      Then 回應應設定 "profit_partner_token" 的存活秒數為 0

    Example: 重複登出不得報錯
      Given 夥伴 "夥伴A" 已登出
      When 夥伴 "夥伴A" 再次登出
      Then 系統應回傳成功

    Example: 登出 API 失敗時前端仍清除本機登入狀態
      Given 登出 API 將失敗
      When 夥伴 "夥伴A" 在報表頁點擊登出
      Then 本機的登入資訊應被清除
      And 畫面應導向登入頁

  Rule: 隔離（跨夥伴）- 網址的夥伴與本機登入身分不一致時必須遮蔽

    Example: 以夥伴A 的登入狀態開啟夥伴B 的報表網址
      Given 夥伴 "夥伴A" 已在瀏覽器登入
      When 使用者開啟 "/profit-report/partner-b/"
      Then 畫面不應顯示夥伴 "夥伴A" 的任何資料
      And 本機的登入資訊應被清除
      And 畫面應導向登入頁

  Rule: 前置（表單）- 登入頁必須從網址帶入 slug 且不可由使用者更改

    Example: 登入表單的 slug 由網址帶入並鎖定
      When 訪客開啟 "/profit-report/partner-a/"
      Then 登入表單的 slug 欄位應為 "partner-a"
      And 登入表單的 slug 欄位應為停用狀態

    Example: 被鎖定期間登入按鈕顯示倒數且停用
      Given 系統回應 429 且 Retry-After 為 600
      Then 登入按鈕應為停用狀態
      And 畫面應顯示剩餘等待秒數

    Example: 無法解析的 Retry-After 不得讓按鈕永久停用
      Given 系統回應 429 但 Retry-After 無法解析為數字
      Then 登入按鈕應為可用狀態
</content>
