# 實作計劃：購物車顯示賣場覆寫後的原價 / 特價（regular / sale price override in cart）

> 日期：2026-08-03
> 上游：team lead 已完成 systematic-debugging Phase 1-3 根因調查（本計劃不重複調查，直接進規劃）
> 相關規範：`.claude/rules/profit-shop.rule.md` §7、`.claude/rules/wordpress.rule.md`、`.claude/CLAUDE.md` Pitfall #22
> 對照實作：`SingleProductPriceHook`（Phase 9，單品頁已修好的另一半）

---

## 概述

分潤賣場覆寫商品價格時，購物車（WooCommerce Cart Block / Store API）顯示的**劃線原價**是商品的
**原始** `regular_price`（1000），而非賣場覆寫後的原價（800）；實收價（600）正確。成因是
`CartPriceOverrideHook::apply_override()` 只呼叫 `set_price()`，從未呼叫 `set_regular_price()` /
`set_sale_price()`，而 `resolve_effective_price()` 的回傳型別 `?string` 在結構上就把「原價覆寫」
這個資訊丟掉了。

Phase 9 的 `SingleProductPriceHook::resolve_prices()` 早就回傳 `{price, regular, sale}` **三元組**
並掛了 `woocommerce_product_get_regular_price` / `_sale_price` filter，所以**單品頁顯示正確**。
本次是把同一件事補到 cart 這條路，並用一個共用的 Domain 解析器讓兩條路徑**在結構上無法再次分歧**。

---

## 需求重述

**修復目標**：cart（Cart Block / Store API、classic cart、mini-cart、checkout）看到的
`regular_price` / `sale_price` 都要是賣場覆寫後的值，且實收價維持現行正確行為。

**不可退讓的約束**：既有顧客購物車（含**永不過期**的 logged-in 持久化購物車）的分潤價
**絕對不可以**因為本次改動而靜默退回商品原價。

---

## 根因（team lead 已驗證，本計劃逐項複驗通過）

| # | 事實 | 證據（本計劃複驗） |
|---|------|--------------------|
| 1 | `apply_override()` 驗章通過後只 `set_price()` | `CartPriceOverrideHook.php:189` — 全檔無 `set_regular_price` / `set_sale_price` |
| 2 | `resolve_effective_price(): ?string` 結構上丟失原價覆寫 | `CartPriceOverrideHook.php:229-250`，fallback chain 只回一個值 |
| 3 | cart_item meta 只存一個價（`_profit_price_override`） | `CartPriceOverrideHook.php:47` |
| 4 | Store API 讀 `$product->get_regular_price()`，我方未覆寫 | `woocommerce/src/StoreApi/Schemas/V1/ProductSchema.php:844-853`，由 `CartItemSchema.php:72` 呼叫 |
| 5 | Store API 序列化前一定會跑 `calculate_totals()`（我方 priority 999 hook 必定已執行） | `src/StoreApi/Utilities/CartController.php:69` `did_action('woocommerce_after_calculate_totals') ? get_cart_instance() : calculate_totals()` |
| 6 | cart item 的 `$product` 是 `WC_Product_Variation`（不是 `WC_Product_Variable`），走 `else` 分支讀 `get_regular_price()` / `get_sale_price()` | `ProductSchema.php:842-848`（`is_type(VARIABLE)` 對 variation 為 false） |
| 7 | 正確對照組已存在 | `SingleProductPriceHook.php:301` `resolve_prices(): array{price, regular, sale}` |

### 補充：其他購物車 UI **不受此 bug 影響**（本計劃額外查證，說明為何只有 Cart Block 出包）

| UI | 取價方式 | 是否受影響 |
|----|---------|-----------|
| Cart Block / Checkout Block / Mini-Cart Block | Store API `prices.regular_price` ← `$product->get_regular_price()` | ✅ **受影響（本 bug）** |
| Classic cart（`templates/cart/cart.php`） | `wc_cart_totals_product_price()` → `WC()->cart->get_product_price()` → 只讀 `get_price()` | ❌ 不顯示劃線價，看不出差異 |
| Legacy mini-cart widget | 同上，只讀 `get_price()` | ❌ 同上 |
| 訂單 / 訂單 email | line item 存 `subtotal` / `total`（來自 cart 的 `line_subtotal` / `line_total`，皆由 `get_price()` 計算） | ❌ **完全不碰 regular_price，無需改動** |

> ⚠️ 「訂單 email」是本次需求列出的目標之一，查證結論是**它本來就是對的、不需要改**。
> 訂單快照裡根本沒有 regular_price 這個概念。這點寫進計劃是為了讓後續實作者不要去
> 「順手補一個其實不存在的欄位」。

---

## 已知風險（來自研究）

### R-1 【最高】改簽章 payload 會讓**所有既有購物車**驗章失敗 → 分潤價靜默消失

`CartPriceOverrideHook::apply_override()` 驗章失敗的行為是 `strip_profit_meta()` + `reset_to_original_price()`
（`:206-207`），**沒有任何使用者可見的錯誤訊息**。若把 regular/sale 納入簽章 payload 卻不做
版本相容，既有顧客回到購物車時分潤價會直接變回商品原價 —— 這正是 CLAUDE.md 反覆警告的
「商家損失」失敗模式。

**緩解**：v2 payload + **v1 雙軌驗證**（詳見「簽章版本遷移設計」一節）。`CartPriceSignatureService`
的 `PAYLOAD_VERSION = 'v1'` 常數與 `'v1.'` 前綴 docblock 明講是為此而設計（`:24`、`:29-31`），
本次是它的**第一次實際使用**。

### R-2 【最高】WC session / 持久化購物車的存活期比想像長很多，「過一陣子再拔掉 v1」不成立

| 情境 | 存活期 | 出處 |
|------|--------|------|
| 訪客 session | **2 天** | `class-wc-session-handler.php:401` `2 * DAY_IN_SECONDS` |
| 登入使用者 session | **1 週** | 同行 `WEEK_IN_SECONDS` |
| 登入使用者**持久化購物車** | **永不過期** | `class-wc-cart-session.php:442-461` 寫 usermeta `_woocommerce_persistent_cart_{blog_id}`，只有 `persistent_cart_destroy()`（結帳完成 / 清空購物車）才刪 |

**緩解**：v1 驗證分支**不設下架期限**（成本只有幾行 + 一條測試）。詳見「何時可以拔掉 v1」。

### R-3 【高】`has_all_profit_meta()` 若把新 meta 判成必填 → 既有購物車全數被當成 tampering

`has_all_profit_meta()`（`:292-306`）目前要求 4 筆 meta 全在，缺任一筆即走 `else` 分支 →
記 log → strip + reset。既有購物車沒有新的 2 筆 meta，判成必填 = R-1 的另一條觸發路徑。

**緩解**：新 meta 改為**依簽章版本條件必填**（v2 才必填，v1 不查）。

### R-4 【高】`wc_format_decimal( $price, '' )` 對空字串會回 `'0.00'`，不是 `''`

`wc-formatting-functions.php:288-317` 逐行追：`$dp = ''` → `false !== ''` 為 true → 進 `number_format()`
分支 → `intval( '' === $dp ? wc_get_price_decimals() : $dp )` = 2 → `number_format( floatval(''), 2 )` = **`'0.00'`**。

若把「未覆寫原價」的 `null` 直接丟進 `normalize_price_for_signature()`，會得到 `'0.00'`，
購物車就會顯示 **「原價 NT$0」的劃線價**，且不會有任何錯誤。

**緩解**：新增 `normalize_nullable_price( ?string ): string`，`null` 一律回 `''`，**絕不進 `wc_format_decimal`**；
並補一條專門的回歸測試。

### R-5 【中】`normalize_price_for_signature()` 的 docblock 與實際行為不符（既有缺陷，非本次引入）

同一段追蹤（R-4）顯示 `wc_format_decimal( '888', '' )` 實際回 **`'888.00'`**，因為第三參數
`$trim_zeros` 預設 `false`、沒有被傳 `true`。但 `CartPriceOverrideHook.php:335-341` 的 docblock 宣稱
`'888.00' → '888'`、`'888.50' → '888.5'`。

**這極可能就是 `specs/tech-debt.md` TD-1 記載的「cart hook 6 個 pre-existing 失敗」的成因**——
`CartPriceOverrideHookTest` 內斷言 `'888'` / `'666'` 字面值的方法**剛好 6 個**：

| # | 測試方法 | 斷言 | 預測 |
|---|---------|------|------|
| 1 | `test_add_to_cart_with_profit_shop_id_writes_override_meta_to_cart_item` | `_profit_price_override === '888'` | ❌ 實際 `'888.00'` |
| 2 | `test_before_calculate_totals_sets_product_price_to_override_when_signature_valid` | `get_price() === '888'` | ❌ |
| 3 | `test_cart_item_meta_survives_session_serialization` | `get_price() === '888'` | ❌ |
| 4 | `test_multiple_cart_items_from_different_shops` | `'888'` / `'666'` | ❌ |
| 5 | `test_same_product_from_different_shops_treats_as_different_cart_items` | `'888'` / `'666'` | ❌ |
| 6 | `test_override_remains_after_other_plugins_run_before_calculate_totals` | `'888'` | ❌ |

其餘 8 個斷言 `'1200'` / `'500'` 的方法會通過（`reset_to_original_price()` 走
`set_price()` → `wc_format_decimal($price)`（`$dp = false`）→ 字串原樣不變）。

> ⚠️ **這是逐行推導的高信心假設，不是實測結果**。第一階段（步驟 0）必須先實跑 baseline 確認，
> 不要當成已證實的事實寫進任何文件。

**緩解與決策**：
- **不要**去修 `normalize_price_for_signature()`。修了會讓所有 v1 簽章的 payload 價格字串
  從 `'888.00'` 變成 `'888'` → 既有購物車驗章全掛（= R-1）；而且會被迫在 v1 / v2 之間維護
  **兩套** normalize 形式，永久增加複雜度。
- 產品端零影響：`_profit_price_override` 不對外顯示，`set_price('888.00')` 之後所有 WC 計算與
  `wc_price()` 顯示都走數值比較，`'888.00'` 與 `'888'` 完全等價。
- 修 **docblock**（讓下一個人不再被誤導）與 **測試斷言**（改成實際的 `'888.00'`）。
  這是純文件 / 測試變更，零產品行為變動，順帶讓 TD-1 的 21 個失敗少 6 個。

### R-6 【中】設 regular/sale 會改變 `is_on_sale()`，進而影響「排除特價商品」的優惠券

`abstract-wc-product.php:1687-1688`：`is_on_sale()` = `'' !== get_sale_price() && get_regular_price() > get_sale_price()`。
`class-wc-discounts.php:840` 與 `:881-885`：`$coupon->get_exclude_sale_items() && $item->product->is_on_sale()`
→ 該品項被排除在折扣之外。

改版前 cart item 只有 `set_price()`，`is_on_sale()` 讀的是**商品原始**的 regular/sale；
改版後（regular=800、sale=600）`is_on_sale()` 會變 **true**。所以站台若有「排除特價商品」的
優惠券，分潤賣場的商品**會從可折扣變成不可折扣**。

**評估**：這在語意上是**正確的**（該品項確實在特價中），而且 `SingleProductPriceHook` 在單品頁
早就是這個行為，改完反而是兩邊一致。但它是**站長可感知的行為變更**，必須：
1. 寫進 IT 測試鎖住（讓行為是刻意的、不是意外的）
2. 寫進 `profit-shop.rule.md` 與本次 commit message
3. 交付時明確告知 team lead / 站長

### R-7 【中】未簽章的 regular/sale meta = 優惠券驗證繞過面（＝「不簽章」這個省事方案必須否決）

承 R-6：既然 `is_on_sale()` 會被 regular/sale 影響，而 `is_on_sale()` 會被優惠券驗證消費，
那「regular/sale 只存 meta、不納入簽章」就**不是只有顯示層風險**——攻擊者改自己 cart session 裡的
`_profit_sale_price_override` 就能翻轉 `is_on_sale()`，讓「排除特價商品」的優惠券變成可用（或反之）。
**這是有金額影響的**。

**結論**：regular/sale **必須納入簽章**。這也直接否決了「不改 payload、只加 meta」這條看似最省事的路。

### R-8 【低】跨層級 fallback 可能產生「原價 < 特價」的荒謬顯示（既有限制，兩條路徑一致）

變體覆寫只設 `sale_price`（`regular_price` 為 null）時，`resolve_prices()` 回 `regular => null`
→ 我方不動商品原本的 regular。若該變體原始 regular（100）低於覆寫的 sale（500），畫面會是
「原價 100 / 現價 500」。

**評估**：`PriceOverride` VO 只保證**同一層**內 `sale <= regular`（`PriceOverride.php:64-71`），
跨層級不保證。此限制在 `SingleProductPriceHook` 上早已存在，本次**刻意不加額外守門**——
加了會讓 cart 與單品頁又分歧一次（就是本 bug 的成因）。此情境需要站長刻意把特價設得比商品
真實原價還高才會發生。登記為技術債，不在本次修。

### R-9 【低】`set_regular_price()` / `set_sale_price()` 會把 product 物件標記為 dirty

與既有的 `set_price()` **風險等級相同**（`abstract-wc-product.php:890-892`，`set_price` 同樣走
`set_prop()` 標記 changed）。cart item 的 product 物件是 per-request 實例、生命週期內不會被
`save()`，本次不新增風險類別，僅擴大既有暴露面。**不需額外緩解**，登記備查。

---

## 架構變更

| # | 檔案 | 變更 | 新/改 |
|---|------|------|-------|
| 1 | `inc/classes/Domains/ProfitShop/Domain/Service/OverridePriceResolver.php` | 從 `SingleProductPriceHook::resolve_prices()` 抽出的**純 Domain 服務**，回傳 `{price, regular, sale}` 三元組。兩條路徑（cart / 單品頁）唯一真相 | **新增** |
| 2 | `inc/.../Infrastructure/WooCommerce/SingleProductPriceHook.php` | `resolve_prices()` 改為委派 `OverridePriceResolver`（純重構，行為零變更） | 改 |
| 3 | `inc/.../Application/Service/CartPriceSignatureService.php` | `PAYLOAD_VERSION` 升 `v2`（payload 加入 regular / sale）+ `sign()` / `verify()` 加兩個 optional 尾參 + 新增 `payload_version()` + 保留 v1 驗證分支 | 改 |
| 4 | `inc/.../Infrastructure/WooCommerce/CartPriceOverrideHook.php` | 新增 2 個 meta 常數 + meta key 清單收斂為常數陣列 + `resolve_effective_price()` → 委派 resolver 取三元組 + `apply_override()` 套 regular/sale + `reset_to_original_price()` 還原 regular/sale + `has_all_profit_meta()` 條件式 + `normalize_nullable_price()` + 修正 normalize docblock | 改 |
| 5 | `tests/Unit/Domain/Service/OverridePriceResolverTest.php` | 三元組 fallback 語意（含跨層級不逐欄混用） | **新增** |
| 6 | `tests/Unit/Application/Service/CartPriceSignatureServiceTest.php` | +v2 / 雙軌 / `payload_version()` 測試；更新 2 條硬編 `'v1'` 的斷言 | 改 |
| 7 | `tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php` | +本 bug 回歸鎖、+v1 legacy 相容鎖、+安全鎖；修正 6 條 `'888'` 斷言（R-5） | 改 |
| 8 | `.claude/rules/profit-shop.rule.md` | §7 cart signature 章節擴充 + 新增「cart 三元組」與「簽章版本遷移」小節 | 改 |
| 9 | `.claude/CLAUDE.md` | Phase 13 章節 + Pitfall 新增一條 | 改 |
| 10 | `specs/tech-debt.md` | TD-1 更新（-6）、新增 TD-34（R-8）、TD-35（v1 分支永久保留） | 改 |

**明確不動**：`AddToCartHook`、`CartItemMetaDisplay`、`OverrideItem`、`PriceOverride`、
`VariationOverride`、`PriceCalculator`、`CptProfitShopRepository`、`V2Api`、`ProfitShopHydrator`、
`specs/api/api.yml`（cart meta 不是 wire 契約）、任何前端檔案。

---

## 簽章版本遷移設計（本計劃的核心）

### Payload 格式

```
v1（既有，永久保留驗證能力）：
  'cart-signature:v1|{shop_id}|{partner_term_id}|{price}'

v2（新，sign() 一律產出）：
  'cart-signature:v2|{shop_id}|{partner_term_id}|{price}|{regular}|{sale}'
```

`{regular}` / `{sale}` 為 normalize 後的字串，**未覆寫一律是空字串 `''`**（見 R-4）。

### API 變更（刻意設計成既有呼叫端與既有測試零改動）

```php
public function sign(
    int $shop_id, int $partner_term_id, string $price,
    string $regular_price = '', string $sale_price = ''
): string;

public function verify(
    int $shop_id, int $partner_term_id, string $price, string $signature,
    string $regular_price = '', string $sale_price = ''
): bool;

/** 回 'v1' / 'v2'；前綴不認得回 null */
public function payload_version( string $signature ): ?string;
```

兩個新參數放在**最後**、且有預設值，所以 `CartPriceSignatureServiceTest` 既有 14 個方法中
「`sign(a,b,c)` → `verify(a,b,c,$sig)`」的 round-trip 全部**維持綠燈**（兩端都用同一組預設空字串）。
只有 2 條硬編 `'v1'` 字面值的測試需要更新（`:135`、`:177`）。

### `verify()` 的雙軌分派

```
verify( ... , $signature, $regular, $sale )
  │
  ├─ str_starts_with($signature,'v2.') ─▶ 用 v2 payload（含 regular/sale）比對
  ├─ str_starts_with($signature,'v1.') ─▶ 用 v1 payload（只有 price）比對，忽略 regular/sale 參數
  └─ 其他 ──────────────────────────────▶ false（含裸 hash、未知前綴）
```

⚠️ **兩個版本的 hash 絕不可互通**：v1 簽章硬改成 `v2.` 前綴必須失敗（payload 的 domain prefix
本身含版本字串 `cart-signature:v1` vs `cart-signature:v2`，天然不同 → hash 不同）。需要一條測試鎖死。

### 🔴 `CartPriceOverrideHook` 端的關鍵安全規則

`verify()` 回 `bool`，**無法**告訴 caller 是哪個版本驗過的。若 hook 用「meta 在不在」判斷要不要套
regular/sale，會出現這個漏洞：

> 攻擊者保留 v1 簽章（合法、驗得過），**額外注入**一筆偽造的 `_profit_regular_price_override`。
> hook 看到 meta 存在 → 以為是 v2 → 用 v2 payload 驗 → v2 分支失敗 → 落到 v1 分支 → 只驗 price → **通過** →
> hook 套用了一個**從未被簽章保護**的假原價。

**因此 hook 必須先用 `payload_version($signature)` 決定分支，並在 v1 分支中「無條件忽略」
任何 regular/sale meta**，而不是靠 meta 是否存在來判版本。這條規則要寫在程式碼註解裡。

### 過渡期行為矩陣（必查表）

| # | cart item 情境 | 簽章 | regular/sale meta | 驗證分支 | price | regular/sale | 結果 |
|---|---------------|------|-------------------|----------|-------|--------------|------|
| 1 | 改版前加入、之後沒再加購 | v1 | 無 | v1 | ✅ 套用 | ❌ 不動 | **相容**：行為與改版前完全相同（劃線價仍是商品原價），**價格不退回原價** |
| 2 | 改版前加入 + 攻擊者注入假 regular meta | v1 | 有（偽造） | v1 | ✅ 套用 | ❌ **強制忽略** | **安全**：假原價不生效 |
| 3 | 改版後新加購 | v2 | 有 | v2 | ✅ | ✅ 套用 | **修好**：劃線價 = 800 |
| 4 | v2 但 regular/sale meta 被刪 | v2 | 缺 | `has_all` 條件式判失敗 | ❌ | ❌ | fallback 原價 + `error_log` |
| 5 | v2 且 regular/sale meta 被竄改 | v2 | 有（偽造） | v2 驗章失敗 | ❌ | ❌ | fallback 原價 + `error_log` |
| 6 | v1 簽章硬改成 `v2.` 前綴 | 偽 v2 | 任意 | v2 payload 對不上 | ❌ | ❌ | fallback 原價 + `error_log` |
| 7 | 同一顧客車內 v1 + v2 品項並存 | 混合 | 混合 | **逐品項獨立判版** | ✅ 各自 | 各自 | 兩者都正確，互不影響 |

> 情境 1 是**本次改動最重要的驗收點**：既有購物車必須「維持現況」而不是「壞掉」。
> 情境 7 必然發生（顧客改版前加了 A，改版後又加了 B），需要獨立測試。

### 何時可以拔掉 v1？——建議：**不設期限，永久保留**

理由（承 R-2）：登入使用者的持久化購物車寫在 usermeta，**沒有任何過期機制**，只有結帳完成或
清空購物車才會被刪。拔掉 v1 分支 = 那些購物車的簽章驗證失敗 = `strip_profit_meta()` +
`reset_to_original_price()` = **分潤價靜默消失、顧客與站長都不會收到任何訊息**。

保留成本極低（一個 `if` 分支 + 一條測試）。**建議寫進 `profit-shop.rule.md` 明令不得移除**，
並登記 `specs/tech-debt.md`（TD-35）說明「這不是忘了清的死碼」。

**若日後真的要拔**，必須先做 v1→v2 就地升級：在 `restore_from_session()`（每個 request 每個
品項只跑一次，比 `before_calculate_totals` 便宜很多）偵測到 v1 簽章且驗章通過時，回查賣場、
重新解析三元組、改寫成 v2 meta + v2 簽章。等升級路徑上線並跑滿一段時間後才移除 v1。
**本次不做**（會把 repository 查詢加回熱路徑，且非當前需求）。

---

## 資料流分析

### 流程 A：加入購物車 → 寫入 meta（`add_cart_item_data`，priority 10）

```
$cart_item_data['profit_shop_id']
      │
      ▼
  find(shop_id) ──▶ status==='publish' ──▶ items[product_id] ──▶ OverridePriceResolver
      │                    │                      │                      │
      ▼                    ▼                      ▼                      ▼
 [null? → return]   [draft? → return]      [不在賣場? → return]   [無任何覆寫? → return null]
      │                                                                  │
      │                                                                  ▼
      │                                                    { price, regular:?string, sale:?string }
      │                                                                  │
      ▼                                                                  ▼
                              normalize_price_for_signature(price)          ← 必為非 null
                              normalize_nullable_price(regular)             ← null ⇒ ''（🔴 R-4）
                              normalize_nullable_price(sale)                ← null ⇒ ''（🔴 R-4）
                                                   │
                                                   ▼
                        寫入 6 筆 meta + sign(shop,partner,price,regular,sale) → 'v2.{hash}'
                                                   │
                                                   ▼
                                         cart_item_data（回傳給 WC）
```

Shadow paths：`shop=null` / `status!=publish` / `product 不在賣場` / `resolver 回 null`
→ **一律原樣回傳 `$cart_item_data`，不寫任何 meta，商品走原價**（既有行為，不變）。

### 流程 B：計算總額前套用（`before_calculate_totals`，priority 999）

```
foreach cart_contents as $key => $item
      │
      ▼
 isset($item['_profit_shop_id'])? ──no──▶ continue（非我方管理）
      │ yes
      ▼
 $version = payload_version($item['_profit_signature'] ?? '')
      │
      ├── null ─────────────────────────────────▶ ┐
      │                                            │
      ├── 'v1' ─▶ has_all(base 4 筆)?              │
      │             │ no ────────────────────────▶ ┤
      │             │ yes                          │
      │             ▼                              │
      │        verify(v1: price only)              │
      │             │ false ────────────────────▶ ┤
      │             │ true                         │
      │             ▼                              │
      │        set_price(price)                    │
      │        🔴 不碰 regular/sale（忽略任何注入） │
      │             │                              │
      │             ▼ continue                     │
      │                                            │
      └── 'v2' ─▶ has_all(base 4 + regular + sale)?│
                    │ no ───────────────────────▶ ┤
                    │ yes                          │
                    ▼                              │
               verify(v2: price+regular+sale)      │
                    │ false ───────────────────▶ ┤
                    │ true                         │
                    ▼                              │
               regular !== '' ? set_regular_price(regular) : （不動，沿用商品原價）
               set_sale_price(sale)   ← '' 代表「無特價」，要寫進去，不是跳過
               set_price(price)       ← 最後設，語意最明確
                    │                              │
                    ▼ continue                     │
                                                   ▼
                                    log_signature_mismatch()
                                    strip_profit_meta()      ← 含新 2 筆
                                    reset_to_original_price() ← 🔴 也要還原 regular/sale
```

> ⚠️ `regular` 與 `sale` 的空字串**語意不同**，這是本次最容易寫錯的一行：
> - `regular === ''` ⇒「不覆寫原價」⇒ **不呼叫** `set_regular_price()`，沿用商品原本的原價
>   （對齊 `SingleProductPriceHook::filter_regular_price()` `:163-169`）
> - `sale === ''` ⇒「沒有特價」⇒ **必須呼叫** `set_sale_price('')` 清掉商品原本的特價
>   （對齊 `SingleProductPriceHook::filter_sale_price()` `:182-188`——不清掉會出現
>   「原價劃掉→特價」但實收卻是覆寫原價的矛盾）

### 流程 C：session round-trip（`get_cart_item_from_session`，priority 10）

```
session $values ──▶ foreach META_KEYS_ALL（6 筆）──▶ isset? ──▶ $cart_item[$k] = $values[$k]
                                                        │
                                                        ▼
                                                 [不存在 → 跳過]
                                                 （v1 舊車自然只補回 4 筆 → 走流程 B 的 v1 分支）
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|--------------|----------|----------|-------------|
| `OverridePriceResolver::resolve()` | `OverrideItem` 完全無覆寫 | 正常分支 | 回 `null`，caller 不介入 | 否（商品走原價，正確） |
| `add_cart_item_data` | shop 不存在 / draft / 商品不在賣場 | 正常分支 | 原樣回傳，不寫 meta | 否（走原價，既有行為） |
| `normalize_nullable_price(null)` | 🔴 誤傳進 `wc_format_decimal` 會變 `'0.00'` | 邏輯錯誤 | **提前 return `''`**，不進 WC 函式 | 否（若漏 → 顯示「原價 NT$0」，**必須靠測試擋**） |
| `apply_override` — `payload_version()` 回 null | 簽章格式不明 / 空字串 / 裸 hash | 竄改或資料損毀 | `log_signature_mismatch` + strip + reset | 否（刻意不回饋，避免 oracle） |
| `apply_override` — v2 但缺 regular/sale meta | meta 被刪 | 竄改 | 同上 | 否 |
| `apply_override` — v2 驗章失敗 | regular/sale/price 任一被改 | 竄改 | 同上 | 否 |
| `apply_override` — v1 驗章失敗 | price 被改 | 竄改 | 同上（既有行為） | 否 |
| `apply_override` — v1 + 注入 regular meta | 竄改嘗試 | 竄改 | **忽略該 meta，price 照常套用**（不視為 tampering，避免把合法舊車判死） | 否 |
| `reset_to_original_price` | `wc_get_product()` 回 false（商品已刪） | 資料缺失 | 直接 `return`（既有行為，product 物件維持現狀） | 否 |
| `set_regular_price` / `set_sale_price` | product 非 `WC_Product` | 型別 | 既有 `instanceof` 守門已涵蓋（`:186-188`） | 否 |
| 優惠券 `exclude_sale_items` 因 `is_on_sale()` 翻轉而排除品項 | 行為變更（非錯誤） | 業務規則 | **刻意接受**，寫測試鎖住 + 文件告知站長 | ✅ **是**（顧客看到折扣不適用） |

> **CRITICAL GAP 檢查**：表中無「處理方式=無 且 使用者可見=靜默」的列。
> 唯一逼近的是 `normalize_nullable_price(null)` —— 它靜默且使用者只會看到一個怪數字（NT$0），
> 所以被列為 🔴 並指定**必須有專屬回歸測試**，而非只靠 code review。

---

## 失敗模式登記表

| # | 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|---|-----------|----------|---------|---------|-------------|----------|
| F-1 | `CartPriceSignatureService::verify` | v1 分支被移除 → 既有購物車全數退回原價 | ✅ 永久保留 v1 | ✅ 新增（IT + Unit） | ❌ 靜默 | 無法自動恢復 → 靠測試 + 文件禁令 |
| F-2 | `has_all_profit_meta` | 新 meta 判成無條件必填 → 既有購物車全被判 tampering | ✅ 條件式（依版本） | ✅ 新增 IT | ❌ 靜默 | 同上 |
| F-3 | `normalize_nullable_price` | `null` 被 `wc_format_decimal` 轉成 `'0.00'` | ✅ 提前 return | ✅ 新增 IT | ✅ 顯示「原價 NT$0」 | 靠測試 |
| F-4 | `apply_override` v1 分支 | 誤用 meta 存在與否判版本 → 套用未簽章的假原價 | ✅ 用 `payload_version()` 判版本 | ✅ 新增安全測試 | ❌ 靜默（顯示假原價） | 靠測試 |
| F-5 | `reset_to_original_price` | 只還原 price、不還原 regular/sale → 同 request 內二次計算殘留竄改值 | ✅ 一併還原 | ✅ 新增 IT | ✅ 劃線價殘留錯誤值 | 靠測試 |
| F-6 | `strip_profit_meta` / `restore_from_session` / `has_all_profit_meta` 三處 key 清單 | 新增 meta 時漏改其中一處 → session 後遺失 / 誤判 tampering | ✅ 收斂為 `const` 陣列，三處共用 | ✅ session round-trip IT | ❌ 靜默 | 結構上根除 |
| F-7 | `OverridePriceResolver` 與 `SingleProductPriceHook` 語意分歧 | 兩份實作各自演進 → cart 與單品頁價格不一致（**本 bug 的成因類型**） | ✅ 抽成單一 Domain 服務、兩邊都委派 | ✅ Unit + 既有 11 條 IT 不改即綠 | ✅ 價格跳動 | 結構上根除 |
| F-8 | `set_sale_price` 沒被呼叫（`sale === ''` 時跳過） | 商品原本的特價殘留 → 「原價劃掉→特價」但實收是覆寫原價 | ✅ `''` 也要寫入 | ✅ 新增 IT | ✅ 顯示矛盾 | 靠測試 |
| F-9 | `is_on_sale()` 翻轉影響 `exclude_sale_items` 優惠券 | 分潤商品從可折扣變不可折扣 | ⚠️ **刻意接受** | ✅ 新增 IT 鎖住 | ✅ 是 | 站長可改優惠券設定 |
| F-10 | 跨層級 regular < sale（R-8） | 顯示「原價 100 / 現價 500」 | ⚠️ 刻意不處理（與單品頁一致） | ❌ 不測 | ✅ 是 | 站長修正賣場設定 |

---

## 實作步驟

### 第〇階段：Baseline 量測（**必做，不可跳過**）

1. **實跑 PHP 測試 baseline**
   - 行動：依 `wordpress.rule.md` §11.3 用 wp-env 容器跑
     ```bash
     ./tests/e2e/node_modules/.bin/wp-env run tests-cli \
       --env-cwd=wp-content/plugins/power-shop \
       -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --colors=never
     ./tests/e2e/node_modules/.bin/wp-env run tests-cli \
       --env-cwd=wp-content/plugins/power-shop \
       -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration --colors=never
     ```
   - 原因：`specs/tech-debt.md` TD-1 / TD-2 記載 21 + 1 個 pre-existing 失敗，**必須先有實測數字**
     才能證明本次零 regression。CLAUDE.md Phase 9 已明訂「baseline 以動工前實測為準，不沿用文件數字」
   - 依賴：無
   - 風險：低

2. **驗證 R-5 假設**（`normalize_price_for_signature` 回 `'888.00'`）
   - 行動：`--filter CartPriceOverrideHookTest --testdox` 逐條看，確認失敗的是否**正好**是 R-5 表列的 6 條、
     且錯誤訊息是 `'888' vs '888.00'`
   - 原因：R-5 是逐行推導的假設。若實測不符，R-5 的處置（改測試而非改產品碼）必須重新評估
   - 依賴：步驟 1
   - 風險：低（純觀察）
   - **產出**：確認 / 推翻 R-5，並把結論回報 team lead（會影響是否要順手清 TD-1 的 6 條）

3. **記錄 `vendor/bin/phpcs inc/` 綠燈數**
   - 行動：`vendor/bin/phpcs inc/`（**不要**用 `composer lint`，未追蹤的 `.claude/tmp/` 會被掃到，見 TD-18）
   - 依賴：無
   - 風險：低

---

### 第一階段：抽出 `OverridePriceResolver`（純重構，行為零變更）

> 目標：讓「cart 與單品頁的取價語意必須逐字一致」這條紀律從**靠人記**變成**結構上做不到分歧**。
> 這一階段的驗收條件是 `SingleProductPriceHookTest` 11 個方法**一個字都不改就全綠**。

4. **建立 Domain 服務**（檔案：`inc/classes/Domains/ProfitShop/Domain/Service/OverridePriceResolver.php`）
   - 行動：新增 `final class OverridePriceResolver`，方法
     ```php
     /**
      * @return array{price: string, regular: string|null, sale: string|null}|null
      */
     public function resolve( OverrideItem $item, ?int $variation_id ): ?array
     ```
     內容 = 現行 `SingleProductPriceHook::resolve_prices()`（`:301-330`）**逐行搬移**，
     `$this->item` 改為參數 `$item`
   - 原因：Domain 層純 PHP、不依賴 WP / WC，可在 `tests/Unit/Domain/` 不啟 WP 直接測（`profit-shop.rule.md` §1）
   - 依賴：無
   - 風險：低
   - ⚠️ **必須在 class docblock 明寫**：「變體只要有任一覆寫欄位就**整組**採用變體層，
     **不與商品層逐欄混用**；改成逐欄 fallback 會讓單品頁與購物車顯示不同價」
     （沿用 `SingleProductPriceHook.php:293-295` 的既有警語，不要弄丟）

5. **`SingleProductPriceHook` 改為委派**（檔案：`inc/.../WooCommerce/SingleProductPriceHook.php`）
   - 行動：`resolve_prices()` 內容改為 `return $this->resolver->resolve( $this->item, $variation_id );`
     （保留 `$this->item instanceof OverrideItem` 的守門）。resolver 用 `private readonly` 建構子注入，
     `Loader.php` 對應補上 `new OverridePriceResolver()`
   - 原因：單一真相
   - 依賴：步驟 4
   - 風險：**低但需嚴格驗收** —— 這是 Phase 9 已人工端到端驗證過的路徑
   - **成功標準**：`SingleProductPriceHookTest`（11 method）**零修改**全綠

6. **新增 `OverridePriceResolverTest`**（檔案：`tests/Unit/Domain/Service/OverridePriceResolverTest.php`）
   - 行動：涵蓋 — 商品層 regular+sale / 只有 regular / 只有 sale / 完全無覆寫回 null /
     變體層整組採用（不與商品層逐欄混用）/ 變體 override 存在但兩欄皆 null 時 fallback 到商品層 /
     `variation_id = null` 時只看商品層
   - 原因：這組語意目前只被 IT 間接覆蓋，抽成 Domain 後應有直接的 Unit 鎖
   - 依賴：步驟 4
   - 風險：低
   - ⚠️ 每個 `test_*` **各自**要標 `@group happy|edge|error|smoke|security` 之一，
     否則 PHPUnit **靜默跳過**（`wordpress.rule.md` §11.5）。驗收方式：
     `--filter OverridePriceResolverTest --testdox` 的筆數 == 檔內 `test_` 方法數

**階段成功標準**：`git diff` 對 `SingleProductPriceHookTest.php` 為空；Unit 測試數 +N、failure 不增加；`phpcs inc/` 綠。

---

### 第二階段：`CartPriceSignatureService` v2 + 雙軌驗證

7. **升級簽章服務**（檔案：`inc/.../Application/Service/CartPriceSignatureService.php`）
   - 行動：
     - `PAYLOAD_VERSION` 由 `'v1'` 改 `'v2'`；新增 `LEGACY_PAYLOAD_VERSION = 'v1'`
     - 抽 `private function build_payload( string $version, int $shop, int $partner, string $price, string $regular, string $sale ): string`
       —— v1 只串到 `$price` 為止，v2 續串 `|{regular}|{sale}`
     - `sign()` 加兩個 optional 尾參，一律用 v2
     - `verify()` 加兩個 optional 尾參，依前綴分派 v2 / v1；未知前綴回 `false`
     - 新增 `payload_version( string $signature ): ?string`
     - 保留既有的長度 64 + `ctype_xdigit` + `hash_equals` 三道檢查（**不可弱化**）
   - 原因：R-1 / R-2 的唯一解
   - 依賴：無
   - 風險：**高**（簽章是防竄改核心）
   - ⚠️ **不可改動**：`PAYLOAD_DOMAIN = 'cart-signature'`（cross-protocol collision 防護）、
     `hash_equals`（timing oracle 防護）。`CartPriceSignatureServiceTest` 有 reflection 測試鎖死這兩點

8. **更新 `CartPriceSignatureServiceTest`**（檔案：`tests/Unit/Application/Service/CartPriceSignatureServiceTest.php`）
   - 行動：
     - 更新 `test_signature_format_is_version_dot_hex`（`:135`）：`'v1.'` → `'v2.'`，`substr($sig,3)` 維持
     - 更新 `test_payload_includes_domain_prefix_to_prevent_cross_protocol_collision`（`:177`）：
       斷言原始碼含 `'v2'`**且仍含** `'v1'`（後者是 legacy 分支存在的證明）
     - 新增：`sign()` 預設輸出 v2 前綴
     - 新增：regular 被竄改 → v2 verify false
     - 新增：sale 被竄改 → v2 verify false
     - 新增：**手動組出的 v1 簽章仍能 verify 通過**（雙軌回歸鎖）
     - 新增：v1 簽章把前綴換成 `v2.` → 必須 false（跨版本不可互通）
     - 新增：`payload_version()` 對 `v1.` / `v2.` / 裸 hash / 空字串 / `v3.` 的回傳
   - 依賴：步驟 7
   - 風險：低
   - 註：其餘 12 個方法因兩參數有預設值而**不需修改**（見「API 變更」一節）

**階段成功標準**：Unit `CartPriceSignatureServiceTest` 全綠；既有 12 方法零修改。

---

### 第三階段：`CartPriceOverrideHook` 寫入新 meta（尚未套用，可獨立驗證）

9. **meta key 清單收斂為常數**（檔案：`inc/.../WooCommerce/CartPriceOverrideHook.php`）
   - 行動：新增
     ```php
     private const META_REGULAR_OVERRIDE = '_profit_regular_price_override';
     private const META_SALE_OVERRIDE    = '_profit_sale_price_override';

     /** v1 / v2 共同必備 */
     private const META_KEYS_BASE = [ self::META_SHOP_ID, self::META_PARTNER_TERM_ID, self::META_PRICE_OVERRIDE, self::META_SIGNATURE ];
     /** v2 才必備 */
     private const META_KEYS_V2   = [ self::META_REGULAR_OVERRIDE, self::META_SALE_OVERRIDE ];
     /** session restore / strip 用（全集） */
     private const META_KEYS_ALL  = [ ...self::META_KEYS_BASE, ...self::META_KEYS_V2 ];
     ```
     並讓 `restore_from_session()` / `strip_profit_meta()` 改用 `META_KEYS_ALL`，
     `has_all_profit_meta()` 改用 `META_KEYS_BASE`（+ v2 時再串 `META_KEYS_V2`）
   - 原因：根除 F-6（三處清單漂移）。**這是本次改動最直接的長期價值之一**
   - 依賴：無
   - 風險：低
   - 註：PHP 8.1 支援 const 陣列展開（`...`）；若 phpcs / phpstan 有意見則改為明列

10. **新增 `normalize_nullable_price()`**（同檔）
    - 行動：
      ```php
      private static function normalize_nullable_price( ?string $price ): string {
          // 🔴 null / '' 絕不可進 wc_format_decimal( $x, '' )——它會回 '0.00'（非 ''），
          //    會讓「未覆寫原價」在購物車顯示成「原價 NT$0」的劃線價，且無任何錯誤訊息。
          if ( null === $price || '' === $price ) { return ''; }
          return self::normalize_price_for_signature( $price );
      }
      ```
    - 原因：R-4 / F-3
    - 依賴：無
    - 風險：中（漏掉就是 F-3，靜默且顯示錯誤金額）

11. **修正 `normalize_price_for_signature()` 的 docblock**（同檔 `:333-348`）
    - 行動：把 `'888.00' → '888'` / `'888.50' → '888.5'` 的範例改成實際行為
      （`'888' → '888.00'`，因 `wc_format_decimal` 第三參數 `$trim_zeros` 未傳 `true`），
      並加註「**不要為了讓它符合舊 docblock 而去改行為**——那會讓所有既有 v1 簽章的 payload
      價格字串改變、驗章全失敗（＝分潤價靜默消失）」
    - 原因：R-5。**只改註解，不改行為**
    - 依賴：步驟 0-2（先確認 R-5 成立）
    - 風險：低

12. **`resolve_effective_price()` 改為委派 resolver**（同檔 `:229-250`）
    - 行動：改名為 `resolve_override_prices()`，回傳
      `array{price:string, regular:?string, sale:?string}|null`，內容委派
      `OverridePriceResolver::resolve()`。建構子注入 resolver，`Loader.php` 同步
    - 原因：F-7（結構上根除語意分歧）
    - 依賴：步驟 4
    - 風險：中 —— **必須驗證 `price` 欄位的計算結果與改動前完全相同**
    - 📌 已逐條比對確認等價：現行 cart 版是「變體 sale → 變體 regular → 商品 sale → 商品 regular」；
      resolver 版是「變體有任一欄 → 整組取變體（price = sale ?? regular）；否則商品層同理」。
      兩者對 `price` 的輸出在所有分支上相同（變體 override 兩欄皆 null 時都會落到商品層）

13. **`add_cart_item_data()` 寫入 6 筆 meta**（同檔 `:86-130`）
    - 行動：
      ```php
      $prices           = $this->resolve_override_prices( $item, $product_id, $variation_id );
      if ( null === $prices ) { return $cart_item_data; }

      $normalized_price   = self::normalize_price_for_signature( $prices['price'] );
      $normalized_regular = self::normalize_nullable_price( $prices['regular'] );
      $normalized_sale    = self::normalize_nullable_price( $prices['sale'] );

      $cart_item_data[ self::META_SHOP_ID ]           = $shop_id;
      $cart_item_data[ self::META_PARTNER_TERM_ID ]   = $shop->partner_term_id;
      $cart_item_data[ self::META_PRICE_OVERRIDE ]    = $normalized_price;
      $cart_item_data[ self::META_REGULAR_OVERRIDE ]  = $normalized_regular; // '' = 不覆寫原價
      $cart_item_data[ self::META_SALE_OVERRIDE ]     = $normalized_sale;    // '' = 無特價
      $cart_item_data[ self::META_SIGNATURE ]         = $this->signatures->sign(
          $shop_id, $shop->partner_term_id, $normalized_price, $normalized_regular, $normalized_sale
      );
      ```
    - 原因：核心資料寫入
    - 依賴：步驟 7、10、12
    - 風險：中
    - ⚠️ **一律寫 `''`、絕不寫 `null`** —— `has_all_profit_meta()` 用 `isset()` 判斷，
      `isset(null)` 為 `false`，寫 null 會讓自己產生的 cart item 被自己判成 tampering

**階段成功標準**：既有 IT 除 R-5 那 6 條外全綠；新加購的 cart item 可見 6 筆 meta 且簽章為 `v2.` 開頭。

---

### 第四階段：`apply_override()` 實際套用 regular / sale（**行為變更點**）

14. **`apply_override()` 依版本分派**（同檔 `:165-209`）
    - 行動：
      ```php
      $signature = isset( $item[ self::META_SIGNATURE ] ) ? (string) $item[ self::META_SIGNATURE ] : '';
      $version   = $this->signatures->payload_version( $signature );

      if ( 'v2' === $version && $this->has_all_profit_meta( $item, true ) ) {
          $regular = (string) $item[ self::META_REGULAR_OVERRIDE ];
          $sale    = (string) $item[ self::META_SALE_OVERRIDE ];
          if ( $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature, $regular, $sale ) ) {
              $this->apply_prices_to_product( $cart, $key, $price, $regular, $sale );
              continue;
          }
      } elseif ( 'v1' === $version && $this->has_all_profit_meta( $item, false ) ) {
          if ( $this->signatures->verify( $shop_id, $partner_term_id, $price, $signature ) ) {
              // 🔴 legacy 相容：v1 沒有簽章保護 regular/sale，
              //    即使 cart_item 上出現這兩筆 meta 也**一律忽略**（可能是攻擊者注入）。
              $this->apply_prices_to_product( $cart, $key, $price, null, null );
              continue;
          }
      }

      $this->log_signature_mismatch( $shop_id, $key );
      $this->strip_profit_meta( $cart, $key );
      $this->reset_to_original_price( $cart, $key );
      ```
    - 原因：本 bug 的實際修復 + F-4 安全規則
    - 依賴：步驟 7、9、13
    - 風險：**高**（影響真實訂單金額與既有購物車）
    - ⚠️ 版本判斷**必須**用 `payload_version($signature)`，**不可**用「regular meta 在不在」——
      理由見「🔴 `CartPriceOverrideHook` 端的關鍵安全規則」。這行註解要寫進程式碼

15. **新增 `apply_prices_to_product()` helper**（同檔）
    - 行動：
      ```php
      private function apply_prices_to_product( \WC_Cart $cart, string $key, string $price, ?string $regular, ?string $sale ): void {
          if ( ! isset( $cart->cart_contents[ $key ]['data'] ) ) { return; }
          $data = $cart->cart_contents[ $key ]['data'];
          if ( ! $data instanceof \WC_Product ) { return; }

          // regular：'' / null 代表「不覆寫原價」→ 保留商品原本的原價
          //          （對齊 SingleProductPriceHook::filter_regular_price()）
          if ( null !== $regular && '' !== $regular ) { $data->set_regular_price( $regular ); }

          // sale：'' 代表「無特價」，**必須寫入**以清掉商品原本的特價
          //       （對齊 SingleProductPriceHook::filter_sale_price()，
          //        不清會出現「原價劃掉→特價」但實收是覆寫原價的矛盾）
          //       null 代表 v1 legacy 路徑，完全不碰
          if ( null !== $sale ) { $data->set_sale_price( $sale ); }

          // price 最後設：語意最明確，且與既有行為完全相同
          $data->set_price( $price );
      }
      ```
    - 原因：F-8；把 `''` 兩種語意的差異集中在一處並註解清楚
    - 依賴：步驟 14
    - 風險：中
    - 📌 `set_regular_price` / `set_sale_price` **不會**自動重算 `price`
      （`abstract-wc-product.php` 的 setter 只 `set_prop()`），順序其實不影響結果，
      但 price 放最後可讀性最好

16. **`has_all_profit_meta()` 改為條件式**（同檔 `:292-306`）
    - 行動：簽章改為 `has_all_profit_meta( array $item, bool $require_v2 ): bool`，
      `$require_v2` 為 true 時額外檢查 `META_KEYS_V2`
    - 原因：R-3 / F-2
    - 依賴：步驟 9
    - 風險：**高**（判錯 = 既有購物車全滅）

17. **`reset_to_original_price()` 一併還原 regular / sale**（同檔 `:265-283`）
    - 行動：
      ```php
      $sale = (string) $fresh->get_sale_price( 'edit' );
      $reg  = (string) $fresh->get_regular_price( 'edit' );
      $data->set_regular_price( $reg );
      $data->set_sale_price( $sale );
      $data->set_price( '' !== $sale ? $sale : $reg );
      ```
    - 原因：F-5 —— 同一 request 內 `calculate_totals()` 會被呼叫多次；
      若第一次驗章通過（已寫入 regular/sale）、第二次因竄改失敗，只還原 price 會殘留錯誤劃線價
    - 依賴：無
    - 風險：中
    - 註：`$fresh` 已由既有程式碼以 `wc_get_product()` 取得（`:275`），無新增查詢

**階段成功標準**：手動在真實站台重現原 bug 的情境 → 劃線價變 800；既有 v1 cart 不退價。

---

### 第五階段：測試

18. **修正 R-5 的 6 條既有斷言**（檔案：`tests/Integration/.../CartPriceOverrideHookTest.php`）
    - 行動：把 `'888'` / `'666'` 改為實測的 normalize 結果（預期 `'888.00'` / `'666.00'`）；
      在檔頭 docblock 註明「這是 `wc_format_decimal($x, '')` 的實際輸出，**不是** normalize 壞掉，
      詳見 `profit-shop.rule.md`」
    - 原因：讓「新壞的」與「本來就壞的」可以區分（TD-1 的核心痛點）
    - 依賴：步驟 0-2 實測確認
    - 風險：低
    - ⚠️ **範圍決策點**：這 6 條屬於 TD-1 的 pre-existing 失敗，嚴格說不在本次 scope。
      建議一併修（我們正在改同一個檔、留著紅燈會讓本次驗收無法判斷），但**請 team lead 拍板**。
      若決定不修，本階段其餘新測試仍可獨立完成

19. **新增 IT：核心回歸鎖**（同檔）
    - `test_cart_item_regular_and_sale_price_reflect_shop_override`
      —— **本 bug 的直接回歸鎖**：商品 regular 1000、賣場覆寫 800/600 → 驗章後
      `get_regular_price()` == 800、`get_sale_price()` == 600、`get_price()` == 600
    - `test_only_sale_overridden_keeps_product_original_regular_price`
      —— 只覆寫 sale → regular 維持商品原值（`''` 語意 A）
    - `test_only_regular_overridden_clears_product_sale_price`
      —— 只覆寫 regular → `get_sale_price()` == `''`（`''` 語意 B，F-8）
    - `test_variation_override_sets_variation_regular_and_sale_price`
      —— variable 商品：cart item 的 `data` 是 `WC_Product_Variation`，
      變體層覆寫要正確落在該變體上（用 `createVariableProduct()` helper）
    - `test_null_regular_override_is_not_normalized_into_zero`
      —— 🔴 F-3 鎖：未覆寫 regular 時 `_profit_regular_price_override` 必須是 `''`，**不可**是 `'0.00'`
    - 依賴：第四階段
    - 風險：低

20. **新增 IT：v1 legacy 相容與安全鎖**（同檔）
    - `test_legacy_v1_cart_item_still_applies_price_and_is_not_treated_as_tampering`
      —— 🔴 **最重要的一條**：手動組出只有 4 筆 meta + 手工 v1 簽章的 cart item →
      price 正確套用、**不寫 error_log**、meta 不被 strip、regular/sale 保持商品原值
    - `test_legacy_v1_signature_ignores_injected_regular_price_meta`
      —— 🔴 F-4 安全鎖：v1 簽章 + 注入 `_profit_regular_price_override = '99999'` →
      `get_regular_price()` 必須維持商品原值
    - `test_v1_signature_with_forged_v2_prefix_falls_back_to_original_price`
      —— 跨版本不可互通
    - `test_v2_cart_item_with_tampered_regular_price_falls_back_to_original`
      —— v2 驗章涵蓋 regular
    - `test_v2_cart_item_with_missing_regular_meta_falls_back_to_original`
      —— `has_all_profit_meta` v2 分支
    - `test_mixed_v1_and_v2_cart_items_are_evaluated_independently`
      —— 過渡期矩陣情境 7
    - 依賴：第四階段
    - 風險：低

21. **新增 IT：連帶行為鎖**（同檔）
    - `test_signature_failure_also_restores_regular_and_sale_price`（F-5）
    - `test_override_makes_cart_item_on_sale_for_exclude_sale_items_coupon`（F-9，
      **鎖住行為變更**：建立 `exclude_sale_items = true` 的優惠券 → 分潤品項被排除）
    - 依賴：第四階段
    - 風險：低
    - ⚠️ 每個 `test_*` 各自標 `@group`（`wordpress.rule.md` §11.5），並用
      `--filter CartPriceOverrideHookTest --testdox` 核對筆數 == `test_` 方法數

22. **真實站台人工端到端驗證**
    - 行動：對真實賣場設定「商品原價 1000 / 賣場覆寫 regular 800、sale 600」，
      在 **Cart Block** 頁面確認劃線價為 800、現價 600；並用
      `POST /?wc-ajax=get_refreshed_fragments` 或 Store API `GET /wc/store/v1/cart`
      直接讀 `prices.regular_price` / `prices.price` 核對
    - 原因：`@woocommerce/blocks` 的前端劃線邏輯是 minified JS，本計劃**未逐行驗證**其判斷條件
      （推定為比較 `regular_price` 與 `price`，與使用者回報一致）。這一步是唯一的直接證據
    - ⚠️ 站台裝有 **WP Rocket**，`/cart/` 的 GET 可能拿到快取版本（`specs/tech-debt.md` TD-32）——
      **必須在真實瀏覽器操作**，不要用 HTTP client 直接讀
    - ⚠️ 另需**負向驗證**：不帶 `profit_shop_id` 加購同一商品 → 劃線價應為 1000（零殘留）
    - 依賴：第四階段
    - 風險：低（唯讀觀察；驗證用的臨時賣場 / 購物車項目事後必須清除）

---

### 第六階段：文件同步

23. **`.claude/rules/profit-shop.rule.md`**
    - 在 §7「Cart price signature normalize 兩端一致」之後新增：
      - 「**cart 也要套 regular / sale，不是只有 price**」——說明本 bug 與 6 筆 meta 的語意表
        （特別是 `regular === ''` 與 `sale === ''` 的**不同**語意）
      - 「**簽章版本遷移：v1 分支永久保留**」——過渡期行為矩陣 + 拔掉 v1 的後果 + 若要拔必須先做的升級路徑
      - 「**版本判斷必須用 `payload_version()`，不可用 meta 是否存在**」——F-4 的攻擊敘述
      - 「`normalize_price_for_signature` 的真實行為是 `'888' → '888.00'`」——更正既有文件中的錯誤描述，
        並說明為何**不能**改它
      - 「`OverridePriceResolver` 是 cart / 單品頁的唯一取價真相」——取代原本靠人維持「逐字一致」的紀律
      - 「`is_on_sale()` 翻轉 → `exclude_sale_items` 優惠券行為變更」（R-6）
    - 依賴：第四、五階段
    - 風險：低

24. **`.claude/CLAUDE.md`**
    - 新增 Phase 13 章節（表格 + 完工要點 + 驗證數據），並在 Common Pitfalls 補一條：
      「**Don't** 在 cart 只 `set_price()` 就以為價格覆寫完成——Cart Block / Store API 的劃線原價
      讀的是 `get_regular_price()`，漏了它顧客會看到**商品原始原價**被劃掉，且沒有任何錯誤訊息。
      改動 cart 價格時 price / regular / sale 三者必須成套。」
    - 依賴：同上
    - 風險：低

25. **`specs/tech-debt.md`**
    - TD-1：把 integration 失敗數從 21 更新為實測值（若第 18 步執行則 -6），並註明成因已查明（R-5）
    - 新增 **TD-34**：跨層級 regular < sale 的荒謬顯示（R-8），註明「與單品頁行為一致，刻意不修」
    - 新增 **TD-35**：`CartPriceSignatureService` 的 v1 驗證分支**不是死碼**，
      因持久化購物車永不過期而必須永久保留；附「若要移除必須先做 v1→v2 就地升級」的前置條件
    - 依賴：同上
    - 風險：低
    - ⚠️ TD 編號**只能往後加、不可補空號**（tech-debt.md 使用方式第 4、5 條）。目前最大為 TD-33

---

## 測試策略

| 層級 | 檔案 | 內容 |
|------|------|------|
| Unit（Domain） | `tests/Unit/Domain/Service/OverridePriceResolverTest.php`（新） | 三元組 fallback 語意 7 條 |
| Unit（Application） | `tests/Unit/Application/Service/CartPriceSignatureServiceTest.php` | +7 條（v2 / 雙軌 / `payload_version` / 跨版本隔離）；既有 12 條零修改 |
| Integration | `tests/Integration/Infrastructure/WooCommerce/CartPriceOverrideHookTest.php` | +13 條（核心回歸 5 + v1 相容與安全 6 + 連帶行為 2）；修正 6 條既有斷言 |
| Integration（回歸） | `tests/Integration/Infrastructure/WooCommerce/SingleProductPriceHookTest.php` | **零修改必須全綠**（第一階段重構的驗收證明） |
| 人工 | 真實站台 Cart Block | 步驟 22（含負向驗證） |

**測試執行指令**（`wordpress.rule.md` §11.3，⚠ 不要用 `npx wp-env`）：

```bash
./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --colors=never

./tests/e2e/node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/power-shop \
  -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration --colors=never

vendor/bin/phpcs inc/          # ⚠ 不要用 composer lint（.claude/tmp/ 會被掃到，TD-18）
```

**關鍵邊界情況**：
- 賣場只設 regular（無 sale）／只設 sale（無 regular）／兩者皆設／完全不設
- 變體層覆寫 vs 商品層覆寫（**整組取用，不逐欄混用**）
- v1 / v2 cart item 混合共存
- 覆寫價為 `'0'`（免費贈品）—— normalize 後為 `'0.00'`，**不可**與「未覆寫」的 `''` 混淆
- 同一商品來自兩個不同賣場（既有測試已覆蓋，需確認新邏輯不破壞）
- 商品在加購後被刪除（`reset_to_original_price` 的 `wc_get_product()` 回 false 分支）

---

## 依賴項目

- **無新增** composer / npm 套件
- WooCommerce 10.1.4（本地實測版本）—— 相依的兩個內部行為：
  `ProductSchema::prepare_product_price_response()` 讀 `get_regular_price()`；
  `WC_Discounts` 用 `is_on_sale()` 判 `exclude_sale_items`。兩者皆為 WC 長期穩定 API
- wp-env 測試容器（跑 IT 必須）

---

## 風險與緩解措施

| 等級 | 風險 | 緩解 |
|------|------|------|
| **高** | R-1 既有購物車驗章全失敗 → 分潤價靜默消失 | v1 雙軌驗證 + 過渡期行為矩陣 + 專屬 IT 回歸鎖（步驟 20 第一條） |
| **高** | R-3 `has_all_profit_meta` 誤判 → 同上 | 條件式必填（依簽章版本）+ IT |
| **高** | F-4 v1 簽章 + 注入假 meta → 套用未簽章的假原價 | 用 `payload_version()` 判版本、v1 分支無條件忽略 + 安全 IT |
| **中** | R-4 `null` 被 normalize 成 `'0.00'` → 顯示「原價 NT$0」 | `normalize_nullable_price()` + 專屬 IT |
| **中** | R-6 `is_on_sale()` 翻轉影響 `exclude_sale_items` 優惠券 | 刻意接受（語意正確、與單品頁一致）+ IT 鎖住 + 文件 + **交付時明確告知站長** |
| **中** | 第一階段重構誤傷 Phase 9 已驗證的單品頁行為 | `SingleProductPriceHookTest` 11 條**零修改**必須全綠，作為硬性驗收 |
| **中** | `resolve_effective_price` → resolver 後 `price` 值改變 | 已逐分支比對確認等價（步驟 12 註記）+ 既有 cart IT 覆蓋 |
| **低** | R-5 測試斷言修改被誤讀為「改測試遷就實作」 | 檔頭 docblock 寫明原因；只改測試不改行為；先實測確認再動 |
| **低** | R-8 跨層級 regular < sale 顯示荒謬 | 登記 TD-34，與單品頁行為一致，不修 |
| **低** | R-9 product 物件 dirty | 與既有 `set_price()` 同風險等級，不新增類別 |

---

## 錯誤處理策略

沿用本專案既有的**三層分工**（Phase 7 / Phase 9 慣例）：

1. **前端守門**：本次**無前端改動**。賣場編輯頁的 `sale <= regular` Form rule 已存在
2. **後端 Domain 驗證（唯一真相）**：`PriceOverride` VO 的 invariant 不變、不放寬
3. **降級不阻斷**：簽章 / meta 任何異常 → 記 `error_log`（過濾 `\r\n\t` 防 log injection）→
   strip meta + reset 原價 → **對前端完全靜默**（避免 oracle）。顧客仍可結帳，商家不會少收錢

**唯一的例外**：`normalize_nullable_price` 的 `null` 處理不走「降級」而是**結構上不可能發生**
（提前 return），因為它的降級結果（NT$0）比不處理更糟。

---

## 限制條件（此計劃**不會**做的事）

1. **不改** `normalize_price_for_signature()` 的行為（只改 docblock）—— 理由見 R-5
2. **不改** Domain 的 `PriceOverride` / `VariationOverride` / `OverrideItem` / `PriceCalculator`
3. **不改** `AddToCartHook`（三道閘門）、`CartItemMetaDisplay`、`SingleProductPriceHook` 的 filter 註冊範圍
4. **不改** 任何前端檔案、`specs/api/api.yml`（cart meta 不是 wire 契約）
5. **不做** v1 → v2 的就地升級（會把 repository 查詢加回 `before_calculate_totals` 熱路徑）
6. **不移除** v1 驗證分支（見「何時可以拔掉 v1」）
7. **不處理** TD-21（Cart Block `undefined:` 標籤）—— 同一個畫面但完全不同的成因（`item_data` 鍵名，
   在 `@woocommerce/blocks` 前端），與本次價格問題正交
8. **不處理** 訂單 / 訂單 email —— 已查證其完全不使用 regular_price（見「補充」表）
9. **不新增** 任何 composer / npm 套件
10. **不修** TD-1 中除 cart hook 那 6 條以外的 15 個 pre-existing 失敗

---

## 成功標準

- [ ] Cart Block 顯示的劃線原價 = 賣場覆寫的 regular（800），現價 = 600（步驟 22 人工驗證）
- [ ] 不帶 `profit_shop_id` 加購同商品 → 劃線價回到 1000，零殘留（負向驗證）
- [ ] **改版前既有的 v1 購物車項目維持原價格、不被判 tampering、不寫 error_log**（IT 鎖）
- [ ] v1 簽章 + 注入假 regular meta → 假原價不生效（安全 IT 鎖）
- [ ] v2 簽章涵蓋 regular / sale，任一被竄改即 fallback 原價（IT 鎖）
- [ ] 未覆寫 regular 時 meta 為 `''` 而非 `'0.00'`（IT 鎖）
- [ ] `SingleProductPriceHookTest` 11 個方法**零修改**全綠（重構無誤的證明）
- [ ] Unit / Integration failure 數**不高於**步驟 0 實測的 baseline（若執行步驟 18 則 integration 應 −6）
- [ ] `vendor/bin/phpcs inc/` 全綠（新檔納入計數）
- [ ] 所有新 `test_*` 方法各自標了 `@group`，`--testdox` 筆數 == `test_` 方法數（無靜默跳過）
- [ ] `profit-shop.rule.md` / `CLAUDE.md` / `tech-debt.md` 三份文件同步完成
- [ ] `exclude_sale_items` 優惠券的行為變更已寫入文件並**在交付訊息中明確告知 team lead**

---

## 預估複雜度：**中高**

- 程式碼改動量不大（約 4 個檔、+150 行以內，新增 1 個小 Domain class）
- 複雜度全部集中在**簽章版本遷移的正確性**：一旦 v1 相容寫錯，症狀是「既有顧客的分潤價
  安靜消失」——沒有錯誤訊息、沒有 log（因為那條路徑的 log 只寫給開發者看）、
  站長通常要等到對帳才會發現
- 因此**第 20 步的 v1 相容 IT 是整個計劃的驗收核心**，其優先度高於本 bug 的修復本身

---

## 待 team lead 拍板的決策點

| # | 決策 | 選項 | 建議 |
|---|------|------|------|
| D-1 | 是否順手修 R-5 那 6 條 pre-existing 測試斷言（步驟 18） | (a) 一併修（+6 綠，範圍略超出）／(b) 留給 TD-1 | **(a)**：我們正在改同一個檔，留著紅燈會讓本次「零 regression」無法判定 |
| D-2 | regular/sale 是否納入簽章 | (a) 納入（v2 遷移）／(b) 不納入（只加 meta，零遷移） | **(a)**：(b) 因 R-7（`is_on_sale()` → `exclude_sale_items` 優惠券）而有真實金額影響，不只是顯示層 |
| D-3 | 是否做第一階段的 `OverridePriceResolver` 抽取 | (a) 做（根除 F-7）／(b) 跳過，cart 自己複製一份三元組邏輯 | **(a)**：cart 與單品頁語意分歧**就是本 bug 的成因類型**，靠註解要求「逐字一致」已被證明無效 |
| D-4 | v1 驗證分支的下架期限 | (a) 永久保留／(b) 訂一個期限 | **(a)**：持久化購物車無過期機制（R-2 已查證），(b) 在任何期限下都會有顧客被靜默降價 |
