# Feature 測試補齊 — Red→Green→Refactor 協調藍圖

> 建立：2026-08-07 ｜ 修訂：2026-08-07（納入 team lead 四則實證更新）
> 使用者需求原話：**「根據這些 feature files 補齊測試，確保全鏈路符合情境，並且測試通過」**
> 範圍：`specs/features/profit-shop/*.feature`（30 檔 / 255 Rule）× `tests/**`（103 檔 / 902 個 `test_` 方法）
> 本檔是**協調藍圖**，供主窗口逐一 spawn 下游 agent 照辦。診斷階段**已全部完成**，下游直接進入修復與補洞。

---

## 0. 一句話結論

**16 個紅燈裡有 12 個是真的產品缺陷，不是測試問題**——其中一個正在**持續造成生產環境資料遺失**，
另一個讓 11 條安全核心測試永久不可靠、並連帶製造至少 6 條**假綠燈**。
先修這兩個產品缺陷，再以 **Rule（255 條）為單位**盤點覆蓋、只補真缺口；
前端另立 vitest（僅限「錯了會收錯錢」的變體比對）+ Playwright 兩條線。

---

## 1. 診斷結論總表（team lead 已全部實證完成，**下游不需要再診斷**）

| 類別 | 數量 | 性質 | 處置 | 批次 | 狀態 |
|---|---|---|---|---|---|
| **產品缺陷・資料遺失** | 1 | `settings` / `items` 寫 postmeta 前未 `wp_slash()` | **改產品**（最高優先） | **B1** | ✅ 完成 |
| **產品缺陷・時間精度** | 11 | partner token 同秒撤銷 | **改產品** + 安全複審 | **B2** | ✅ 完成 |
| 測試過時 | 2 | IP hash / `show_in_rest` | 改測試（**絕不可改產品去遷就**） | B3 | ✅ 完成 |
| 測試寫法錯 | 2 | `RewriteRulesTest` | 改測試 | B4 | ✅ 完成 |
| skipped・可解除 | 1 | HPOS（已實測通過） | 制度化兩輪跑法 | B5 | ⬜ 待辦 |
| skipped・結構性 | 3 | WCS 是付費外掛 | 標明只能真實站台驗 | B5 | ⬜ 待辦 |

### B1–B4 完工驗收（實測，2026-08-07）

```
Unit          503 tests / 1164 assertions / 0 failure
Integration   403 tests / 1964 assertions / 0 failure / 4 skipped   ← 連跑 3 次逐字相同
Integration   403 / 1964 / 0 failure（HPOS=yes 輪）
phpcs inc/    0 errors
```

**16 個紅燈 → 0**。B2 硬要求 3 的
`git diff --stat -- <三個 partner 測試檔>` → **無輸出**
（既有 11 條斷言一行未動，是靠產品修復轉綠的，不是靠改測試）。

⚠️ B2 的修法在實作過程中被實測推翻一次並修訂，見決策 2 的「修訂紀錄」——
**純快照法會製造反向缺陷**，必須配合產生端的嚴格遞增。

⚠️ **這兩個產品缺陷符合同一個失效模式**：**寫在註解裡的行為斷言與實際行為相反，而 `phpcs` / PHPUnit / `tsc` 一個都攔不到**。
本專案已為此吃虧四次（TD-20 / TD-54 / TD-67 / 本次 B1），列為風險登記表第一條。

---

## 2. 七個決策點的裁決

### 決策 1｜優先序：先滅火再補洞 ✅，但「滅火」的內容是**修產品**不是修測試

**順序**：`B0 誠實基線` → `B1 資料遺失` → `B2 安全核心` → `B3/B4/B5（可平行）` → `B6 traceability` → `B7a–e 逐檔盤點（可平行）` → `B8 vitest` → `B9 Playwright` → `B10 文件同步`。

**B1 排在 B2 之前**的理由：B2 影響的是「測試套件可不可信」，B1 影響的是「**站長此刻正在遺失資料**」。
站長在 Phase 16 的自訂文字打一個雙引號，整包 `settings`（開賣時間 / 呈現設定 / 按鈕色 / 庫存顯示…）就消失，HTTP 200、前端顯示儲存成功、零錯誤訊息。這是生產中的資料遺失，優先於任何測試工程。

**B0 仍然要做**，但已因 team lead 完成診斷而大幅瘦身——它現在只剩兩個無可取代的產出：
① **假綠燈清單**；② 逐檔「宣告數 vs 執行數」對照（防 `@group` 靜默跳過）。

⚠️ **B2 完成後必然浮現新紅燈**：change-password 的 422 / 429 / Set-Cookie / Cache-Control 分支
**從來沒有任何一次自動化執行真正走到過**（都卡在 401）。那些紅燈是**新發現**，不是回歸，**禁止**以回退 B2 處理。

### 決策 2｜11 個 401：**產品缺陷**（team lead 已實證，修法已拍板）

**根因**（兩行程式碼疊加）：

1. `PartnerTermRepository.php:178` — `save()` 在**任何**密碼寫入時（**包含建立夥伴**）寫 `password_changed_at = time()`
2. `PartnerTokenStore::verify()` — Phase 6-A1 reviewer M-1 把 `<` 反轉成 `<=`，判 `issued_at <= password_changed_at` 為撤銷

`SystemClock` 是**秒**精度 ⇒ 同一秒內簽發的 token 一出生就是死的：`login` 回 200 並發 token，但那個 token 每次使用都 401。

**決定性證據**（team lead 實測，對**同一個 token** 做 before/after）：

```
[DIAG] changed_at=1786075085  me_before_backdate=401  me_after_backdate=200
```

把 `password_changed_at` 往前調 1 秒，同一個 token 從 401 變 200
⇒ 唯一成因就是這個同秒比較，與 transient / salt / cookie / wp-env 環境全部無關。

**這是時間競態不是穩定失敗**：同批診斷另一條跑在跨秒 → 200 通過。
⇒ 跑得快就紅、跨秒就綠。這也修正了 TD-36 把它記成「order-dependent flaky」的誤判。

**真實世界影響**：站長按「重新產生密碼」→ 立刻把密碼給夥伴 → 夥伴同秒登入 → 登入成功但 token 立刻失效；
夥伴自助改密後同秒重新簽發亦然；任何自動化 / API 整合更是必中。

#### 🔴 已實作的修法：**產生端嚴格遞增 + 消費端快照比對（兩者缺一不可）**

##### ⚠️ 修訂紀錄：純快照法單獨不夠，會製造反向缺陷（實作時實測發現）

本檔初版只寫了消費端的快照法（token payload 快照 `password_changed_at`，verify 比對「快照 === 當下值」）。
**實作後 11 條紅燈只轉綠 9 條，剩下 2 條症狀反轉**：

```
PartnerChangePasswordEndpointTest::test_old_token_revoked_after_self_change_password
PartnerPasswordRotationRevokesTokenTest::test_old_token_is_revoked_after_admin_regenerates_password
  → Failed asserting that 200 is identical to 401.   // 改密後舊 token 沒有失效
```

**根因**：初版表格寫「同秒改密 → 值一樣會變 → 仍然撤銷 ✅」，**那一列的前提不成立**——
`password_changed_at` 本身就是 `time()`（秒精度），同一秒內改密時寫回去的值與改密前**完全相同**
⇒ 快照當然對得上 ⇒ 判定為「密碼沒變」⇒ 舊 token 續用。
**快照法只是把問題往前推了一層，M-1 當初要閉合的 race window 被放了回來。**

##### 正解：問題在**產生端**，不在消費端

第一性原理：`password_changed_at` 這個欄位的**契約**是「每次密碼變更都要能被辨識」，
而 `time()` 無法履行這個契約。M-1 的 `<=` 與快照法都是在**消費端**補救資料本身的精度不足——那是治標。

```php
// PartnerTermRepository::next_password_changed_at()
private function next_password_changed_at( int $term_id ): int {
    $now  = time();
    $prev = (int) \get_term_meta( $term_id, self::META_PASSWORD_CHANGED_AT, true );
    return $now > $prev ? $now : $prev + 1;   // 同秒退而取 prev + 1
}
```

**保證嚴格遞增之後，兩條驗證路徑同時成立、都不需要補償**：

| 路徑 | 結果 |
|---|---|
| 快照值比對（現行 token） | 改密 → 值必變 → **撤銷** ✅ |
| 時間比較（legacy token 的 fallback，維持 `<=`） | 改密 → `changed_at` 變大 → **撤銷** ✅ |
| 同秒簽發的**合法** token | `password_changed_at` 不再與它相等 → **不再被誤殺** ✅ |

**代價**：同秒連續改密時該值會比真實時間略微超前（秒級，且只在連續改密時發生），
對它唯一的用途（撤銷判斷）完全無害。

**為何優於先前評估的兩個替代方案**：不改欄位語意、不改 interface、不動 `ClockInterface`／`FixedClock`，
只加一個 `max(now, prev+1)`。獨立計數器的遷移成本與毫秒精度的「兩處正規化易寫錯」風險在此皆不存在。

##### 連帶的回歸鎖（**這是整組修復最關鍵的一條測試**）

```
PartnerTermRepositoryGetPasswordChangedAtTest
  ::test_password_changed_at_strictly_increases_even_within_same_second
連續 3 次 save()（全程不 sleep），斷言值嚴格遞增。
```

⚠️ 值得記一筆：既有的 `..._updates_after_subsequent_save_with_new_password` **主動迴避了同秒**
（註解明寫「直接把 termmeta 倒退 2 秒…避免 time() 同秒情況」）。
它**知道**同秒有問題，卻選擇繞開而不是驗證——這正是缺陷能長期潛伏的原因之一。
⇒ **通則：測試裡任何「為了讓它穩定而繞開某個條件」的手法，都是在標示一個未被驗證的區域，
應該當成待辦而不是解法。**

red-green 實證（實作者已執行）：把 `next_password_changed_at()` 中性化回 `time()`
→ 新回歸鎖立刻紅（`Failed asserting that 1786076922 is greater than 1786076922`）→ 還原 → 殘留掃描 0。

🔴 **同一次探針下 `test_old_token_revoked_after_self_change_password` 反而沒紅（剛好跨秒）**
——再次印證 §4 B2 硬要求 1：**碰運氣的紅燈等於沒有紅燈**。
新增的這條回歸鎖是確定性的，比原有 11 條更能穩定守住這個不變量。

##### 連帶處理的既有測試

`PartnerTokenStorePasswordRotationTest::test_verify_treats_same_second_password_change_as_revoked`
原本明確斷言「同秒 = 已撤銷」且是綠的。語意**重新定義而非刪除**：改成「同秒改密（值真的變了）仍須撤銷」，
用 `FixedClock` + 不同的 `password_changed_at` 值表達，繼續守住 M-1 的原始意圖。

### 決策 3｜盤點策略：以 **Rule** 為單位，且**必須 Rule-by-Rule 拆前後端**

現查數字（非沿用文件）：

| 單位 | 數量 | 適合當盤點單位？ |
|---|---|---|
| `Rule:` | **255** | ✅ **選它**。Rule 是行為契約，一條 Rule ≈ 1..n 個 test method |
| `Example:` | 536 | ❌ 同一 Rule 下多個 Example 多半是同一契約的不同例證 |
| `Scenario Outline:` / 其資料列 | 51 / 192 | ❌ 同上 |
| 具體案例合計 | **728** | ❌ 逐條對映不可行也無必要 |

902 個既有方法對 255 條 Rule，平均每條 3.5 個 ⇒ **多數 Rule 應已覆蓋**。
B7 產出的「新增測試數」若異常龐大（> 150），代表把「已覆蓋」誤判成「未覆蓋」，退回重查。

🔴 **不可以用「檔案」判定前後端歸屬**——已實地核對 Rule 標題，反例明確：

| feature | 看似 | 實際 |
|---|---|---|
| `view-order-profit-panel` | 純前端面板 | 8 Rule 裡 **2 條是純後端**：`已知落差 - 訂單編號清單有筆數上限`（`ListSettlementOrderIdsUseCase` cap 200 + `truncated`）、`安全（權限）- 訂單編號清單端點要求站台管理權限` ⇒ **PHP 必測，且第二條是安全** |
| `view-order-profit-panel` | 「凍結值」要前端測 | 「凍結」的實質契約是**meta 存的就是凍結值**，寫入端已由 `OrderLineItemSettlementHookTest` 覆蓋；前端只負責讀出來顯示 |
| `select-shop-products` | 純前端 Modal | `加入動作不得覆寫既有商品已設定的覆寫值` 的**持久化面向**由 `ProfitShopItemsRoundTripTest` 覆蓋 |

⇒ B7 盤點時每條 Rule 都要先問「**這條契約的真相住在哪一層**」，再決定用 PHP / vitest / Playwright 哪一種測。

### 決策 4｜traceability：**feature 內註記（手寫）+ 產生式矩陣（唯讀）+ 驗證腳本（機器把關）**

```gherkin
  Rule: 後置（可用）- 沒有任何衝突時回報可用
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_available_slug_returns_true
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopValidateSlugEndpointTest.php::test_returns_available_true
```

| 元件 | 性質 | 為什麼需要 |
|---|---|---|
| `.feature` 內 `# @covered-by:` | **手寫、唯一真相** | **co-location**：改 Rule 的人一定看得到指標。獨立矩陣檔會無聲漂移——這正是本專案已吃虧四次的模式 |
| `specs/traceability.md` | **產生式、禁手改**（檔頭標 `<!-- GENERATED -->`） | 給人看的總覽（哪些 Rule 沒人守） |
| `bin/check-traceability.php` | **機器把關** | ① 每條 Rule 至少一筆註記；② **指到的檔案與方法必須真的存在**。**②才是防漂移的關鍵**，沒有②就不要做這套註記 |

允許三種註記值：`tests/…::method`（自動化）／`MANUAL`（結構性測不到，須附理由，例如 WCS）／`NOT-COVERED`（附理由與 TD 編號）。
後兩者要在產生的矩陣中**單獨列出**，讓「沒被自動驗證」這件事看得見。

**維護成本**：新增 Rule 多寫一行（低）；改測試方法名時腳本會擋（**這正是我們要的成本**）。

### 決策 5｜三項未驗證缺口

| 缺口 | 裁決 | 依據 |
|---|---|---|
| **TD-88 HPOS** | ✅ **已解除（自動化這一半）** | team lead 實測：測試 DB 內 `wp_wc_orders` / `wp_wc_orders_meta` **本來就存在**，翻一個 option 即可。HPOS=yes 下 `WpSettlementSummaryProviderRealAggregationTest` 27 tests / 363 assertions / 0 failure；完整 integration testsuite 兩種模式**失敗清單逐字相同**（同樣 15 條、assertions 同為 1904）⇒ 三處 HPOS 分支實際上是正確的。剩下工作是**制度化兩輪跑法**（B5） |
| **夥伴報表前台** | ⚠️ **API 層由 B2 自動解鎖**，瀏覽器層歸 B9 | `login → kpi/trend/settlements` 本來就該由 IT 覆蓋，而那正是 B2 解鎖的東西，不需要獨立批次 |
| **訂閱續訂（WCS）** | ❌ **不納入**，改為讓 skip 變大聲 | WCS 是付費外掛，wp-env **結構性**裝不了。**不要規劃成「想辦法讓它在 wp-env 跑起來」**。要求：skip 訊息據實說明 + feature 註記標 `MANUAL` + 真實站台驗收清單 |

**4 個 skipped 的完整定性**（team lead 已查證）：

| skipped | 原因 | 可否解除 |
|---|---|---|
| `WpSettlementSummaryProviderRealAggregationTest::test_summary_works_under_hpos_enabled` | HPOS 未啟用 | ✅ 翻 option 即跑且通過 |
| `RenewalProfitHookTest::test_real_wcs_renewal_order_resets_paid_status` | WCS 未安裝 | ❌ 只能真實站台 |
| `ProfitShopFrontDataVariationsTest::test_variable_subscription_variations_are_built` | 同上 | ❌ 同上 |
| `ProfitShopShortcodeTest::test_variable_subscription_without_purchasable_variation_renders_disabled_card` | 同上（TD-64） | ❌ 同上 |

🔴 **順帶抓到的假安全感**：`WpSettlementSummaryProviderRealAggregationTest.php:491` 的 skip 訊息寫著
「CI matrix with-wc-hpos 才會跑此 case」，但 `grep -rn "hpos\|HPOS\|custom_orders_table" .github/` → **零命中**，
**那個 CI matrix 根本不存在**。任何人讀到它都會以為「CI 有在跑」——這正是 TD-88 能長期沒被發現的原因。
同型的還有 `test_summary_works_under_legacy_post_orders`（HPOS 開時 skip）：**兩條互斥，永遠只有一條會跑**。

### 決策 6｜分批策略

見 §4。三條原則：
1. **B1 / B2 各自單獨先做**（都是產品改動，且 B2 會改變 partner 相關測試的整個基線）
2. 可平行的批次**嚴格分檔**（Pitfall #36：同一檔案同一時間只能有一方做 red-green）
3. 每個 Batch 的綠燈驗收都是**可執行指令 + 明確數字**

### 決策 7｜前端測試策略：**A + B 混合，但 A 走 jsdom 驅動 DOM，零產品改動**

先講一個決定走法的硬事實（我實地查證）：

```
inc/assets/js/profit-shop-front.js  →  ( function () { … } )();   // IIFE
grep export|module.exports          →  零命中
findBestVariation() / matchVariation() / isAllAttributesChosen()
                                    →  巢狀在 bindVariations() 閉包內（行 602/653/773 縮排兩層）
```

⇒ **`findBestVariation()` 目前無法被 import**。要用「純函式單元測試」就必須把它提到頂層並加 export——
那是**對一個零測試覆蓋的收錢路徑動產品碼**，而且 Pitfall #35 訂的純重構驗收標準（「既有測試零修改全綠」）
在這裡**根本不適用**（沒有既有測試）。先改再測是把順序做反了。

**裁決**：

| 線 | 工具 | 範圍 | 為什麼 |
|---|---|---|---|
| **A** | **vitest + jsdom**（新增 2 個 devDependency） | **只**用於 `select-product-variation` 的 6 條演算法 Rule | 全案唯一「錯了會**收錯錢**、且三道閘門沒有任何一道會發現」的邏輯（Pitfall #31 花大篇幅保護它，實測舊寫法 12.4% 挑錯率），**目前零自動化測試**。純邏輯、可組合窮舉，投報率最高 |
| **B** | **Playwright**（不新增依賴） | `select-shop-products` Modal 動線、`enrich-shop-item-display` 四分支降級、`view-order-profit-panel` 面板渲染與訂單列表過濾 | 失敗模式是**畫面看得到**的，e2e 足夠；且已有 `06-profit-shop` 基礎設施 |
| **PHP** | 既有 | 從這四個 feature **切出來的後端 Rule**（見決策 3 的表） | 契約真相住在後端，本來就該用 PHP 測 |

🔴 **A 的實作方式：jsdom 建 DOM → 執行 IIFE → 點屬性按鈕 → 斷言 `[data-ps-variation-id]` 的值。零產品改動。**

```
setup: environment='jsdom'
  1. 用 PHP template 印出的真實結構建 DOM：
     .ps-profit-shop-front[data-ps-shop] > [data-ps-modal-for] >
       <script type="application/json" class="ps-variations-data">…</script>
       + [data-ps-attr-name] 屬性按鈕 + [data-ps-variation-id] hidden input
  2. new Function(readFileSync('inc/assets/js/profit-shop-front.js'))()   // 執行 IIFE
  3. 模擬點擊屬性按鈕
  4. 斷言 hidden input 的 variation_id / 加購按鈕 disabled 狀態
```

這比「重構成純函式再測」更好的三個理由：
1. **零產品改動** —— 不必先動一段沒有安全網的收錢邏輯
2. **測到的是真正的契約** —— 「最後送進購物車的是哪一個 `variation_id`」，而不是某個內部函式的回傳值
3. **順帶覆蓋 Pitfall #23 的 DOM 契約** —— `data-ps-*` 屬性被改名時測試會紅

**必含的回歸鎖（Pitfall #31 的分水嶺，不可省）**：

```
變體 #1 { color: '',    size: '' }   ← catch-all
變體 #2 { color: 'red', size: '' }   ← 具體覆寫
使用者選 red + l  ⇒  送出的 variation_id 必須是 #2
```
這是「命中即 return」與「具體度計分」的分水嶺，也是唯一能擋住有人為了簡化而改回舊寫法的鎖。
另建議加一組差分測試（隨機生成變體集，與獨立實作的裁判函式比對），把 12.4% 那個數字鎖成 0。

**不選 C（純手動驗收清單）**：`select-product-variation` 的失敗模式是**靜默收錯錢**，
沒有人會在每次改動後手動跑 24 種變體組合。手動清單擋不住它。
**不選「只用 B」**：Playwright 測不到演算法正確性（組合窮舉一次幾十秒，做不了差分）。

**成本與前提**：`vitest`（對應 vite ^6.3.5 → vitest ^3.x）+ `jsdom` 兩個 devDependency
+ 一份 `vitest.config.ts` + `package.json` 加 `test:js` 腳本；不影響既有 `pnpm build`。
⚠️ **新增 devDependency 屬倉庫層級決定，B8 動工前需 team lead 最終點頭**（team lead 已表態傾向 A+B）。

---

## 3. 已確認的基線事實（可直接信任）

| 事實 | 值 | 查證方式 |
|---|---|---|
| `test_` 方法總數 | **902**（Unit 503 / Integration 399） | `grep -rho 'public function test_' tests/ --include=*.php \| wc -l` |
| 實際執行數 | 503 + 399 = **902** | 與宣告數**完全相符** |
| ⇒ **目前零 `@group` 靜默跳過** | ✅ | 上兩列相減為 0。**B7 之後必須維持這個等式** |
| feature 檔 | 30 檔，**全部 `@ignore`** | 無任何 Gherkin runner ⇒「測試通過」= PHPUnit / Playwright 通過，**不是**跑 `.feature` |
| `Rule:` / `Example:` / Outline 資料列 | 255 / 536 / 192 | 見決策 3 |
| traceability | **0** | `grep -rl "tests/" specs/features/profit-shop/*.feature` → 0 |
| 前端測試框架 | **無** vitest / jest / testing-library；Playwright 24 spec（`06-profit-shop` 僅 1 個） | `package.json` |
| `profit-shop-front.js` | **IIFE、零 export**，關鍵函式巢狀在閉包內 | 見決策 7 |
| HPOS | 測試 DB **兩張表本來就在**，翻 option 即可，且**兩輪測試結果逐字相同** | team lead 實測 |
| `wp rewrite flush` | **只跑在 `cli`，沒跑在 `tests-cli`** | `.wp-env.json` `afterStart` |

---

## 4. Batch 詳表

> **進度**：`B0` ✅ ／ `B1` ✅ ／ `B2` ✅ ／ `B3` ✅ ／ `B4` ✅ ／ **`B5` ⬅ 下一個** ／ `B6` ／ `B7a–e` ／ `B8` ／ `B9` ／ `B10`
> B1–B4 的驗收數字見 §1。以下 B1–B4 章節保留為**已執行的規格**（供日後追溯與同型問題參照），不再是待辦。

### B0｜誠實基線（測量批次，不改任何程式碼）✅ 已完成

| 項 | 內容 |
|---|---|
| **目標** | ① 產出**假綠燈清單**；② 逐檔「宣告數 vs 執行數」對照 |
| **動哪些檔** | 無（只讀）。記錄寫進 `specs/plan/2026-08-07-baseline.md` |
| **派誰** | `general-purpose`（或主窗口自行執行） |
| **綠燈驗收** | 交付物含上述兩項；且逐檔對照表全部相等（目前應為 902 == 902） |
| **規模** | 小 |

假綠燈候選的機械篩法：

```bash
# 「斷言 401/403 且與 partner token 有關」的測試 —— 在 B2 修好前無法證明自己有效
grep -rn "assertSame( *401\|assertSame( *403" tests/Integration/Infrastructure/Rest/Partner*.php
```

### B1｜🔴 `settings` / `items` 未 `wp_slash`（產品缺陷・資料遺失・**最高優先**）

| 項 | 內容 |
|---|---|
| **目標** | 修掉正在生產環境造成資料遺失的缺陷 |
| **動哪些檔** | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/CptProfitShopRepository.php:292-298` + 對應測試 |
| **派誰** | `general-purpose` |
| **紅燈定義** | 既有 `test_save_round_trip_with_chinese_characters` 已紅；**另須新增三條**（純雙引號 / 純反斜線 / 兩者），修復前必紅 |
| **規模** | 小（產品改動兩行 + 註解改寫） |

**缺陷內容**（team lead 實證，**不需要再診斷**）：

```php
// 現況（:292-298）
// 註解宣稱：「JSON 內不含 magic-quote 字元…因此不需 wp_slash 包裹」
$settings_json = (string) \wp_json_encode( $shop->settings, JSON_UNESCAPED_UNICODE );
\update_post_meta( $post_id, self::META_SETTINGS, $settings_json );
```

🔴 **那段註解的推理錯得很微妙**：它說「會被 json_encode 自行 escape，所以不需 wp_slash」——
但 **json_encode 的 escape 動作本身就是在字串裡放進反斜線**，而 `update_post_meta()` → `update_metadata()`
內部會 `wp_unslash()` 把它吃掉。**escape 不是保護，escape 正是受害者。**

實測結果：

| 輸入 | 寫入 | 讀回 | 後果 |
|---|---|---|---|
| 含**雙引號** | `{"note":"他說\"限時優惠\"開跑"}` | `{"note":"他說"限時優惠"開跑"}` | JSON 破損 → `decode_settings()` 回 `[]` → **settings 全滅** |
| 含**反斜線** | `{"note":"a\\b"}` | `{"note":"a\b"}` | `decode_ok=true` 但 **`\b` 被解讀成 backspace ⇒ 值被靜默竄改**（更陰險） |
| 純中文 / 單引號 / 斜線 | — | — | 安全 |

**真實觸發路徑（不需攻擊者，站長正常操作即可）**：Phase 16 的 `beforeText` / `afterText` 是站長自由輸入，
經 `WP::sanitize_text_field_deep()` 後雙引號**原樣保留** ⇒ 站長在「已結束販售時顯示的文字」打一句
`他說"限時優惠"已結束` → 按儲存 → **整個 settings 消失**（`startTime` / `endTime` / `btnColor` /
`showStock` / `showBuyerCount` / `beforeDisplay` / `afterDisplay` 全部歸零），**HTTP 200、前端顯示儲存成功、零錯誤訊息**。

**修法**：

```php
\update_post_meta( $post_id, self::META_ITEMS,    \wp_slash( $items_json ) );
\update_post_meta( $post_id, self::META_SETTINGS, \wp_slash( $settings_json ) );
```

**四條硬要求**：

1. 🔴 **那段錯誤註解必須一併改寫** —— 只改程式碼不改註解，下一個人會照它的推理把 `wp_slash()` 拿掉。
   這與 CLAUDE.md Pitfall #25（明文密碼必須先 `wp_slash()`）**是同一個機制**，那條 Pitfall 已寫得很清楚，
   而這個檔案的註解卻宣稱相反的事，兩者並存了很久沒人發現
2. **三條新回歸測試**（雙引號 / 反斜線 / 兩者），且**反斜線那條必須斷言值逐字相同**——
   它的 `decode_ok` 是 `true`，只斷言「解得開」會漏掉靜默竄改
3. **`items` 與 `settings` 兩處都要修**（`items` 目前不含使用者自由文字，但沒有理由留一個一樣的地雷）
4. **向後相容說明寫進交付紀錄**：既有已存資料是「沒 slash 過的」，讀取端不受影響（`get_post_meta` 不做 slash 處理）
   ⇒ **不是 breaking change、不需要 migration**；但 ⚠️ **已經被破壞的 settings 救不回來**（資料已遺失），這點要如實告知站長

**綠燈驗收**：

```bash
$B --testsuite integration --filter CptProfitShopRepositoryTest --testdox   # 全綠（含 3 條新增）
git diff -- inc/  # 僅 CptProfitShopRepository.php，且註解已改寫
```

### B2｜🔴 Partner token 同秒撤銷（產品缺陷・安全核心）

| 項 | 內容 |
|---|---|
| **目標** | 以**快照法**修掉根因，讓 11 條 401 在**不放寬任何既有斷言**的前提下轉綠 |
| **動哪些檔** | 產品：`Application/Service/PartnerTokenStore.php`（`issue()` + `verify()`）。測試：既有 11 條**只准新增不准改斷言**；`PartnerTokenStorePasswordRotationTest` 那一條依決策 2 重新定義語意 |
| **派誰** | `general-purpose`（先載入 `zenbu-powers:systematic-debugging` skill）。**完成後 opt-in 派 `@zenbu-powers:security-reviewer` 複審**（安全核心，必要） |
| **紅燈定義** | 見下（必須是**確定性**紅燈） |
| **規模** | 中（產品改動小，驗證與複核成本高） |

**四條硬要求**：

1. **新增確定性紅燈測試**：用 `FixedClock` 或明確把 `_partner_password_changed_at` 設為 `time()`，
   **同秒**呼叫 login，斷言取得的 token 打 `GET /partner-auth/me` 回 **200**。
   🔴 **不可用「多跑幾次剛好會紅」當紅燈**——這是時間競態，碰運氣的紅燈等於沒有紅燈
2. **M-1 契約必須維持並仍有測試守著**：`PartnerTokenStorePasswordRotationTest::test_verify_treats_same_second_password_change_as_revoked`
   **改語意、不刪除**——改成「同秒改密（值真的變了）仍須撤銷」，用 `FixedClock` + 不同的 `password_changed_at` 值表達
3. **既有 11 條測試斷言零修改**：
   ```bash
   git diff --stat -- tests/Integration/Infrastructure/Rest/PartnerChangePasswordEndpointTest.php \
                       tests/Integration/Infrastructure/Rest/PartnerPasswordRotationRevokesTokenTest.php \
                       tests/Integration/Infrastructure/Rest/PartnerReportsScopeTest.php
   # 期望：三檔皆無變更（新增輔助 helper 可接受，但既有斷言一行都不准動）
   ```
4. 🔴 **驗收必須連跑 3 次**（時間競態不能靠跑一次判定），或改用 `FixedClock` 讓它與時鐘解耦

**綠燈驗收指令**：

```bash
cd "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-shop"
B="./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop -- vendor/bin/phpunit -c phpunit.xml.dist --colors=never"

# ① 三個檔連跑 3 次全綠（證明不是碰運氣跨秒）
for i in 1 2 3; do $B --testsuite integration --filter 'PartnerChangePasswordEndpointTest|PartnerPasswordRotationRevokesTokenTest|PartnerReportsScopeTest' --testdox; done

# ② integration failure 數 15 → 4（減 11），且沒有新增任何 failure 名稱
$B --testsuite integration | tail -5

# ③ 🔴 產生端嚴格遞增的確定性回歸鎖（最關鍵的一條）
$B --testsuite integration --filter PartnerTermRepositoryGetPasswordChangedAtTest --testdox
#   必含 test_password_changed_at_strictly_increases_even_within_same_second
#   （連續 3 次 save()、全程不 sleep、斷言嚴格遞增）
```

⚠️ **B2 完成後必做**：逐條複核 §5 R-2 的假綠燈清單。轉紅者是**好消息**（它終於開始真的測東西了），
當新發現 triage，**禁止**回退 B2。

**實際結果**：11 條全轉綠且既有斷言零修改；期間實測推翻「純快照法即可」的假設，
修訂為「產生端嚴格遞增 + 消費端快照比對」，詳見決策 2 的修訂紀錄。

### B3｜兩條過時測試（**改測試，嚴禁改產品**）

| 項 | 內容 |
|---|---|
| **動哪些檔** | `tests/Unit/Application/Service/LoginRateLimiterIpDimensionTest.php`、`tests/Integration/Infrastructure/WordPress/CptRegistrarTest.php` |
| **派誰** | `zenbu-powers:test-creator` |
| **綠燈驗收** | 兩檔全綠 **且 `git diff -- inc/` 必須為空** |
| **規模** | 小 |

| 測試 | 期望 | 產品實際 | 誰對 |
|---|---|---|---|
| `test_ip_is_hashed_in_transient_key` | key 含 `hash('sha256',$ip)` | `LoginRateLimiter:323` 用 `hash_hmac('sha256',$ip,$salt)`，prefix 已升 `..._ip_v2_` | **產品對**（更安全）。同測試的「不得含明文 IP」斷言**目前就是通過的**，PII 保護未失效 |
| `test_cpt_supports_block_editor_via_rest` | `show_in_rest === true` | `CptRegistrar:91` 為 `false`，程式碼註解寫明理由：**避免 author+ 經由 `wp/v2/powershop` 繞過 V2Api 權限檢查** | **產品對**。改成 `true` 是**權限提升漏洞** |

🔴 **明令禁止**把 `LoginRateLimiter` 改回純 `sha256`、或把 `show_in_rest` 改成 `true`。
測試改動時**必須在 docblock 寫明為什麼期望值改了**，否則下一個人會再改回來。

### B4｜`RewriteRulesTest` 兩條（測試寫法錯，產品是對的）

| 項 | 內容 |
|---|---|
| **動哪些檔** | `tests/Integration/Infrastructure/WordPress/RewriteRulesTest.php` |
| **派誰** | `zenbu-powers:test-creator` |
| **綠燈驗收** | 兩條全綠；`git diff -- inc/ .wp-env.json` 為空 |
| **規模** | 小 |

**精確成因**（team lead 已查證）：

1. `test_profit_report_rewrite_rule_exists`：全專案 `grep -rn "set_permalink_structure" tests/` → **零命中**。
   `permalink_structure` 為空（plain permalinks）時 WordPress **根本不產生任何 rewrite rules**，
   `get_option('rewrite_rules')` 必然是空字串。測試漏了 `$this->set_permalink_structure('/%postname%/')`
2. `test_profit_report_query_var_is_registered`：測試只呼叫 static `RewriteRules::register()`——
   那個方法**只做 `add_rewrite_rule()`**，`query_vars` filter 掛在 `__construct()`（要 `instance()`）。
   filter 從未掛上；且 `$wp` 物件在 bootstrap 早就建好，filter 不會回溯套用

**選 hermetic 修法（測試自己準備前置條件）而非改 `.wp-env.json`**：測試的前置條件應由測試自己保證。
依賴容器 lifecycle 會讓換機器 / 重建容器 / CI 時再次無聲失敗，而那種失敗的長相與真正的產品缺陷**完全一樣**。

### B5｜HPOS 兩輪制度化 + 修掉虛構的 skip 訊息

| 項 | 內容 |
|---|---|
| **目標** | 讓兩條互斥測試**都**被執行；消滅指向不存在 CI matrix 的假安全感 |
| **動哪些檔** | `WpSettlementSummaryProviderRealAggregationTest.php:491`（skip 訊息）+ 驗收文件；`.wp-env.json` 順帶補 `tests-cli` 的 rewrite（可選） |
| **派誰** | `zenbu-powers:test-creator` |
| **綠燈驗收** | **兩輪都綠**（見下） |
| **規模** | 小 |

```bash
# 輪 1（現況）
./tests/e2e/node_modules/.bin/wp-env run tests-cli -- wp option update woocommerce_custom_orders_table_enabled no
$B --testsuite integration
# 輪 2（HPOS）
./tests/e2e/node_modules/.bin/wp-env run tests-cli -- wp option update woocommerce_custom_orders_table_enabled yes
$B --testsuite integration
# 還原（不可省）
./tests/e2e/node_modules/.bin/wp-env run tests-cli -- wp option update woocommerce_custom_orders_table_enabled no
```

#### 執行細節

**已查證的現況**（下游不需重查）：

| 項 | 事實 |
|---|---|
| `test_summary_works_under_hpos_enabled` | `:486`，`:490` 判 `! OrderUtil::custom_orders_table_usage_is_enabled()` → `:491` skip，訊息**虛構** |
| `test_summary_works_under_legacy_post_orders` | `:511`，`:512` 判 HPOS 已啟用 → `:513` skip，訊息**是事實**（不用改內容，但要補互斥說明） |
| 兩者的 `@group` | `@group happy` + `@group hpos_compatible`，docblock 已註明「`@group happy` 不可移除」（Pitfall #37 的既有防護，**別動它**） |
| `grep -rn "hpos\|HPOS\|custom_orders_table" .github/` | **零命中** ⇒ `with-wc-hpos` matrix 不存在 |
| CI 跑法 | `.github/workflows/pipe.yml:681 / :722 / :762` 三個 cycle，`continue-on-error` + 中間夾 AI 修復步驟 |
| `.wp-env.json` `afterStart` | 對 `cli` 與 `tests-cli` **都**設 `woocommerce_custom_orders_table_enabled no` |

**必做四項**：

1. 🔴 **`:491` 訊息改成可驗證的事實**。不得再指向流程承諾。建議：
   ```
   'HPOS 未啟用（woocommerce_custom_orders_table_enabled != yes）。
    本地需手動翻 option 跑第二輪，見 .claude/rules/wordpress.rule.md §11 HPOS 兩輪跑法。'
   ```
2. **`:511` 的 docblock 補一句互斥說明**：「本條與 `test_summary_works_under_hpos_enabled` **互斥**，
   單輪只會執行其中一條；兩條合計需要兩輪才算全覆蓋。」——訊息本身是事實不用改，但沒有這句，
   下一個人仍會以為單輪跑完就覆蓋了。
3. **制度化跑法**：`composer.json` 新增兩個 script（**不要**改 `.wp-env.json` 的 `afterStart` 預設值，
   預設維持 `no` 才不會動到既有 399→403 條測試的執行環境）：
   ```
   test:it        → HPOS=no  輪
   test:it:hpos   → HPOS=yes 輪，且**跑完必還原成 no**
   ```
   🔴 **還原必須用 `trap` / `|| true` 保證即使測試失敗也會執行**。忘了還原的後果特別陰險：
   環境卡在 `yes`，下一個人跑「輪 1」時 `legacy_post_orders` 會被 skip 而**兩輪都綠依然成立** ⇒ 假綠燈。
4. **文件**：`.claude/rules/wordpress.rule.md` §11 新增「HPOS 兩輪跑法」小節（含還原的必要性）。

**選配（建議做，做了才能讓那句訊息重新變成真話）**：在 `pipe.yml` 最終測試 cycle **之後**追加一個
獨立步驟跑 HPOS 輪（**不要**用 job-level `strategy.matrix`——那會讓整個含 AI 自動修復的 job 跑兩份，
且 AI 在 HPOS=yes 下的修復可能弄壞 HPOS=no）。該步驟不接 AI 修復，純 pass/fail。

#### 🔴 綠燈驗收：不是「兩輪都綠」，是「兩輪的 skip 清單互補」

「兩輪都綠」**不足以**驗收——兩輪都把該條 skip 掉，一樣是全綠。必須逐條確認執行狀態互補：

| 測試 | 輪 1（HPOS=no） | 輪 2（HPOS=yes） |
|---|---|---|
| `test_summary_works_under_legacy_post_orders` | **✔ 執行** | ↩ skip |
| `test_summary_works_under_hpos_enabled` | ↩ skip | **✔ 執行** |

```bash
# 逐輪檢查（--testdox 下 ✔ = 執行、↩ = skipped）
$W wp option update woocommerce_custom_orders_table_enabled no
$B --testsuite integration --filter WpSettlementSummaryProviderRealAggregationTest --testdox | grep -E "hpos enabled|legacy post orders"
$W wp option update woocommerce_custom_orders_table_enabled yes
$B --testsuite integration --filter WpSettlementSummaryProviderRealAggregationTest --testdox | grep -E "hpos enabled|legacy post orders"
$W wp option update woocommerce_custom_orders_table_enabled no    # 還原，不可省
```

其餘驗收：兩輪的 `Tests:` 行數字相同、failure 皆 0、skipped 皆 4（**且第 4 條的組成在兩輪中不同**）。

### B6｜traceability 基礎建設

| 項 | 內容 |
|---|---|
| **動哪些檔** | 新增 `bin/check-traceability.php`；新增 `specs/traceability.md`（產生式）；對 7 個結算 feature 加註記當範本 |
| **派誰** | `general-purpose` |
| **紅燈定義** | 腳本對 30 檔回報 255 條 Rule 全數缺註記 |
| **綠燈驗收** | 腳本可執行；7 個結算 feature 的 Rule 全數有註記且**指到的方法都真實存在**；其餘 23 檔如實回報缺口（**不是**全綠） |
| **規模** | 中 |

先做 7 個結算 feature 的理由：它們是**正向 TDD** 產出的（規格先於實作，commit `70a704f` → `866fbbd`），
對應測試最明確，適合當範本；另外 23 個是逆向補齊的（commit `0eb0342`，該 commit **零測試檔**），
對應關係要靠 B7 逐條查。

#### 註記格式規格

**位置**：緊接在 `Rule:` 那一行下方，**第一個 `Example:` / `Scenario Outline:` 之前**，縮排 4 空格。
（已查證的檔案排版：`Rule:` 為 **2 空格**縮排、`Example:` / `Scenario Outline:` 為 **4 空格**、步驟 6 空格。）

**五種合法值**：

```gherkin
    # @covered-by: tests/Unit/…/XxxTest.php::test_method_name          ← PHPUnit
    # @covered-by: tests/e2e/06-profit-shop/xxx.spec.ts::測試標題        ← Playwright
    # @covered-by: tests/js/xxx.test.ts::測試標題                        ← vitest（B8 起）
    # @covered-by: MANUAL — <理由>                                      ← 結構性測不到（如 WCS）
    # @covered-by: NOT-COVERED — <理由> (TD-xx)                         ← 已知缺口，須有 TD 編號
```

#### `bin/check-traceability.php` 規格

**用法**：`php bin/check-traceability.php [feature檔...]`（不給參數 = 全掃 `specs/features/profit-shop/*.feature`）

**Parser**：

| 樣式 | 動作 |
|---|---|
| `^  Rule:\s*(.+)$` | 開啟新 Rule 區塊 |
| `^\s*#\s*@covered-by:\s*(.+?)\s*$` | 歸屬於當前 Rule（僅在「Rule 已開啟且尚未遇到第一個 Example/Scenario」時採計） |
| `^    (Example:\|Scenario:\|Scenario Outline:)` | 關閉當前 Rule 的**註記採計視窗**（Rule 本身仍繼續） |
| `^(Feature:\|  Background:)` | 關閉 Rule 區塊 |

⚠️ `Example:`（單數，Gherkin 6 的 Scenario 同義字）與 `Examples:`（複數，Outline 資料表）**必須分辨清楚**，
本專案兩者都大量存在（536 / 51）。

**四項檢查**：

1. 每條 `Rule:` 至少 1 筆 `@covered-by`
2. PHPUnit 指標：檔案存在 **且** `preg_match('/function\s+' . preg_quote($method,'/') . '\s*\(/', $src)`
3. TS 指標（e2e / vitest）：檔案存在 **且** 標題字串出現在檔內
4. `MANUAL` / `NOT-COVERED`：`—` 之後必須有非空理由；`NOT-COVERED` 另需 `(TD-\d+)`

🔴 **檢查 2 / 3 是這整套設計的價值所在**——沒有它，這份註記三個月內就會變成另一份「寫著卻不成立」的文件
（本專案已為此吃虧四次，見 R-1）。

**離場行為**：任一項不通過 → 逐條印到 stderr → `exit(1)`。全數通過 → `exit(0)`。
⚠️ **某個 feature 檔解析出 0 條 Rule 時必須報錯**，不可回報「0 problems」——那是 parser 壞掉的指紋。

**副產出**：`specs/traceability.md`（檔頭 `<!-- GENERATED by bin/check-traceability.php — 請勿手改 -->`），
內容為：總計摘要（Rule 數 / 自動化 / MANUAL / NOT-COVERED / 缺註記）、依 feature 檔的明細表、
以及 **MANUAL 清單**與 **NOT-COVERED 清單**兩張單獨列出的表——
讓「沒被自動驗證的區域」在矩陣上看得見，而不是混在一片綠色裡。

#### 可直接使用的格式範例（**已逐一查證方法真實存在**）

`specs/features/profit-shop/validate-shop-slug.feature` 的 5 條 Rule：

```gherkin
  Rule: 前置（格式）- 永久連結必須為 1 至 60 字元的小寫英數、底線與連字號
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_throws_invalid_partner_slug_when_slug_format_invalid
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_throws_invalid_partner_slug_when_slug_empty
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_throws_invalid_partner_slug_when_slug_too_long

  Rule: 後置（可用）- 沒有任何衝突時回報可用
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_returns_available_when_no_conflicts

  Rule: 後置（衝突）- 命中任一類衝突時回報不可用並附衝突來源
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_returns_unavailable_when_wordpress_reserved
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_returns_unavailable_when_powershop_cpt_conflict
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_returns_unavailable_when_partner_term_conflict
    # @covered-by: tests/Unit/Application/UseCase/Shop/ValidateSlugUseCaseTest.php::test_returns_unavailable_when_multiple_conflicts

  Rule: 後置（草稿）- 草稿賣場佔用的永久連結視為衝突
    # @covered-by: tests/Integration/Infrastructure/Rest/ProfitShopValidateSlugEndpointTest.php::test_get_validate_slug_returns_conflicts_for_existing_draft_powershop_slug

  Rule: 前端（即時）- 輸入時延遲驗證，且編輯自己時不與自己衝突
    # @covered-by: NOT-COVERED — SlugInput.tsx 的 debounce 與 currentSlug 自我比對，屬前端行為；待 B9 e2e (TD-xx)
```

🔴 **最後一條就是決策 3 的活教材**：`validate-shop-slug` 看起來是純後端 feature，
卻藏著一條前端 Rule。**整檔判定必漏，只能 Rule-by-Rule。**

### B7a–B7e｜逐 feature 盤點與補測（30 檔分 5 組，**可平行**）

| 項 | 內容 |
|---|---|
| **目標** | 每條 Rule 標註覆蓋來源；**只對真缺口補測** |
| **派誰** | `zenbu-powers:test-creator` × 5（分檔互斥，可同時 spawn） |
| **紅燈定義** | 新補的每一條測試都必須**先看到紅燈** |
| **規模** | 大（本任務主體） |

| 組 | feature 檔 | Rule | 對應產品區塊 |
|---|---|---|---|
| **B7a 賣場 CRUD / 規格** | `save-profit-shop` `change-shop-status` `query-shop-detail` `validate-shop-slug` `select-shop-products`※ `import-legacy-shop` | 45 | Application/UseCase/Shop、Migration |
| **B7b 商品覆寫 / 變體** | `save-shop-item-overrides` `save-variation-overrides` `enrich-shop-item-display`※ `select-product-variation`※ `apply-single-product-override-price` `authorize-priceless-cart-item` | 47 | Domain/Service、OverridePriceFilters、SingleProductPriceHook |
| **B7c 前台 / 加購** | `render-shop-front` `render-shop-via-shortcode` `schedule-shop-availability` `add-to-cart-from-modal` `attribute-cart-item-to-shop` `apply-cart-override-price` | 63 | WordPress/templates、AddToCartHook、CartPriceOverrideHook |
| **B7d 夥伴 / 認證** ✅ 前置 B2 已完成，可派 | `login-partner` `change-partner-password` `manage-partner-credentials` `manage-profit-partner` `query-partner-report` | 44 | PartnerTokenStore、PartnerAuthService、LoginRateLimiter、V2Api partner-* |
| **B7e 結算全鏈路** | `attribute-order-line-item` `reverse-settlement-on-refund` `carry-profit-to-renewal-order` `mark-settlement-paid` `query-settlements` `export-settlements-csv` `view-order-profit-panel`※ | 56 | Settlement UseCase、Order*Hook、WpAdminSettlementQuery |

※ = 含前端 Rule。這些組**只負責切出後端 Rule 並用 PHP 補測 + 把前端 Rule 標註為
`@covered-by: tests/e2e/…`（B9）或 `tests/js/…`（B8）**，前端測試本身由 B8 / B9 寫。
🔴 切分依據見決策 3——**不可以整檔判定為「前端不可測」**。

#### 🔴 派工策略：**B7a 先當試點跑完並驗收，再平行派其餘四組**

五組同時開跑的風險不是檔案衝突（已分檔），而是**五份格式與判定尺度不一致的產出**，
回頭統一的成本遠高於先跑一組。B7a 是最小組（6 檔 / 45 Rule）且含 §B6 已備好完整範例的
`validate-shop-slug`，適合當試點。試點驗收通過（格式、判定理由品質、新增測試數合理）後才平行派 B7b–B7e。

#### 每組執行流程（強制，六步）

**第 1 步：Rule 分類 —— 先問「這條契約的真相住在哪一層」**

| 判定 | 特徵 | 測法 |
|---|---|---|
| **後端契約** | 寫入什麼 meta / API 回什麼 / 誰有權限 / 狀態怎麼轉 / 金額怎麼算 | PHPUnit（Unit 或 IT） |
| **前端演算法** | 純邏輯、輸入→輸出可判定（目前只有變體比對） | vitest（B8） |
| **前端動線** | 使用者操作序列 → 畫面/表單狀態 | Playwright（B9） |
| **已知落差 / 刻意不修** | Rule 標題含「已知落差」 | 用測試把**現況**鎖成明示斷言（防無意間改變），或 `NOT-COVERED` + TD 編號 |

⚠️ **不可以看檔名決定**。已驗證的反例：`view-order-profit-panel` 的
`已知落差 - 訂單編號清單有筆數上限`（`ListSettlementOrderIdsUseCase` cap 200 + `truncated`）與
`安全（權限）- 訂單編號清單端點要求站台管理權限` **都是純後端**，第二條還是安全 Rule；
`validate-shop-slug` 反過來藏著一條前端 Rule。

**第 2 步：在既有 902 個 method 裡找**。搜尋順序建議：對應 UseCase / Hook 的測試檔 →
關鍵字 grep（Rule 標題裡的名詞）→ 讀 docblock 確認語意真的對得上（**方法名像不等於覆蓋**）。

**第 3 步：判定並記錄**。三種判定，**後兩種必須寫下一句理由**：

| 判定 | 動作 |
|---|---|
| 已覆蓋 | 標 `@covered-by`，不寫新測試 |
| **部分覆蓋** | 標既有 + 寫下「缺哪個分支」 → 只補差額 |
| **未覆蓋** | 寫下「已查過哪些檔／為何確定沒有」 → 補測試 |

**第 4 步：補測試，先紅後綠**，貼出紅燈輸出。

**第 5 步：`@group` 紀律**。每個新 `test_` 方法**各自**帶上
`smoke` / `happy` / `error` / `edge` / `security` 其中之一（Pitfall #37：方法級 `@group` **取代**類別級）。

**第 6 步：交付前自我驗證**（逐檔宣告數 == 執行數；全域等式；`check-traceability.php` 對該組 feature 全綠）。

#### 每組交付物：`specs/plan/2026-08-07-coverage-B7x.md`

| Rule（檔名 + 標題） | 契約層 | 判定 | 依據 / 理由 | 動作 |
|---|---|---|---|---|
| `validate-shop-slug` / 後置（可用） | 後端 | 已覆蓋 | `ValidateSlugUseCaseTest::test_returns_available_when_no_conflicts` | 標註 |
| `validate-shop-slug` / 前端（即時） | 前端動線 | 未覆蓋 | 查過 `ValidateSlugUseCaseTest` / `ProfitShopValidateSlugEndpointTest`，皆無 debounce 相關斷言；SlugInput.tsx 無測試框架 | `NOT-COVERED` + 交 B9 |

**外加必填**：本組新增測試數、新增檔案清單、`git diff --stat -- inc/`（**預期為空**——B7 是補測試，
不是改產品；若非空必須逐項說明為什麼動到產品碼）。

#### 🔴 停止條件（觸發即回報 team lead，不要自行硬幹）

| 條件 | 為什麼要停 |
|---|---|
| 單組新增測試 **> 30 條** | 極可能把「已覆蓋」誤判成「未覆蓋」（R-9）。902 對 255 平均 3.5，一組 ~50 Rule 不該生出 30 條新測試 |
| 某條 Rule 需要**改產品**才測得起來 | 那不是覆蓋缺口，是設計缺陷或真 bug ⇒ 屬新發現，要走 triage 而不是順手改 |
| 發現既有測試的**斷言與 Rule 相衝突** | 可能是規格寫錯，也可能是產品行為錯。**兩者處置完全相反**，必須先判定 |
| 需要動 `inc/assets/js/profit-shop-front.js` | 禁止（R-11 / 禁止事項 8） |

**共用綠燈驗收**：

```bash
# ① 逐檔宣告數 == 執行數
for F in <該組動到的測試檔>; do
  CLS=$(basename "$F" .php)
  DECL=$(grep -c 'public function test_' "$F")
  RUN=$($B --testsuite <unit|integration> --filter "$CLS" --testdox | grep -cE '^ (✔|✘)')
  echo "$CLS 宣告=$DECL 執行=$RUN $([ "$DECL" = "$RUN" ] && echo OK || echo MISMATCH)"
done
# ② 全域等式維持：宣告總數 == unit + integration 執行數總和
# ③ php bin/check-traceability.php <該組 feature 檔> 全綠
# ④ 全套 failure 數不得增加（且 HPOS 兩輪都要跑）
```

### B8｜vitest：變體比對（前端最高價值，**零產品改動**）

| 項 | 內容 |
|---|---|
| **目標** | 讓 `findBestVariation` 的「具體度計分」演算法第一次擁有自動化保護 |
| **動哪些檔** | 新增 `vitest.config.ts`、`tests/js/profit-shop-front.variation.test.ts`；`package.json` 加 `test:js` + 2 個 devDependency。**`inc/assets/js/profit-shop-front.js` 一行不動** |
| **派誰** | `zenbu-powers:test-creator`（前端），必要時 `zenbu-powers:react-master` 協助 vitest 設定 |
| **前置** | ⚠️ 新增 devDependency 需 team lead 最終點頭 |
| **紅燈定義** | 先寫一條**會失敗**的測試證明 harness 真的在驅動那份 JS（例如故意斷言錯誤的 variation_id），看到紅燈後再改對 |
| **綠燈驗收** | `pnpm test:js` 全綠；`git diff -- inc/` **必須為空**；`pnpm build` 不受影響 |
| **規模** | 中 |

實作方式與必含回歸鎖見決策 7。**Pitfall #31 的 catch-all vs 具體覆寫反例不可省**。

### B9｜Playwright：前端動線

| 項 | 內容 |
|---|---|
| **目標** | 補 `select-shop-products` / `enrich-shop-item-display` / `view-order-profit-panel` 的前端 Rule |
| **動哪些檔** | `tests/e2e/06-profit-shop/*.spec.ts`（新增） |
| **派誰** | `zenbu-powers:test-creator` |
| **前置** | **B2 必須先完成**（partner portal 相關動線在 401 未修前會以錯誤理由失敗） |
| **綠燈驗收** | 新增 spec 全綠；既有 24 個 spec 零回歸 |
| **規模** | 中 |

### B10｜文件同步

| 項 | 內容 |
|---|---|
| **派誰** | `zenbu-powers:doc-updater` |
| **規模** | 小–中 |

必須更新：

1. **TD-36 更正**：`PartnerReportsScopeTest` **不是** order-dependent flaky，是 token 同秒撤銷（決策 2）
2. **TD-88 更新**：HPOS 自動化這一半**已實測通過**；記錄兩輪跑法；記錄「虛構 CI matrix」的教訓
3. **TD-2 關閉**（B3 完成後）
4. **新增技術債**：已被 B1 破壞的 settings 資料**不可回復**，需告知站長自行檢查排程設定
5. 🔴 **新增 Common Pitfall（兩條）**：
   - 「**`wp_json_encode` 的輸出寫進 `*_metadata()` 前必須 `wp_slash()`**——escape 不是保護，escape 正是受害者」
     （與 Pitfall #25 同機制，但既有 Pitfall 只寫了密碼那一處）
   - 「**簽發與撤銷共用秒精度時間戳 ⇒ 同秒簽發的憑證一出生就是死的**，且它會製造假綠燈」
6. **`wordpress.rule.md` §11** 補：HPOS 兩輪跑法；「skip 訊息不得指向不存在的 CI 設定」
7. **`profit-shop.rule.md` §6** M-1 章節：改寫為快照法，說明為何時間比較是錯的代理指標

---

## 5. 風險登記表 —— 哪裡最可能出現「測試綠了但功能其實壞了」

| # | 風險 | 為何危險 | 對策（強制） |
|---|---|---|---|
| **R-1** 🔴 | **註解 / 文件宣稱與實際行為相反** | 本專案已四次：TD-20 / TD-54 / TD-67 / B1 的 `:292`。`phpcs` / PHPUnit / `tsc` **一個都攔不到**。B1 那段註解的推理甚至是「反過來的」（把 escape 當保護，其實 escape 是受害者） | 凡在 docblock 或交付紀錄寫下**行為斷言或量化宣稱**，一律附**當次實測**輸出，**不得引用文件、不得靠推理**。修程式碼時**必須**一併檢查旁邊的註解 |
| **R-2** 🔴 | **既有假綠燈**：`PartnerTokenSecurityTest::test_expired_token_returns_401_on_me`、`test_revoked_token_cannot_be_reused`、`PartnerVsAdminAuthIsolationTest`、`PartnerChangePasswordEndpointTest` 中期望 401 的那幾條 | 這些測試斷言「應該 401」，而 token 因同秒撤銷**本來就已經死了** ⇒ 即使過期檢查、logout 撤銷、雙軌隔離**全部失效，它們照樣會綠** | B2 修復後**逐條重新檢視**：轉紅者代表它終於在測東西；仍綠者要能說出「這次綠是因為哪個機制真的生效」 |
| **R-3** 🔴 | **時間競態測試「跑一次綠了就當修好」** | B2 的紅燈本身就是機率性的（跑得快就紅、跨秒就綠） | 驗收**連跑 3 次**或改用 `FixedClock` 與時鐘解耦；紅燈必須是**確定性**的 |
| **R-4** 🔴 | **skip / 註解指向不存在的設定**（假安全感） | `:491` 的 `CI matrix with-wc-hpos` 根本不存在，讓 TD-88 長期沒被發現；`legacy_post_orders` / `hpos_enabled` **兩條互斥，永遠只有一條會跑** | B5 修掉該訊息；日後任何 `markTestSkipped` 的理由**必須是可驗證的事實**（外掛沒裝 / option 為何值），不得指向流程或設定的承諾 |
| **R-5** | **`@group` 靜默跳過**（Pitfall #37） | 方法級 `@group` 取代類別級，標錯的方法不報錯、不列 skipped，只是消失 | 每 Batch 驗收含逐檔「宣告數 == 執行數」；全域等式 902 → 新值必須等於新 grep 數 |
| **R-6** | **B2 後浮現的新紅燈被誤判為回歸而回退** | 那些是**本來就存在、只是被 401 遮住**的真實缺陷（422 / 429 / Set-Cookie / Cache-Control 分支從未被執行過） | 明文規定：一律當新發現 triage，**禁止**回退 B2 |
| **R-7** | **B1 只斷言「JSON 解得開」** | 反斜線那條的 `decode_ok` 是 `true`，值卻已被靜默竄改（`\b` → backspace） | 三條回歸測試都必須斷言**值逐字相同**，不是只斷言解析成功 |
| **R-8** | **盤點時整檔判定「前端不可測」** | `view-order-profit-panel` 有 2 條純後端 Rule（含一條**安全**：端點權限），整檔跳過會漏掉它們 | B7 必須 Rule-by-Rule 問「契約真相住在哪一層」，並在交付紀錄列出切分結果 |
| **R-9** | **把「找不到」當成「沒覆蓋」而重複造測試** | 測試虛胖，且新舊兩份可能對同一行為做出不同斷言 | B7 流程第 3 步：判為未覆蓋者**必須寫下判定理由**；新增數 > 150 時退回重查 |
| **R-10** | **補測試時直接寫成綠的** | 無法證明它真的在測東西（B8 尤其危險——harness 若沒真的載入那份 JS，測試會「什麼都沒測到卻全綠」） | 每條新測試都要貼紅燈輸出；B8 必須先寫一條故意失敗的測試證明 harness 真的在驅動產品程式碼 |
| **R-11** | **B8 為了可測性而重構 `profit-shop-front.js`** | 那是零測試覆蓋的收錢路徑，先改再測順序做反；且 Pitfall #35 的純重構驗收標準（既有測試零修改全綠）在無測試時**不適用** | 決策 7 已裁定走 jsdom 驅動 DOM，**`git diff -- inc/` 必須為空** |
| **R-12** | **red-green 中性化探針殘留**（Pitfall #36） | 探針殘留比原缺陷更難發現：SQL 表面正常、註解正常、`phpcs` 全綠 | 平行批次**嚴格分檔**；用過探針的批次交付紀錄須貼出 `grep -rn "OR 1=1\|OR TRUE\|1 = 1\|if ( false )\|暫時版本" inc/` 的**零命中**結果 |
| **R-13** 🔴 | **測試為了「跑得穩」而繞開某個條件** | B2 實測抓到的活例：`PartnerTermRepositoryGetPasswordChangedAtTest::..._updates_after_subsequent_save_with_new_password` 的註解明寫「把 termmeta 倒退 2 秒…避免 time() 同秒情況」——它**知道**同秒有問題，卻選擇繞開而不是驗證，缺陷因此潛伏至今 | **通則：測試裡任何「為了穩定而迴避某條件」的手法，都是在標示一個未被驗證的區域**，應登記為待辦而不是當成解法。B7 盤點時看到這類註解要當成**缺口線索**追下去 |
| **R-14** 🔴 | **HPOS 輪跑完忘記還原 option** | 環境卡在 `yes` 時，下一個人跑「輪 1」會讓 `legacy_post_orders` 被 skip，而**兩輪都綠依然成立** ⇒ 假綠燈 | 還原指令用 `trap` / `\|\| true` 保證即使測試失敗也執行；驗收看的是**skip 清單互補**而非「兩輪都綠」（見 B5） |
| **R-15** | **B7 盤點時「方法名看起來像」就當作已覆蓋** | 方法名相近但斷言的是別的分支，會產出看起來很滿、實際對不上的假覆蓋率——比沒有矩陣更糟 | 第 2 步強制**讀 docblock 確認語意**；判定為「部分覆蓋」時必須寫出「缺哪個分支」 |

---

## 6. 明令禁止事項（違反即退回重做）

1. ❌ **為了讓測試轉綠而放寬斷言**。斷言確實寫錯時，必須在 docblock 寫明「原期望為何錯」，且只允許**加強或改正**。
2. ❌ **刪測試**。任何「這條測不出東西」的判斷，都要先證明該行為已被別處覆蓋（附 `@covered-by` 指向）。
3. ❌ **改產品去遷就過時測試**。B3 兩條是反例教材：把 `show_in_rest` 改成 `true` 直接開出權限提升漏洞。
4. ❌ **B2 只把 `<=` 改回 `<` 而不換掉判準**。那等於重開 M-1 race window，拿安全換綠燈。快照法才是拍板的解。
5. ❌ **B1 只改程式碼不改註解**。留著講錯的註解，下一個人會照它把 `wp_slash()` 拿掉。
6. ❌ **修改 `phpunit.xml.dist` 的 `<include>` group 白名單**來讓測試「被跑到」。正解是替每個方法標對 group。
7. ❌ **用 `markTestSkipped` 迴避 blocked 的驗證**而不登記技術債；skip 理由不得指向不存在的設定（R-4）。
8. ❌ **B8 動 `inc/assets/js/profit-shop-front.js`**（R-11）。
9. ❌ **規劃「想辦法讓 WCS 在 wp-env 跑起來」**——那是付費外掛，結構性做不到。
10. ❌ **在同一個檔案上平行做 red-green**（Pitfall #36）。
11. ❌ **沿用文件記載的測試數字當驗收依據**（Pitfall #39）。一律現查 `grep -c "public function test_"`。

---

## 7. 驗證指令速查

```bash
cd "C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-shop"

# 基底（Windows host 直接跑 phpunit 會 Fatal，一定要走容器；
#       用 ./tests/e2e/node_modules/.bin/wp-env，不要用 npx wp-env）
B="./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop -- vendor/bin/phpunit -c phpunit.xml.dist --colors=never"
W="./tests/e2e/node_modules/.bin/wp-env run tests-cli --"

$B --testsuite unit                        # 目前 503 / 1 failure
$B --testsuite integration                 # 目前 399 / 15 failures / 4 skipped
$B --testsuite integration --filter XxxTest --testdox

# HPOS 兩輪（B5 起為驗收必要條件）
$W wp option update woocommerce_custom_orders_table_enabled no  && $B --testsuite integration
$W wp option update woocommerce_custom_orders_table_enabled yes && $B --testsuite integration
$W wp option update woocommerce_custom_orders_table_enabled no          # 還原，不可省

# @group 靜默跳過偵測（每 Batch 必做）
grep -rho 'public function test_' tests/ --include=*.php | wc -l        # 宣告數，目前 902

# 探針殘留掃描（用過探針的 Batch 必貼）
grep -rn "OR 1=1\|OR TRUE\|1 = 1\|if ( false )\|暫時版本" inc/           # 期望零命中

# traceability（B6 起）
php bin/check-traceability.php

# 前端（B8 起）
pnpm test:js
npx playwright test tests/e2e/06-profit-shop --config=tests/e2e/playwright.config.ts

# 靜態檢查（唯讀）
composer lint
vendor/bin/phpstan analyse inc --memory-limit=1G
```

---

## 8. 完成定義（Definition of Done）

- [x] `--testsuite unit` **0 failures**（503 / 1164 assertions，2026-08-07 實測）
- [x] `--testsuite integration` **0 failures**（403 / 1964 assertions，連跑 3 次逐字相同）
- [ ] **HPOS 兩輪都跑**，且 `test_summary_works_under_hpos_enabled` / `test_summary_works_under_legacy_post_orders`
      的執行狀態**互補**（不是「兩輪都綠」就算數，見 B5）
- [ ] 跑完 HPOS 輪後 option **已還原為 `no`**（R-14）
- [ ] `skipped` 僅剩 WCS 那 3 條，且 skip 訊息是**可驗證的事實**（不得指向不存在的 CI 設定）
- [ ] 宣告數 == 執行數（零 `@group` 靜默跳過）
- [ ] `pnpm test:js` 全綠（含 Pitfall #31 的 catch-all vs 具體覆寫反例）
- [ ] `tests/e2e` 全綠（既有 24 spec 零回歸）
- [ ] `php bin/check-traceability.php` 全綠：255 條 Rule 每條都有 `@covered-by`（或 `MANUAL` / `NOT-COVERED` + 理由），且指到的方法**真實存在**
- [ ] `git diff -- inc/` 的每一處產品改動都有對應的**先紅後綠**證據；`inc/assets/js/profit-shop-front.js` 零改動
- [ ] R-2 假綠燈清單逐條複核完畢，結論寫進交付紀錄
- [ ] B2 經 `@zenbu-powers:security-reviewer` opt-in 複審通過
- [ ] 探針殘留掃描零命中
- [ ] `composer lint` 全綠
- [ ] 文件同步完成（TD-36 更正、TD-88 更新、TD-2 關閉、兩條新 Pitfall 寫入）
