# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Version:** 3.0.12
> **Project Type:** WordPress Plugin (WooCommerce extension, React SPA admin UI)

## Project Summary

**Power Shop** replaces WooCommerce admin screens with a React/TypeScript SPA built on Refine + Ant Design, served at `admin.php?page=power-shop`.

- **PHP Namespace:** `J7\PowerShop` | **Text Domain:** `power_shop`
- **React Mount:** `#power_shop` | **REST Namespace:** `power-shop` → `/wp-json/power-shop/`
- **Entry:** `plugin.php` → `Bootstrap` → `Admin\Entry` + `Domains\Loader`
- **Frontend:** `js/src/main.tsx` → `App1.tsx` (Refine + HashRouter)

## Build / Dev / Lint Commands

```bash
# Frontend
pnpm dev              # Vite dev server (HMR, port 5178)
pnpm build            # Production build → js/dist/
pnpm lint:ts          # ESLint。⚠ 實際指令含 --fix，會改檔案；唯讀檢查改用 npx eslint "js/src/**/*.{ts,tsx}"（不加 --fix）
npx tsc --noEmit      # TypeScript type-check

# PHP
composer lint          # PHPCS 唯讀檢查 (WordPress-Core/Docs/Extra, config: phpcs.xml)
pnpm lint:php          # ⚠ 實際指令含 phpcbf（自動修正，會改檔案）+ phpcs + phpstan；唯讀檢查改用上面的 composer lint + 下面的 phpstan analyse
vendor/bin/phpstan analyse inc --memory-limit=1G   # PHPStan level 9 (config: phpstan.neon)

# Release
pnpm sync:version     # Ensure package.json == plugin.php version
pnpm release:patch    # Bump + build + GitHub Release + ZIP
pnpm release:minor
pnpm release:major
```

> **注意**：`package.json` 沒有 `lint` 這個腳本，只有 `lint:ts` / `lint:php`（見 `package.json:12-13`）。照舊文件寫的 `pnpm lint` 執行會直接失敗（`ERR_PNPM_NO_SCRIPT`）。

## Common Pitfalls

1. **Don't** add logic in `plugin.php` — only `Plugin` class + `Plugin::instance()` call
2. **Don't** read `window.power_shop_data` directly — use `useEnv()` or `env` from `@/utils`
3. **Don't** use `fetch`/`axios` for API — use Refine hooks with explicit `dataProviderName`
4. **Don't** forget `memo()` on page components — Refine re-renders can be expensive
5. **Don't** place business logic in route components — extract to hooks
6. **Always** specify `dataProviderName` on Refine hooks for non-default resources
7. **Always** register new REST API classes in `Domains\Loader`
8. **Always** use `WP::sanitize_text_field_deep()` on all REST request params
9. **Don't** remove the Enqueue Guard (`General::in_url(['page=power-shop'])`)
10. **Don't** 在 ProfitShop / ProfitPartner / ProfitMigration 用 `useTable` / `useForm`（後端 `{code, data}` 與 antd-toolkit dataProvider shape 不相容）—— 用 `useCustom` / `useCustomMutation` 並明確指定 `dataProviderName: 'power-shop'`。⚠️ **連帶紀律**：這三個 resource 的快取失效**必須**用 `useInvalidateProfitQueries`（`@/hooks`），**不可**用 Refine 的 `useInvalidate`——後者產生的 queryKey 在 resource 命名空間，`useCustom` 的在 custom 命名空間，兩者前綴對不上、**一次都打不中**；配上 `main.tsx` 全域 `staleTime: 10 分鐘`，症狀是「改完資料回列表頁看到的還是舊內容」且無任何錯誤訊息。詳見 `react.rule.md` §3。
11. **Partner self-service portal** 是**獨立 SPA bundle**（mount 點 `#profit_partner_portal`，URL `/profit-report/{slug}/`），與 admin SPA 完全隔離。Auth 走 cookie + sessionStorage metadata（永不存 token）；不用 Refine / dataProvider，直接 axios + `useCustom`/`useCustomMutation`；不打包 admin（grep `App1` / `Refine` 0 命中為驗收）。
12. **賣場前台**（`/profit-shop/{slug}/`，customer-facing）走 **PHP page template + theme 整合**，商品 grid 與商品 Modal（`<dialog class="pc-modal">`）**全部由 PHP 印出**（SEO 內容必須留在 HTML）。Phase 10-13 曾是「PHP 印內容 + React/antd bundle 掛互動層」的混合式；**Phase 14 起已移除 React / antd**（`js/src/profit-shop-front/` 整目錄與 `vite.config.ts` 第三個 entry 都已刪除），互動層改成**手寫 vanilla JS**（`inc/assets/js/profit-shop-front.js`，不進 Vite build pipeline），只負責五件事：開 Modal、Gallery 縮圖切換、描述展開收合、數量加減、變體選擇（依選中變體重寫 `variation_id` / `attribute_*` hidden input）。**Don't** 把商品名稱、價格搬離 PHP 輸出。AddToCart 是一張 **PHP 直接印出**的 WooCommerce 原生表單（帶 `profit_shop_id`），交給 WC `woocommerce_add_cart_item_data` filter 注入分潤標記，再由 Phase 3-D `CartPriceOverrideHook` 簽章 / 套價；加購結果由 template 自印的 `wc_print_notices()` 呈現（賣場頁不是 WC template，佈景主題的 `woocommerce_output_all_notices` 不會被觸發）。注意這條路與 Partner Portal SPA（`/profit-report/{slug}/`）路線**完全不同**：partner portal 走獨立 SPA + 不走 theme；賣場前台走 PHP template + 走 theme。**Shortcode 呈現模式**另有 `[power_shop_profit_shop id="{post_id}"]`（`ProfitShopShortcode`，與上述 template 共用同一份）；Phase 14 起 `$is_shortcode_context` 旗標已移除，**兩條路徑渲染逐字相同的 DOM**（外層一律 `<div>`，且都會傳 `$front_settings` 並呼叫 `ProfitShopFrontView::enqueue()`——早期 shortcode 路徑漏傳 `$front_settings` 曾讓已結束販售的賣場照樣可加購，見 `profit-shop.rule.md` §7）。⚠️ 但 shortcode 模式的賣場**公開網址仍然開得起來**（`ProfitShopRenderer` 不檢查 `mode`），`BasicTab.tsx` 的據實告知警語不可移除。詳見 `profit-shop.rule.md` §7 / §12.8、`react.rule.md` §6.7 與 `specs/tech-debt.md` TD-13。
13. **Always** 讓 admin 列表頁遵循 `.claude/rules/admin-list-ui.rule.md` 的「篩選卡片 + 實體列表 & 新增按鈕」標準版型（基準範本 `#/products`）：兩張 `antd-toolkit` 的 `Card`、新增按鈕在列表卡片內 Table 之上、Table 鋪 `defaultTableProps` + `getDefaultPaginationProps({label})`。**Don't** 用 Refine `<List>` 當列表頁外框、**Don't** 用 `antd` 的 `Card`。
14. **Don't** 假設 `pnpm lint` 存在——實際腳本是 `pnpm lint:ts`（含 `--fix`）與 `pnpm lint:php`（含 `phpcbf`，會改檔案）；CI / agent 唯讀檢查用 `npx eslint "js/src/**/*.{ts,tsx}"`（不加 `--fix`）與 `composer lint`。
15. **Don't** 把 antd `<Form>`（尤其 `type="submit"` 按鈕）直接放進 `antd-toolkit` 的 `SimpleModal` 而不處理事件冒泡——遮罩對所有冒泡 click 呼叫 `preventDefault()`，會連帶取消表單送出且無任何錯誤訊息。修法與清單見 `react.rule.md` §6.4 與 `admin-list-ui.rule.md` §7。
16. **Don't** 對 `wc-rest` resource 只設 `pagination.pageSize` 就假設分頁生效——WC REST API 只認 `per_page`，要把它當 **filter** 送才會生效，否則靜默退回每頁 10 筆。詳見 `react.rule.md` §3。
17. **Don't** 用 `agent-browser` 之類的瀏覽器自動化 CLI 截圖時假設呼叫端 shell 的 `cd` 會影響它的相對路徑——`agent-browser` 是**獨立行程**，相對路徑是相對於它自己的工作目錄（通常是專案根），**不是**呼叫端 shell 當下所在目錄。截圖 / 輸出檔案一律傳**絕對路徑**，否則檔案會落在專案根目錄，混進 `git status` 的 untracked 清單，有被 `git add -A` 誤收進版本庫的風險。
18. **Don't** 以為 Refine dataProvider 的錯誤物件（`HttpError`）帶有 `error.response.data`——Refine 只保留 `{ message, statusCode }`，原始 response body（含後端 `code` / `data`）已經不見，`statusCode` 甚至可能與真實 HTTP 狀態碼不符。錯誤訊息解析要 fallback 到 `error.message`，不要假設一定讀得到 `response.data.code`。詳見 `react.rule.md` §3。
19. **Don't** 以為 power-shop 自己管 Tailwind / daisyUI——**Phase 14 起本外掛已移除全部自有 CSS**（`tailwind.config.cjs` / `postcss.config.cjs` / `js/src/assets/styles/tailwind.css` / `js/src/profit-shop-front/styles.css` 全數刪除，`package.json` 也拿掉 `tailwindcss` / `daisyui` / `autoprefixer` / `postcss` / `postcss-import` / `postcss-nesting` 六個 devDependencies），樣式**全部改由 powerhouse 統一提供**：admin SPA 吃 powerhouse 的 `admin.min.css`（Tailwind + daisyUI + antd reset），前台（partner portal / 賣場前台）吃 `front.min.css`。powerhouse 的 Tailwind config 是 `important: '#tw'`，utility 一律輸出成 `#tw .flex{}` 這種前綴形式——**沒有 `id="tw"` 的祖先元素時 utility 全部靜默失效**（admin-layout 的 `<body>`、partner portal 自印 HTML 骨架的 `<html>` 都必須帶這個 id；後者由 `PartnerPortalRenderer::render_portal()` 顯式印出，前者是 powerhouse 自己的 admin-layout template，屬跨 plugin 改動）。daisyUI 元件 class 帶 **`pc-` 前綴**（不是 `tw-`）；`tw-` 開頭的只剩 powerhouse `blocklist` 擋下的少數 utility（例如 `tw-hidden`），且 `inline` / `tw-inline` 有專屬陷阱見 Pitfall #27。**跨 repo build 相依**：power-shop 的 class 是否有效，取決於 powerhouse 的 Tailwind build 有沒有掃到（其 `content` 已含兄弟目錄 `power-*` 的 `inc` 與 `js/src`）——**改動 power-shop 的 class 之後，必須到 powerhouse 重跑 `pnpm build-css:admin` / `build-css:front`**，否則新 class 不會出現在任何 CSS 檔裡，且 build 完全無警告。詳見 `react.rule.md` §10。
20. **Don't** 假設 `<Tooltip>` 包 disabled `<Button>` 會顯示提示——antd v5 拿掉了 v4 的 disabled 相容包裝，瀏覽器又不對 disabled `<button>` 派發滑鼠事件，提示因此永遠不會出現，且沒有任何錯誤訊息。也**不要**只靠 antd 目前會在 `disabled` 時抽掉 `<Button href>` 的 `href` 就假設安全——外開連結按鈕該拆成停用 / 可用兩條 render 分支，別把 `href` 和 `disabled` 一起塞給同一顆 `<Button>`。詳見 `react.rule.md` §2。
21. **Don't** 想把 antd `<Table>` 的展開圖示放進「某個欄位內」（而非表格最左端）時，還去改 `components.body.row` 或另外處理展開列——用 `showExpandColumn: false` 關掉預設最左展開欄，改在目標欄位自刻按鈕 + 受控 `expandedRowKeys`（非受控點了沒反應）即可；`expandable` 與自訂 `components.body.row`（例如 dnd-kit 拖曳排序）**零衝突**，因為 rc-table 渲染展開列時只傳 `className` / `style` 給自訂 row component、**不傳 `data-row-key`**，會自動落進「先判斷 `data-row-key` 是否存在」這種既有守門的 fallback 分支。`expandedRowRender` 也天然 lazy（沒展開過的列不 render，收合是 `display:none` 不 unmount），查詢可以直接寫在展開列渲染的子元件裡，不需要自己刻快取。詳見 `react.rule.md` §6.5「`ItemsEditor` 變體展開列」。
22. **Don't** 在賣場前台的加購路徑上漏掉 `profit_shop_id`——後端 `AddToCartHook`（priority 5）**只**從 `$_GET` / `$_POST` 讀它，讀不到就**靜默走商品原價、分潤失效，且沒有任何錯誤訊息**。Phase 14 起商品卡片與商品 Modal（`<dialog>`）都是 **PHP 直接印出**的 WooCommerce 原生表單（`<form method="get">` 或 `<form method="post">`），`profit_shop_id` 印死在 hidden input 裡；Modal 內的 `variation_id` / `attribute_*` 由 `inc/assets/js/profit-shop-front.js` 在使用者選定變體後才填入（鍵一律取自**選中變體自己的** `attributes` 物件，**不要**自行加 `attribute_` 前綴組出來——中文等自訂屬性經 WC `sanitize_title()` 後可能與原始名稱不同，自行組鍵會安靜地對不上）。JS 停用時卡片本身的表單 / 連結就是完整可用的加購路徑（漸進增強，**不需要**任何 `<noscript>` 樣式）。**Phase 15 起 Modal 內的加購改走 AJAX**（不換頁 + loading + 成功 toast），通道是 **WooCommerce 原生的 `?wc-ajax=add_to_cart`**，body 必須是 `application/x-www-form-urlencoded`（PHP 才會填 `$_POST`），只送 `product_id` / `quantity` / `profit_shop_id` 三個欄位；**variable 商品送的 `product_id` 是「選中變體自己的 ID」**（`WC_AJAX::add_to_cart()` 會自己換算回父商品並反查 `attribute_*`，換算**之後**才觸發 `woocommerce_add_cart_item_data`，第三閘門因此仍對得上），這條通道**不送也不需要** `attribute_*`。**Don't** 改送 JSON body——PHP 不會填 `$_POST`，分潤會靜默失效；真要另開通道就必須自己在呼叫 `add_to_cart()` 前把值橋接進 `$_POST`。**Don't** 在任何失敗分支自動重送表單——`wc_add_notice()` 沒有去重，同一則錯誤會顯示兩次，而「伺服器已加購成功但回應異常」時重送會造成**重複加購、顧客被重複收費**；業務失敗一律依 WooCommerce 官方契約導向回應內的 `product_url`。也**不要**改用 `add_to_cart()` 的第 5 個參數 `$cart_item_data` 傳 `profit_shop_id`（`AddToCartHook` 開頭有 short-circuit，那會**跳過全部三道閘門**，變成任何人都能對任意商品套任意賣場的分潤價）。詳見 `react.rule.md` §6.7。（Phase 10 的 admin-ajax `FrontAjax` + WC Store API 雙通道、Phase 11 的自訂 REST `FrontCartApi`、Phase 12 的 React `AddToCartButton.tsx` 皆已**整條移除**，前台不再有任何購物車 API，Phase 14 起也不再有任何 React 程式碼。）
23. **Don't** 改動賣場前台 PHP↔JS 的 DOM 契約——Phase 14 換成一組新的 `data-ps-*` 屬性：賣場容器 `.ps-profit-shop-front[data-ps-shop]`（多賣場查找邊界，一頁多個 shortcode 賣場靠它天然隔離）、卡片 `[data-ps-product-id]`、對應 Modal `[data-ps-modal-for]`，以及 Modal 內的 `[data-ps-gallery-main]` / `[data-ps-gallery-thumb]` / `[data-ps-content*]` / `[data-ps-qty*]` / `[data-ps-attr-*]` / `[data-ps-variation-id]` / `[data-ps-price]` / `[data-ps-stock]` / `[data-ps-buyer]` / `[data-ps-submit]` / `[data-ps-icon]` 等；**Phase 15 另增** `[data-ps-form]`（AJAX 攔截點）/ `[data-ps-submit-spinner]` / `[data-ps-submit-label]` / `[data-ps-submit-error]` / `[data-ps-submit-error-label]`（Modal 內就地錯誤列）/ `[data-ps-toast]` / `[data-ps-toast-body]` / `[data-ps-toast-label]`（成功通知，印在賣場容器內）；**Phase 16 再增** `[data-ps-schedule]`（排程說明區塊）/ `[data-ps-countdown][data-ps-countdown-target]`（倒數容器與目標毫秒數）/ `[data-ps-cd-unit="days|hours|minutes|seconds"]`（daisyUI `pc-countdown` 外層）/ `[data-ps-cd-plain="…"]`（> 99 時的純文字退路）（完整清單見 `inc/assets/js/profit-shop-front.js` 檔頭註解）。改了任一項會讓商品 Modal 開不起來、Gallery / 數量 / 變體互動靜默失效，而且**不會有任何錯誤訊息**。JS 查找一律限定在最近的 `.ps-profit-shop-front` 祖先內，**不要**改回全域查詢。舊版 `#profit_shop_front_app` / `.ps-btn` / `.ps-not-ready` / `.ps-nojs-fallback` / `.ps-modal-trigger` / `window.power_shop_front_data`（React 全域 payload）**已隨 React bundle 一併移除，不存在**。
24. **Don't** 在 cart 只呼叫 `set_price()` 就以為賣場覆寫價格已經完成——Cart Block / Store API 顯示的劃線原價讀的是 `get_regular_price()`（`WooCommerce/src/StoreApi/Schemas/V1/ProductSchema.php`），漏了它顧客會看到**商品原始原價**被劃掉、只有實收價正確，且沒有任何錯誤訊息。改動 cart 價格時 `price` / `regular_price` / `sale_price` **三者必須成套**（`set_price()` + `set_regular_price()` + `set_sale_price()`）；`sale_price` 留空時仍要顯式呼叫 `set_sale_price('')` 清掉商品原本的特價，否則會出現「原價劃掉→特價」但實收卻是覆寫原價的矛盾。詳見 `profit-shop.rule.md` §7 與 Phase 13。
25. **Don't** 以為分潤夥伴密碼只存雜湊——2026-08 起**雜湊與明文並存**（站長要求能在後台查看 / 複製密碼再轉交夥伴，那在只存雜湊的前提下技術上不可能）。`_partner_password`（雜湊）仍是 `verify_password()` 的**唯一**依據，`_partner_password_plain`（明文）**只供顯示**；驗證路徑永遠不得讀明文。兩者必須在 `PartnerTermRepository::save()`（**密碼寫入的唯一出口**，五條路徑全經過它）內成套寫入，且明文**必須先 `wp_slash()`**——`update_metadata()` 內部會 `wp_unslash()`，不 slash 會靜默吃掉 `\` / `'` / `"`，導致後台顯示的密碼比實際密碼少幾個字元、站長照著複製給夥伴卻登不進去。`PartnerOutput` 因此含明文、**只能用於 admin-only endpoint**（`GET /partner-auth/me` 刻意自行列舉欄位輸出，不用這個 DTO）。另有兩條連帶紀律：`PartnerInput::from_array()` 必須把空密碼正規化為 null（否則站長清空欄位按儲存會把夥伴密碼設成空值、從此登不進去）、Edit 頁只在密碼**真的改動**時才送（`save()` 一收到 password 就 bump `password_changed_at` = 該夥伴所有登入 session 立即失效）。詳見 `profit-shop.rule.md` §6「明文密碼並存」與 `react.rule.md` §6.5「夥伴密碼是一般欄位」。
26. **Don't** 以為賣場前台的 daisyUI class（`pc-btn-sm` 之類）需要自己再疊一層 Tailwind utility 才會生效——**這個限制已於 2026-08-04 由 powerhouse 的 CSS 建置層系統性修正**，繞過用的常數已全部移除，現在直接寫原生 `pc-btn pc-btn-sm` 就會生效。根因（仍然成立，供理解）：daisyUI v4 的元件 class 以 specificity **(0,1,0)** 輸出，且**沒有** `important` 之類的設定可以提高權重；WordPress 佈景主題常有相同 specificity 的規則（實測 Blocksy 命中 `[type="submit"]` 設定 `min-height` / `font-size` / `padding`），**CSS 同分時由載入順序決勝**，主題排在 plugin CSS 之後 → 永遠贏，`pc-btn-sm` / `pc-btn-ghost` / `pc-btn-circle` / `pc-input-sm` 這類尺寸與變體 class 因此被主題的 40px 蓋掉（原版 32px）。**現行修法**：powerhouse 的 `pnpm build-css:admin` / `build-css:front` 新增一道 PostCSS 步驟（`scripts/postcss-scope-daisyui.cjs`，透過 `--postcss ./postcss.tailwind.cjs` 掛載），把所有 daisyUI 選擇器整批收進 `#tw`——與 Tailwind utility 同一個 scope，specificity 一起拉高到 (1,1,0)，穩定勝過主題、與載入順序無關。原本收斂在 power-shop 這一側的 `ProfitShopFrontView::BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE` 三個 Tailwind utility 尺寸常數**已全部移除**。**唯一例外**：daisyUI 對某個元素**完全沒出過規則**時（例如 Modal 背景遮罩的 `<button>`——daisyUI 只對 `.pc-modal-backdrop` 這個 form 本身出規則，沒管裡面的 button），沒有規則可以 scope，主題仍會無對手地套用（塗成整片實心色塊、蓋掉整個畫面），這種情況**仍要**使用端自行加 Tailwind utility 壓過去（`templates/partials/profit-shop-modal.php` 的 backdrop button 因此保留 `bg-transparent text-transparent border-0 cursor-default`，是本次修正後唯一僅存的手動 utility 用法）。詳見「Phase 14 補充修正」章節、`profit-shop.rule.md` §12.8、`react.rule.md` §10 與 `specs/tech-debt.md` TD-40；powerhouse 端的完整建置說明見 powerhouse `.claude/CLAUDE.md`。
27. **Don't** 給賣場前台的 SVG 圖示加任何 display utility（`inline` / `tw-inline` / `tw-block`）——`inline` 在 powerhouse 的 `blocklist` 內（與 WordPress 衝突），寫了不會產出、靜默不生效；替代品 `tw-inline` 更糟：它與 `tw-hidden` 同樣來自 `addUtilities`、權重相同，但 `.tw-inline` 在產出的 CSS 中排在 `.tw-hidden` **之後**，兩者同時存在時 `tw-inline` 會贏，讓「本來要隱藏的元素」永遠顯示（本專案的庫存 / 屬性狀態圖示切換就是靠 `tw-hidden`，踩到會讓三種圖示同時出現）。SVG 瀏覽器預設 display 本來就是 `inline`，本專案 preflight 又是關閉的，**什麼都不加**即可；垂直對齊用 `align-middle`（一般 utility，不影響 display，不在 blocklist）。
28. **Don't** 以為改完 powerhouse 的 Tailwind build 就會立刻對站台生效——powerhouse 的 CSS enqueue 是 `?ver={Plugin::$version}`，重跑 `pnpm build-css:admin` / `build-css:front` 只改檔案內容、**版本號不變**，瀏覽器會沿用舊快取，樣式壞掉且**沒有任何錯誤訊息**（實測就曾被這樣騙過一次，誤以為某個 class 沒生效，其實是瀏覽器用舊 CSS）。**部署必要條件**：powerhouse 重新 build CSS 之後必須 bump 版本號（或走正常 `pnpm release:patch` 流程），否則既有訪客的瀏覽器會長時間停留在舊樣式。

29. **Don't** 把 Tailwind 的 `text-*`（或任何會設 `line-height` 的 utility）掛在 daisyUI 元件本身，尤其是**行為依賴 `line-height` 精確等於 `1em`** 的元件（`pc-countdown` 是已知案例）。powerhouse 的 Tailwind 是 `important: '#tw'`，utility 的 specificity 是 **(1,1,0)**；而 daisyUI 有一批**根部錨定**的宣告（例如 `:root .pc-countdown{line-height:1em}`）因為 `scope-daisyui` 的 `ROOT_ANCHORED` 守門而**刻意不被 scope**，維持 **(0,2,0)**——ID 欄位勝出，**Tailwind utility 反過來蓋掉 daisyUI**。這與 Pitfall #26 是同一套機制的**反方向**：#26 講 scope 讓 daisyUI 贏過佈景主題，本條講 scope 讓 utility 贏過 daisyUI 自己的必要宣告。⚠️ **daisyUI 官方範例會誤導你**：官方站寫的是 `class="countdown font-mono text-5xl"`（字級與元件同一個元素），在那邊 `.text-5xl` 只有 (0,1,0)、輸給 `:root .countdown`，所以能動；照抄到本專案就壞。`pc-countdown` 的實際症狀是「上下兩個數字各露一半」的錯位（`::before` 以 `top: calc(var(--value) * -1em)` 捲動，每行高度卻是 line-height，兩者不等就每格累積偏移），**沒有任何錯誤訊息**。修法：把 `font-*` / `text-*` 移到**外層 wrapper**，讓 daisyUI 的規則直接命中元件本身——**直接命中的宣告永遠優先於繼承值**，與 specificity 或 CSS 順序無關，因此是決定性的修法（不要改用 `leading-none` 之類的 utility 對打，那是同分靠順序決勝、powerhouse 調整輸出順序就會再壞一次；何況 `leading-none` 在 `front.min.css` 根本沒產出）。實作與完整推導見 `ProfitShopFrontView::countdown_unit()` 的 docblock。

30. **Don't** 用 `curl` 抓賣場前台頁面來驗證改動——站台裝了 **WP Rocket**，未登入的 GET 會拿到快取版本，實測抓到的是 Phase 14 之前的 inline style 版 HTML（TD-32）。要看真實渲染結果，網址加一個 cache-buster query（`?nocache=xxx`）即可，或直接用 `agent-browser` 以登入身分開啟。

## Profit Shop Domain（v1 開發中）

> 全新「分潤賣場」系統，與舊版 legacy 一頁商店共存。
> 設計文件：`specs/2026-05-06-profit-shop-design.md`
> 架構與規範：`.claude/rules/profit-shop.rule.md`

**目前狀態**：Phase 4-A + 4-B + 4-C + 5 + 6-A（含 6-A1 後端 + 6-A2 前端 UI）+ 7（賣場編輯頁商品選擇器重構）+ 8（Partner 夥伴圖片）+ 9（賣場商品變體層價格 / 灌水人數覆寫）+ 10（賣場前台 UI 照搬 legacy）+ 11（賣場前台購物車改自訂 REST）+ 12（賣場前台退回 WooCommerce 原生加購、移除自訂購物車）+ 13（購物車顯示賣場覆寫後的原價 / 特價）+ 14（賣場前台改 PHP + daisyUI + vanilla JS，移除 React / antd；CSS 統一交給 powerhouse 管理；獨立頁面與 shortcode 兩條路徑渲染統一 DOM）+ 14 補充（2026-08-04，daisyUI 樣式改由 powerhouse CSS 建置層系統性 scope 進 `#tw`，取代逐檔手動 Tailwind utility 繞過）+ 15（商品 Modal 改 AJAX 加購，走 WooCommerce 原生 `?wc-ajax=add_to_cart`，含 loading 與成功 toast；零新增 endpoint、分潤三閘門零修改）+ 16（賣場開啟 / 關閉時間 + 期間外呈現：三個互相獨立的勾選框「daisyUI 倒數計時 / 自訂文字 / 顯示商品但不可購買」，全部不勾＝什麼都不顯示；後端 Domain 與 Application 層零修改，TD-22 部分兌現）完工 → Profit Shop 全套上線就緒（Domain / Application / Infrastructure / Admin SPA / Partner Portal / 賣場前台**純 PHP + daisyUI + vanilla JS** UI + Modal 加購端到端（WooCommerce 原生表單）+ Partner 自助修密碼端到端 + 商品選擇器 Modal 化 + Partner 夥伴圖片 + 變體層價格覆寫含單品頁一致性 + Shortcode 呈現模式與獨立頁面渲染完全相同 DOM + 購物車劃線原價與單品頁 / 賣場卡片三處一致 + 樣式統一由 powerhouse 管理、本外掛已無任何自有 CSS）。Phase 7 / 8 / 9 已 commit（7 → `52d7aa8`…`6c96679` 一系列、8 → `52d7aa8`、9 → `ab6d0e3` + `381d8b2`）；Phase 10 / 11 由 `issue/L3` 分支的 `ff81dbb` 帶入，經本次 merge 併進 `refactor/one-page`。⚠️ Phase 9 與 Phase 10 / 11 是**兩條平行開發的分支**（各自從 Phase 8 之後接續編號、各自都叫 Phase 9），合併時以 `refactor/one-page` 保號、`issue/L3` 順延一位收斂；讀舊 commit message 時請注意 `ff81dbb` 內文的「Phase 9 / 10」即本文件的 Phase 10 / 11。已知問題與技術債見 `specs/tech-debt.md`。

### Phase 1（Domain 層）已完工（commits `cbd0522` / `8359918` / `9ecdb77`）

| 層 | 內容 | 路徑 |
|----|------|------|
| ValueObject | PriceOverride / ProfitRate / PartnerSlug / InflatedCount / ShopMode | `inc/classes/Domains/ProfitShop/Domain/ValueObject/` |
| Entity | OverrideItem / ProfitShop / SettlementRecord | `inc/classes/Domains/ProfitShop/Domain/Entity/` |
| Service | PriceCalculator（fallback chain）/ ProfitCalculator / RoundingStrategy interface | `inc/classes/Domains/ProfitShop/Domain/Service/` |
| Repository Interface | ProfitShopRepository / PartnerRepository / SettlementRepository | `inc/classes/Domains/ProfitShop/Domain/Repository/` |
| DTO | ProductSnapshot / PartnerSnapshot / FilterCriteria | `inc/classes/Domains/ProfitShop/Domain/Snapshot\|Criteria/` |
| Exception | 8 個 final class extends \DomainException | `inc/classes/Domains/ProfitShop/Domain/Exception/` |

### Phase 2（Infrastructure 層 + CPT）已完工（commits `fb216c7` / `2004b00`）

| 層 | 內容 | 路徑 |
|----|------|------|
| WordPress Registrar | CptRegistrar（powershop CPT，`show_in_rest=false`）/ TaxonomyRegistrar（profit_partner，termmeta 顯式 register_meta）/ RewriteRules（`/profit-report/{slug}/`） | `inc/classes/Domains/ProfitShop/Infrastructure/WordPress/` |
| Persistence | CptProfitShopRepository（JSON items 序列化、trashed find→null）/ PartnerTermRepository（`wp_hash_password`、`is_in_use` 反查）/ OrderItemSettlementRepository（`wc_*_order_item_meta`，HPOS 雙路徑） | `inc/classes/Domains/ProfitShop/Infrastructure/Persistence/` |
| Sub-Loader | ProfitShop\Loader（內部 sub-loader，註冊到 `Domains\Loader`） | `inc/classes/Domains/ProfitShop/Loader.php` |
| Domain 補洞 | PersistenceFailure（替代 4 處 RuntimeException）+ status invariant 修正 | `inc/classes/Domains/ProfitShop/Domain/Exception/` |
| 測試 | 7 個 IT 檔（28 + 4 邊界測試），`tests/Integration/Infrastructure/` | `tests/Integration/Infrastructure/` |

### Phase 3-A（Domain 缺口補完 + Application 骨架）已完工（commit `c1f50ea`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain Exception | 9 個新 final class（PartnerNotFound / ProductNotFound / InvalidVariation / InvalidCredentials / TooManyAttempts / Forbidden / SlugConflictException / RateLimitExceeded / LegacyShopNotImportable） | `inc/classes/Domains/ProfitShop/Domain/Exception/` |
| Domain Entity 修正 | ProfitShop `$status` protected + `status()` getter + `change_status()` mutator（VALID_STATUSES invariant） | `inc/classes/Domains/ProfitShop/Domain/Entity/ProfitShop.php` |
| Domain ValueObject | SlugConflict（DDD 純度修正：避免 Exception 反向依賴 Application） | `inc/classes/Domains/ProfitShop/Domain/ValueObject/SlugConflict.php` |
| Repository Interface | PartnerRepository 補 `delete()` + 實作 | `inc/classes/Domains/ProfitShop/Domain/Repository/` + Persistence |
| Application DTO | 12 個 immutable DTO（PHP 8.1 readonly，from_array / to_array round-trip） | `inc/classes/Domains/ProfitShop/Application/DTO/` |
| Application Service Stub | 7 個 Service stub（method 拋 BadMethodCallException，留 3-B/C 實裝） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| 測試 | 41 條新 Unit Test（NewExceptionsTest / ProfitShopChangeStatusTest / DtoRoundTripTest / PartnerRepositoryDeleteContractTest） | `tests/Unit/` |

### Phase 3-B（Admin CRUD UseCase + V2Api）已完工（commit `b549796`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Application UseCase | 18 個 UseCase（8 Shop + 5 Partner + 2 Migration + 3 Settings） | `inc/classes/Domains/ProfitShop/Application/UseCase/` |
| Application Port | 5 個 interface（ProductLookup / SlugConflictLookup / LegacyShopRepository / ItemValidator / SlugConflictDetector）⚠️ 原有的 RewriteRulesFlusher / SettingsRepository 已隨全域設定頁移除 | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Application Service | 3 個 Service 實裝（ItemValidator / SlugConflictDetector / ProductSnapshotProvider） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Infrastructure Adapter | WpProductLookup / WpSlugConflictLookup（spec §6.11 五類衝突 SQL）/ LegacyOnePageShopRepository | `inc/classes/Domains/ProfitShop/Infrastructure/(Persistence\|WordPress)/` |
| Presentation | ExceptionMapper（spec §7 13 種 Domain Exception → HTTP）+ V2Api（17 個 admin endpoint，`namespace=power-shop`，partner POST/PUT/DELETE 升 `manage_options`） | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/` |
| Domain 補洞 | InvalidShopMode Exception | `inc/classes/Domains/ProfitShop/Domain/Exception/InvalidShopMode.php` |
| 測試 | 99 個新測試（69 UseCase Unit + 10 Service Unit + 30 IT） | `tests/Unit/Application/` + `tests/Integration/Infrastructure/Rest/` |

### Phase 3-C（Partner Auth + Reports，安全核心）已完工（commits `e98edbf` / `cb62bdd` / `f47a531`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Application Port | 4 個 interface（TransientStore / Clock / EmailNotifier / SettlementSummaryProvider） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Application Service | PartnerAuthService（rate-limit short-circuit + 統一 InvalidCredentials）/ PartnerTokenStore（sha256 hash 儲存，**不存明文** + dual safety expires_at）/ LoginRateLimiter（5 次失敗鎖定 + email warn-and-swallow） | `inc/classes/Domains/ProfitShop/Application/Service/` |
| Auth UseCase | LoginPartnerUseCase / LogoutPartnerUseCase（idempotent）/ GetCurrentPartnerUseCase / RegeneratePartnerPasswordUseCase（admin-only，wp_generate_password 12 字元） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Auth/` |
| Report UseCase | GetPartnerKpiUseCase / GetPartnerTrendUseCase / ListPartnerSettlementsUseCase（**partner_term_id 鎖死於 caller，禁止從 query string 取**） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Report/` |
| Infrastructure Adapter | WpTransientStore / SystemClock / WpAdminEmailNotifier / WpSettlementSummaryProvider（Phase 3-C placeholder 回 0/空陣列，3-D 將補真實 SQL） | `inc/classes/Domains/ProfitShop/Infrastructure/(WordPress\|Persistence)/` |
| Presentation | V2Api 新增 7 個 endpoint（regenerate-password + partner-auth ×3 + partner-reports ×3）+ `partner_token_permission`（從 X-Partner-Token / Authorization Bearer / Cookie 取）+ Set-Cookie HttpOnly/Secure/SameSite=Lax/Path=/wp-json/power-shop/ | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php` |
| 測試 | 73 個 test method / 194 assertion（紅燈→綠燈→二輪 PASS，Unit 265/486 全綠、Phase 3-C IT 46/129 全綠） | `tests/Unit/Application/` + `tests/Integration/Infrastructure/Rest/` + `tests/Support/`（FixedClock / InMemoryTransientStore / SpyEmailNotifier / InMemorySettlementSummaryProvider） |

**Domain / Application 約定（4-layer DDD）**：
- Domain：純 PHP，不依賴 WP / WC，例外統一 `extends \DomainException`
- Application：UseCase + DTO（PHP 8.1 readonly）+ Port interface（DIP 注入）
- Infrastructure：Persistence + WordPress + Rest，是唯一可呼叫 WP / WC API 的層
- Presentation：ExceptionMapper（Domain Exception → HTTP）+ V2Api（單一 ApiBase，namespace `power-shop`）
- Test：`tests/Unit/**` 純 PHP；`tests/Integration/**` 啟 WP；`tests/Support/**` fakes 與 production interface 同步 implements

### Phase 3-D（Settlements 真實聚合 + AddToCart Hook + 三輪安全強化）已完工（commits `78a050f` / `64e4c04` / `4e1c780` / `3b36a2a`）

| Batch | Commit | 範圍 | 測試 |
|-------|--------|------|------|
| Batch 1 | `78a050f` | T-1 PartnerRepo `get_password_changed_at()` + T-7 CartPriceSignatureService（HMAC-SHA256 + `cart-signature:v1` domain prefix + `v1.` version prefix + hash_equals） | 16 method（CartPriceSignatureServiceTest）+ 4 method（PartnerRepositoryGetPasswordChangedAtContractTest）+ 4 method（PartnerTermRepositoryGetPasswordChangedAtTest IT） |
| Batch 2 | `64e4c04` | T-2 PartnerTokenStore 撤銷比對 + 4-interface DI（`SaltProviderInterface` 加入）+ `hash_hmac('sha256', $token, wp_salt('auth'))` 升級 + T-5 WpSettlementSummaryProvider 真實 SQL（94 行 → 545 行，5 道安全防線：prepare / IDOR / HPOS 雙路徑 / bcadd 精度 / pagination cap） | 9 method（PartnerTokenStorePasswordRotationTest）+ 端到端 IT（PartnerPasswordRotationRevokesTokenTest）+ 21 method（WpSettlementSummaryProviderRealAggregationTest） |
| Batch 3 | `4e1c780` | T-3 LoginRateLimiter +per-IP 雙維度（換 slug 攻擊防禦，IP sha256 hash + 不解析 X-Forwarded-For）+ unknown slug `record_ip_only_failure` + T-8 CartPriceOverrideHook（**新層！`Infrastructure/WooCommerce/`**）：`add_cart_item_data` / `get_cart_item_from_session` / `before_calculate_totals` priority 999 + 4 筆 `_profit_*` meta + draft shop fallback + `wc_format_decimal($price, '')` normalize | 14 method（LoginRateLimiterIpDimensionTest）+ 14 method（CartPriceOverrideHookTest IT，含 draft shop test） |
| Batch 4 | `3b36a2a` | T-4 V2Api 收尾整合：regenerate-password 加 admin nonce（`X-WP-Nonce`）+ `Cache-Control: no-store, no-cache, must-revalidate` + extract_partner_token 三路徑優先級 PHPDoc 化；三輪 reviewer 11 條安全強化：LOW-T2-1（PartnerTokenStore `partner-token:v1\|` domain prefix）/ LOW-T2-2（verify try/catch fail-closed + log injection 過濾）/ LOW-T5-1（V2Api page max 10000 cap）/ LOW-T5-2（format_datetime MAX_TIMESTAMP cap）/ M-1（`power_shop_partner_login_client_ip` filter hook）/ M-2（LoginRateLimiter IP hash 升 hash_hmac + `SaltProviderInterface` DI + KEY_PREFIX_IP v2）/ MAJOR-1（`is_hpos_enabled` 共用一次計算）/ MAJOR-2（format_money 非數字 fail-fast）/ MAJOR-3（trend INNER JOIN 1:1 假設 docblock） | 全部既有測試 ctor 補注入 `FixedSaltProvider` regression 防護 |

### Phase 3-E（validate-slug + ExceptionMapper 解封 + OpenAPI 同步）已完工（commit `0660fc7`）

| 項 | 內容 | 路徑 |
|----|------|------|
| T-9 validate-slug | `ValidateSlugUseCase`（格式守門：空 / >60 字元 / `^[a-z0-9_-]+$`，違規拋 `InvalidPartnerSlug`）+ `SlugValidationOutput` readonly DTO（`available` / `conflicts` / `is_available()`）+ V2Api `GET /profit-shops/validate-slug?slug=xxx`（permission `null`） | `Application/UseCase/Shop/ValidateSlugUseCase.php` + `Application/DTO/SlugValidationOutput.php` |
| T-10 ExceptionMapper 解封 | `ExceptionMapper::should_mask()` private static helper + `power_shop_exception_mapper_mask` filter（test seam，default = `!WP_DEBUG`），測試 try/finally 復原；13 種 Domain Exception 對映邏輯與 V2Api callsites 完全未動 | `Infrastructure/Rest/ExceptionMapper.php` |
| T-11 OpenAPI 同步 | `specs/api/api.yml` 從 1996 行擴增至 2940+ 行：26 個 Profit Shop endpoint（Phase 3-A/B/C/E 全收）+ 21 個 component schemas + 3 個 securitySchemes（`PartnerToken` / `PartnerBearer` / `PartnerCookie`）+ partner endpoint 裸 payload 標註 + partner-reports 三 endpoint description 鎖死「禁從 query string 讀 partner_term_id」 | `specs/api/api.yml` |
| 測試 | 9 method（ValidateSlugUseCaseTest，FakeSlugConflictDetector）+ 紅燈 IT 7 method（待 wp-env 跑） | `tests/Unit/Application/UseCase/Shop/` |

### Phase 4-A（商家後台 React CRUD UI）已完工（commits `1c1e3b9` / `883edc9` / `6610548`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-A1 | `1c1e3b9` | ProfitShop CRUD（List / Edit / Create）+ SlugInput 即時驗證（debounce + useCustom enabled 切換）+ profitShopExceptionMapper（12 種 code → 友善繁中訊息）+ Q1 落坑紀錄（後端 `{code, data}` 不相容 antd-toolkit dataProvider，改 useCustom / useCustomMutation） | 12 檔（+1175 行） |
| 4-A2 | `883edc9` | Partner CRUD（List / Edit / Create + PartnerForm + PartnerSelector）+ RegeneratePasswordButton ⚠ HIGH-RISK（明文僅 useState、5 min auto-clear、Modal closable=false / maskClosable=false / keyboard=false、navigator.clipboard 無 fallback）+ ItemsEditor（OverrideItem 列表編輯，dedupe）+ ShopActionsButtons（publish / unpublish / duplicate）+ ProfitShopForm 升級（partner_term_id → PartnerSelector、items → ItemsEditor）；reviewer + security 雙審 PASS | 18 檔（+1490 行） |
| 4-A3 | `6610548` | ProfitMigration（List + ImportModal ⚠ HIGH-RISK 4 道閘門）+ ProfitSettings（單頁 + ResetButton ⚠ HIGH-RISK 自包 mutation；**已於後續整組移除**）+ OneShop 改造為雙入口（新版 / 舊版 wp-admin）+ 三輪 reviewer 累積 9 條順手清單清完（含 LOW-1 統一補 `dataProviderName: 'power-shop'` 30+ 處、LOW-2 Input.Password 預設 mask + 5 min timer 補 setPwdVisible(false) bugfix）；reviewer + security 雙審 PASS | 18 檔（+945 行） |

**Phase 4-A 完工要點**：
- 3 個 resource：ProfitShop / ProfitPartner / ProfitMigration（全部掛在 marketing parent）。⚠️ 原有的第 4 個 `ProfitSettings` 全域設定頁**已整組移除**（含 3 個 REST endpoint、SettingsDto、3 個 UseCase、SettingsRepository、RewriteRulesFlusher）——4 個欄位不是零消費點（`default_rate` / `page_template`），就是「有消費點但站長改了只會弄壞東西」（`report_slug` 改了讓已發出的報表網址全失效；`rewrite_slug` 控制的 `/powershop/{post-name}/` 是條沒有任何程式碼連過去、打開只有 `post_content` 的半殘網址）。rewrite flush 改為 `RewriteRules::REWRITE_VERSION` 版本比對 + `init` 自動觸發，順帶修掉「全專案無 `register_activation_hook`、新站不 flush 就 404」的既有缺陷。詳見 `profit-shop.rule.md` §7
- 共用工具：`utils/profitShopExceptionMapper.ts`（12 種 ErrorCode → 友善繁中訊息）
- HIGH-RISK 元件目前只剩 **ImportModal**（不可逆 import）。原有的 `RegeneratePasswordButton` 與 `PasswordSection` 已於 2026-08 隨「夥伴密碼改為一般欄位」刪除（見 `profit-shop.rule.md` §6「明文密碼並存」與 `react.rule.md` §6.5）；`ResetButton` 已隨設定頁移除
- **Partner 密碼共五條寫入路徑**（建立 / **更新** / 站長指定 / 重新產生 / 夥伴自助），規則刻意不同，改動前先對照 `profit-shop.rule.md` §6「五條密碼路徑的分工」表。全部經過 `PartnerTermRepository::save()` 這個唯一出口；⚠️ partner endpoint 的 body 一律走 `read_json_body_for_partner()`（`PARTNER_RAW_FIELDS`），用錯成 `read_json_body()` 會靜默改寫密碼字面值
- 防 refetch 覆寫 pattern：`filledIdRef` + `queryOptions.refetchOnWindowFocus: false`（4-A1 起沿用）
- （ProfitSettings 的 `filledRef` + `lastFilledSettingsRef` 雙保險 pattern 隨該頁移除，但「單例資源、無 id 可比對」時仍應用 content-diff，見 `react.rule.md`）
- npx tsc --noEmit baseline 不變，pnpm lint 全綠，既有 SPA（Product / Order / Users）零 regression

### Phase 4-B（Partner self-service portal，獨立 SPA）已完工（commits `bc3ea5c` / `7e40969` / `23e76e9`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-B1 | `bc3ea5c` | Partner portal Skeleton：PartnerPortalRenderer（PHP `template_redirect` priority 9，攔 query var `profit_partner_report`，partner term 不存在 → 404，存在 → 輸出**獨立** HTML 骨架，**不**走 `get_header` / `wp_head` / `get_footer`）+ Vite multi-entry（v4wp `input: string[]` 同時打 admin 與 partner-portal 兩個 bundle）+ Auth 系統（axios `withCredentials` + 401 interceptor + sessionStorage 只存 metadata 不存 token + AuthContext status 三態 + 跨 partner 雙保險：`status='loading'` masking + partner 物件遮蔽 null）+ Login 頁（rate-limit `Retry-After` parse + cooldown 倒數 + `partnerExceptionMapper` fallback 到 admin `profitShopExceptionMapper`） | 15 檔（+990） |
| 4-B2 | `7e40969` | Partner portal Dashboard 殼：DateRangeFilter（5 preset：本月 / 上月 / 近 7 天 / 近 30 天 / 自訂，dayjs + Segmented + RangePicker）+ KPI 4 卡（總銷售 / 待結算 / 已結算 / 已退款，mobile stack）+ LoadingScreen + ErrorBoundary（**partner-portal 唯一 class component**）+ `api/reports.ts`（`fetchKpi`，**partner_term_id 永不出現在 query**，後端 token 鎖死）+ `useKpi` typed `useQuery<TKpiOutput, AxiosError>` | 8 檔（+571） |
| 4-B3 | `23e76e9` | Partner portal Trend + Settlements + 收尾：TrendChart（echarts 折線圖，**callback ref pattern** 取代 `useRef` + `useEffect` 解決 reviewer C-1 race condition）+ SettlementsTable（desktop / mobile 響應式 + status filter + `formatAmount`）+ `useTrend` / `useSettlements`（typed `useQuery`）+ `apiClient` baseURL 內建（`readPartnerEnv` 純函式，免每次傳 `apiUrl`）+ `useAuth.ts` 拆兩層 re-export 簡化（直接 import `AuthContext`）+ Vite manifest fallback（PartnerPortalRenderer `render_maintenance` 503 + `nocache_headers`，防 manifest 缺 entry 看到空白頁）；reviewer 二輪 PASS（C-1 echarts init / m-1 echarts chunk dedup 自承不準 / m-2 / n-3 全清） | 14 檔（+~840） |

**Phase 4-B 完工要點**：
- 完整 partner self-service portal：`/profit-report/{slug}/` URL + 獨立 SPA bundle（mount 點 `#profit_partner_portal`，與 admin 完全隔離）
- HashRouter（與 admin 一致，避免 BrowserRouter 與 RewriteRules 衝突）+ Login + Dashboard（KPI / Trend / Settlements 三 widget）
- HTTP-only cookie auth + sessionStorage metadata（**永不存 token**，XSS 防護）
- 跨 partner 雙保險（status `'loading'` 而非 `'authenticated'` + partner 物件 mismatch 遮蔽 null）
- 401 interceptor 在 React tree 之外（`window.location.hash` 跳轉，`isLoginPage` 防迴圈）
- rate-limit 倒數 UX（`Retry-After` header parse + 防呆 NaN / negative）
- echarts callback ref pattern（reviewer C-1 教訓：dispose race + ref attach timing）
- Vite manifest fallback maintenance 503（reviewer L-3 修補）
- partner bundle 隔離驗收：grep `App1` / `Refine` / `@refinedev` / `dataProviderName` / `wc-rest` / `wp-rest` / `X-WP-Nonce` 全部 0 命中
- 不引入新 npm 套件（複用 admin 既有 antd / react-query / axios / antd-toolkit `simpleDecrypt` / dayjs / echarts）
- 不動 admin SPA 任何檔案 / 6 個 admin dataProvider 配置
- pnpm build / lint / tsc baseline 維持，composer lint 全綠

### Phase 4-C（賣場前台 + AddToCart 端到端整合）已完工（commits `2d6a76a` / `f813e07`）

| Batch | Commit | 範圍 | 檔案 |
|-------|--------|------|------|
| 4-C1 | `2d6a76a` | 賣場前台 PHP renderer + Theme 整合 template：URL `/profit-shop/{slug}/`（`RewriteRules::SHOP_QUERY_VAR='profit_shop_slug'` / `SHOP_REWRITE_PREFIX='profit-shop'` 常數，priority 'top'）+ `ProfitShopRenderer`（`template_redirect` priority 9，DI 注入 `ProfitShopRepositoryInterface`，draft 預覽走 `edit_post($shop->id)` 對特定 post ownership 檢查，二輪修 HIGH-1 從 `edit_posts`）+ `find_by_slug` 補上 Repository（雙保險 trash 過濾）+ `templates/profit-shop-front.php`（走 `get_header` / `get_footer`，與 partner portal 完全脫離 theme 不同；商品 grid + simple form / variable+grouped link 雙路徑 add-to-cart + WC `<del>` / `<ins>` 原價特價 + 全 escape）+ `<title>` / `<link rel='canonical'>` SEO meta + `remove_action wp_head rel_canonical / _wp_render_title_tag` 防雙 canonical / 雙 title | 7 檔（+433） |
| 4-C2 | `f813e07` | AddToCart Hook + Cart UI 分潤標記：`Infrastructure/WooCommerce/AddToCartHook`（**priority 5，早於** Phase 3-D `CartPriceOverrideHook` priority 10）從 query string `profit_shop_id` 注入 cart_item_data；**三道閘門縱深防偽造**：(1) shop 存在 (2) `status='publish'` (3) `product_id ∈ shop->items()` O(1) lookup；只讀 `$_GET + $_POST`（**排除 `$_COOKIE`** 攻擊面，security M-1 修）；`ctype_digit` 純數字檢查（拒 `'01'` / `'+1'` / 浮點 / 負數）；short-circuit「上游已帶則 return」防 Phase 3-D IT regression；`is_admin && !wp_doing_ajax` 不掛 hook + `error_log` 過濾 `\r\n\t`。`CartItemMetaDisplay`（`woocommerce_get_item_data` filter，**一次涵蓋 mini-cart / cart page / checkout**，shop 已刪靜默不顯示但仍可結帳；URL 用 `RewriteRules::SHOP_REWRITE_PREFIX` DRY 統一來源，wordpress M-1 修；`target='_blank' rel='noopener'` 防 reverse tabnabbing）。`Loader.php` 註冊順序：4-C1 ProfitShopRenderer → 4-C2 AddToCartHook (5) → 3-D CartPriceOverrideHook (10) → CartItemMetaDisplay (999) | 3 檔（+308） |

**Phase 4-C 完工要點**：
- 端到端流程：customer 訪問 `/profit-shop/{slug}/` → 渲染商品 grid → 點加購（form action `home_url('/')` 或 permalink + `add_query_arg('profit_shop_id', ...)`） → WC add-to-cart → AddToCartHook (priority 5) 三閘門驗證並注入 cart_item_data → CartPriceOverrideHook (priority 10) 寫 4 筆 `_profit_*` meta + HMAC sign → CartItemMetaDisplay 顯示「來自賣場 XXX」 → CartPriceOverrideHook (priority 999) 結帳前驗章 + `set_price` → Order 完成 → `OrderItemSettlementRepository` 寫 partner 分潤紀錄
- 4 大攻擊情境驗證：偽造 shop_id（find null → return unchanged）/ 偽造 product_id 不在 shop（product_in_shop 拒）/ 拿正版 shop 套未授權商品（product_in_shop 拒）/ cart session 後手動改（兩 filter 同 request 同步串接，無 race）
- DRY 統一：所有 URL 前綴用 `RewriteRules::SHOP_REWRITE_PREFIX` 單一來源
- composer lint 146/146 全綠；既有 4-A / 4-B / Phase 3-D / 3-E 完全 0 regression
- 不引入新 npm / composer 套件

### Phase 6-A1（Partner 自助修密碼）已完工（commit `e974ae4`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain ValueObject | `PartnerPassword`（密碼複雜度規則：≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字；`mb_strlen($value, 'UTF-8')` 以 codepoint 計，避免 byte / multibyte 語意混亂；不 trim、不 normalize、value() 原樣回傳） | `inc/classes/Domains/ProfitShop/Domain/ValueObject/PartnerPassword.php` |
| Domain Exception | `WeakPassword`（final extends \DomainException，attr `reasons: string[]` + `getReasons()` getter；對映 422 `weak_password` + `data.reasons`） | `inc/classes/Domains/ProfitShop/Domain/Exception/WeakPassword.php` |
| Application UseCase | `ChangePasswordUseCase`（流程嚴格鎖死：assert_not_blocked → find_by_id → verify_password → new PartnerPassword → save → reset → audit log；pseudo-slug `pwchange:{id}` 與 login slug 維度隔離；弱密碼**不**污染 rate-limit） | `inc/classes/Domains/ProfitShop/Application/UseCase/Partner/Auth/ChangePasswordUseCase.php` |
| Application DTO | `ChangePasswordInput`（@internal，攜帶明文 current/new password，**不**提供 to_array）+ `ChangePasswordOutput`（success + password_changed_at，可安全序列化） | `inc/classes/Domains/ProfitShop/Application/DTO/ChangePasswordInput.php` + `ChangePasswordOutput.php` |
| Service / TokenStore | `PartnerTokenStore::verify` L138-156 同秒撤銷契約（M-1 race window 修補）：`issued_at <= password_changed_at` 視為已撤銷（從 `<` 反轉為 `<=`，閉合「同秒簽發舊 token + 同秒改密」場景） | `inc/classes/Domains/ProfitShop/Application/Service/PartnerTokenStore.php` |
| Presentation | V2Api 新增 `POST /partner-auth/change-password`（permission `partner_token`，body 不過 sanitize_text_field 保留密碼字面值；success path：Set-Cookie Max-Age=0 + Cache-Control no-store/no-cache/must-revalidate + Pragma no-cache；L-4 fail-fast `_partner_term_id <= 0` 拋 InvalidArgumentException 防污染 `pwchange:0` rate-limit）；`make_partner_login_rate_limiter()` factory 抽出（DRY，login + change-password 共用）；ExceptionMapper 加 `WeakPassword → 422 weak_password` + `data.reasons[]`（在 \DomainException fallback 之前判斷，否則被吞成 400 validation_failed） | `inc/classes/Domains/ProfitShop/Infrastructure/Rest/V2Api.php` + `Infrastructure/Rest/ExceptionMapper.php` |
| 測試 | 35 個新 test method：8 method（PartnerPasswordTest）+ 3 method（WeakPasswordTest）+ 12 method（ChangePasswordUseCaseTest）+ 11 method（PartnerChangePasswordEndpointTest IT）+ 1 method（ExceptionMapperWeakPasswordTest IT）+ 反轉 PartnerTokenStorePasswordRotationTest 同秒 case 為「已撤銷」契約 | `tests/Unit/Domain/ValueObject/` + `tests/Unit/Domain/Exception/` + `tests/Unit/Application/UseCase/Partner/Auth/` + `tests/Integration/Infrastructure/Rest/` |

**Phase 6-A1 完工要點**：
- 8 條安全紀律：(1) current_password 必驗（防 cookie 偷取後改密鎖人）/ (2) pseudo-slug `pwchange:{id}` 與 login slug 維度隔離 / (3) 改密成功後 password_changed_at 寫入 + Set-Cookie Max-Age=0 → 舊 token 立即失效（M-1 同秒覆蓋）/ (4) 弱密碼不污染 rate-limit / (5) response 不洩漏密碼 / hash / token / (6) audit log 不含明文密碼 / (7) admin nonce 不能走此 endpoint（permission `partner_token`）/ (8) Cache-Control: no-store, no-cache, must-revalidate（success path）
- M-1 race window 修補：`issued_at == password_changed_at` 同秒視為已撤銷（從 `<` 反轉為 `<=`），縱深防禦覆蓋「同秒簽發舊 token + 同秒改密」場景
- L-2 multibyte 規則：PartnerPassword 長度檢查以 codepoint（mb_strlen UTF-8）為單位，避免 ASCII vs 中文 / emoji 字元語意不一致
- L-4 fail-fast：V2Api callback 對 `_partner_term_id <= 0` 拋 InvalidArgumentException，防 permission_callback 流程缺陷被繞過時污染 `pwchange:0` rate-limit 維度
- IP 取值流程與 partner login DRY：filter_var FILTER_VALIDATE_IP 防呆 + `power_shop_partner_login_client_ip` filter hook 共用
- composer lint 全綠；既有 Phase 3-D PartnerTokenStorePasswordRotationTest 同秒 case 契約反轉為「已撤銷」（檔頭 docblock 同步）
- 不引入新 npm / composer 套件 / 不動 Phase 4-A SPA / 4-B Portal / 4-C 賣場前台

### Phase 6-A2（Partner 自助修密碼前端 UI）已完工（commit `c80b4cc`）

| 層 | 內容 | 路徑 |
|----|------|------|
| Frontend Hook | `useChangePassword`（typed `useMutation<TChangePasswordOutput, AxiosError, TChangePasswordInput>` + `mutationKey: ['partner-change-password']` + `retry: 0` 防重送） | `js/src/partner-portal/hooks/useChangePassword.ts` |
| Frontend Page ⚠ HIGH-RISK | `ChangePassword.tsx`（antd Form 三欄位：current / new / confirm；Form rule 阻擋 new === current / new !== confirm；cooldown timer + success state lock + setTimeout cleanup ref + mutation.reset；submit button `disabled = isLoading \|\| isCoolingDown \|\| success` 三重守門；成功後 `forceLogoutAndRedirect('password_changed')`） | `js/src/partner-portal/pages/ChangePassword.tsx` |
| Auth API wrapper | `changePassword(payload): Promise<TChangePasswordOutput>` + `TChangePasswordInput` / `TChangePasswordOutput` typed + 加 `X-Skip-Auth-Redirect` header（防 401 interceptor 干擾 success notification UX） | `js/src/partner-portal/auth/api.ts` |
| AuthContext helper | `forceLogoutAndRedirect(reason?: string)`（與既有 `handleLogout` 並存：前者強制 redirect 並附 query reason；後者依 `isLoginPage` 防迴圈） | `js/src/partner-portal/auth/AuthContext.tsx` |
| Routes | App.tsx 加 `/change-password` 路由（authenticated only） + Dashboard 加「修改密碼」link button 入口 | `js/src/partner-portal/App.tsx` + `pages/Dashboard.tsx` |
| Login one-shot notice | Login 頁讀 `?reason=password_changed`（KNOWN_REASONS 白名單，未來可擴 `session_expired` 等）顯示一次性 success notification | `js/src/partner-portal/pages/Login.tsx` |
| ExceptionMapper context-aware | `mapPartnerException(error, context?: 'default' \| 'change-password')` 第二參數 + `KNOWN_WEAK_PASSWORD_REASONS` 白名單 + `isKnownWeakPasswordReason` type guard；既有 Login 不傳第二參數預設 `'default'`，向後相容 | `js/src/partner-portal/utils/partnerExceptionMapper.ts` |

**Phase 6-A2 完工要點**：
- HIGH-RISK 8 條紀律（二輪後強化版）：(1) 明文密碼**只**在 antd Form state，不進 cache / storage / log / (2) 不 console.log 印密碼 / (3) submit 後 form.resetFields 分支策略（weak_password 只清 current；其他全清）/ (4) submit button 三重守門 `disabled={mutation.isLoading \|\| isCoolingDown \|\| success}` / (5) 成功後 `forceLogoutAndRedirect`（後端 password_changed_at 已撤銷舊 token）/ (6) `mutation.reset()` 清 react-query variables（防 React DevTools 窺）/ (7) Form rule 阻擋 new === current / new !== confirm / (8) setTimeout cleanup ref + useEffect unmount cleanup（防使用者中途離開後仍觸發 logout）
- `X-Skip-Auth-Redirect` header 防 401 interceptor 干擾 success notification（master 自決技術決策）
- `forceLogoutAndRedirect` 與 `handleLogout` 並存（前者強制 redirect + reason，後者依 `isLoginPage` 防迴圈）
- 失敗清欄位策略分支（MAJOR-3）：weak_password UX 友善只清 current；其他敏感全清
- success state lock（MAJOR-4，A+ 級品質）：連返回按鈕都 disabled，蓋 1.5s race
- `partnerExceptionMapper` context-aware 向後相容（Login 不傳第二參數預設 `'default'`）
- 隔離驗收（assets/main-*.js）：`App1 \| @refinedev \| Refine \| dataProviderName \| wc-rest \| wp-rest \| X-WP-Nonce` → **0 命中**
- 0 admin SPA 修改 / 0 新 npm 套件 / 0 後端變動（純前端 UI 串接 Phase 6-A1 endpoint）
- TS partner-portal 範圍 0 errors（admin baseline 129 不變）/ ESLint partner-portal 0 errors / 0 warnings / pnpm build 39.90s ✓

### Phase 7（賣場編輯頁商品選擇器重構）已完工（commit `52d7aa8`…`6c96679` 一系列）

> 上游規格：`specs/clarify/2026-07-29-profit-shop-product-selector.md` + `specs/plan/2026-07-29-profit-shop-product-selector-plan.md`（695 行，含資料流分析、錯誤/失敗處理登記表、48 條驗收清單）

| 階段 | 範圍 | 檔案 |
|------|------|------|
| 第一階段 | BUG-A 契約對齊：`types.ts` items 改巢狀（`override{regular_price,sale_price,signup_fee}` + `variations`）、`Edit/index.tsx` 填表 / 送出兩路徑對齊、`ItemsEditor` 價格欄改 2 欄（原價覆寫 / 特價覆寫）、新增 `ProfitShopHydratorTest`（Unit）+ `ProfitShopItemsRoundTripTest`（IT） | `js/src/pages/admin/ProfitShop/types.ts` + `Edit/index.tsx` + `components/ItemsEditor.tsx` + `components/ProfitShopForm.tsx` + 2 個新測試檔 |
| 第二階段 | 抽 `ProductTableBody` 共用子元件（純重構，`#/products` 零 regression）：`useProductTableQuery.ts` 抽出 `useTable` 設定、`ProductTableBody.tsx` presentational 主體、`useColumns` 加 `showActions` 參數 | `js/src/components/product/ProductTable/`（`index.tsx` 改外殼 + 2 個新檔） |
| 第三階段 | 新增 `ProductPickerModal`：`SimpleModal` 內嵌 `ProductTableBody`，跨頁勾選、三重 disabled（變體 / 已加入 / 破 100 上限）、`syncWithLocation: false` 隔離 URL 污染、選取狀態自帶 `useState`（不接 Jotai atom） | `js/src/pages/admin/ProfitShop/components/ProductPickerModal/` |
| 第四階段 | `ItemsEditor` 接上 Modal：移除舊 `useSelect` + `Select`，改「選擇商品」按鈕；`handleBatchAdd` 雙層 dedupe + 100/500 上限提示 | `js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx` |
| 第五階段 | 商品顯示補齊：`useShopItemProducts`（`wc-rest` `include=` 分 5 chunk，每 chunk 100）+ `_enrichState` 四分支降級顯示（ok / missing / failed / pending） | `js/src/pages/admin/ProfitShop/hooks.ts` |

**Phase 7 完工要點**：
- **BUG-A 修正**：前端過去送後端從未定義的扁平欄位 `price_override` / `variation_id`，PHP 只讀巢狀 `override` / `variations` → 使用者設定的價格覆寫存檔後消失。**PHP 端零產品程式碼變更**——`ProfitShopHydrator` / `CptProfitShopRepository` / `ItemValidator` 本來就已經讀寫巢狀契約，成因 100% 在前端；PHP 端工作只有補測試鎖契約（`ProfitShopHydrator` 先前零測試覆蓋，是 BUG-A 能潛伏數個 Phase 的根本原因）。詳見 `.claude/rules/profit-shop.rule.md` §7
- `ProductTableBody` 共用子元件：`useTable` 刻意留在 caller（shell 或 Modal），不移進 body——shell 需要 `searchFormProps` / `tableProps.dataSource` 給 Jotai 同步 effect 用，這個設計讓 `#/products` 的 diff 是純 JSX 搬移，是零 regression 最強的保證
- `ProductPickerModal` 三重 disabled：`isVariation(record.type) || existingIds.has(Number(record.id)) || (selected.length >= 100 && !isSelected)`；跨頁勾選以 `record` 物件累積（不依賴當前頁 `dataSource`），關閉 Modal 時 reset
- 100 筆單次批次上限（硬）、500 筆賣場總數軟上限，皆僅前端擋，後端無對應數量驗證（記為技術債，見 `specs/tech-debt.md`）
- 全域閘門：實測數據見下方「驗證數據」表（tsc / eslint / build / 既有 SPA regression 全數確認）
- 專案無前端單元測試框架（vitest / testing-library），UI 行為以型別 + lint 閘門 + 48 條手動驗收清單把關；BUG-A 契約回歸另補 API-level e2e spec（`tests/e2e/06-profit-shop/profit-shop-items.spec.ts`，沿用既有 `request.put` + `X-WP-Nonce` pattern，非 UI 驅動）
- 過程中發現並修正 3 個 unrelated 環境問題（隨手修，非本次 scope 但擋路必須先解決）：`main.tsx` 缺 `<AntdApp>` provider（第四階段發現，詳見 `react.rule.md` §2）、`.wp-env.json` core 版本釘住、`tests/bootstrap.php` Abilities API polyfill 時序（後兩者詳見 `.claude/rules/wordpress.rule.md` §11）
- 13 條踩坑知識分別寫入 `react.rule.md`（元件架構 + SimpleModal / AntdApp provider / wc-rest 分頁三條踩坑）、`admin-list-ui.rule.md`（Modal 情境必須關閉的元素清單）、`wordpress.rule.md`（測試環境 5 條）、`specs/tech-debt.md`（跨領域已知問題，含既有 21 個 pre-existing integration 測試失敗、`phpstan analyse inc` 在 HEAD 就跑不起來等，皆與本次改動無關）、Common Pitfalls #17（`agent-browser` 截圖絕對路徑）
- 不引入新 npm / composer 套件；不動 Domain 層（`PriceCalculator` / `CartPriceOverrideHook` / `OverrideItem` Entity / `PriceOverride` VO 一律未改）

**驗證數據**（實測，2026-07-29）：

| 層級 | 結果 | 說明 |
|------|------|------|
| Unit | 338 / 669 assertions / 1 failure | 327 baseline + 11 新增（`ProfitShopHydratorTest`）；failure 是 pre-existing 的 `LoginRateLimiterIpDimensionTest`（見 `specs/tech-debt.md` TD-2） |
| Integration | 168 / 723 assertions / 21 failures | 158 baseline + 10 新增（`ProfitShopItemsRoundTripTest`）；21 個全部 pre-existing（見 `specs/tech-debt.md` TD-1） |
| e2e `06-profit-shop` | 4 passed | 新增（`profit-shop-items.spec.ts`） |
| e2e `01`～`05` | 162 passed（27.6 分鐘） | 既有，零 regression |
| `npx tsc --noEmit` | 127 | baseline 129，降 2（刪除死 prop `cardProps` 順帶消掉既有 unused 錯誤） |
| `npx eslint "js/src/**/*.{ts,tsx}"` | 0 errors / 135 warnings | baseline 0 errors / 136 warnings |
| `pnpm build` | 成功 | 各階段 37.94s ～ 48.62s |
| `git diff --name-only -- inc/` | 0 | PHP 產品程式碼零修改，BUG-A 純前端成因的直接證據 |

BUG-A 修正有**三層鎖**：Unit 11 + Integration 10 + e2e 4，扁平契約想復活會在三個層級同時被擋下，任何一層被繞過或誤刪，另外兩層仍會抓到回歸。詳見 `.claude/rules/profit-shop.rule.md` §7。

### Phase 8（Partner 夥伴圖片）已完工（commit `52d7aa8`）

Partner 可設定夥伴圖片，顯示於分潤夥伴列表、編輯頁與賣場編輯頁的分潤設定 selector。

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain Snapshot | `PartnerSnapshot` 加 `?int $image_id` + `?string $image_url`（後者是**唯讀衍生欄位**，`save()` 一律忽略） | `Domain/Snapshot/PartnerSnapshot.php` |
| Application DTO | `PartnerInput` 加 `image_id`（`null` / `0` / 負數正規化為 null = 移除圖片）；`PartnerOutput` 加 `image_id` + `image_url` | `Application/DTO/Partner{Input,Output}.php` |
| Infrastructure | `PartnerTermRepository` 新增 termmeta `_partner_image_id` 讀寫 + `resolve_image_url()`（`wp_get_attachment_image_url($id, 'thumbnail')`）；孤兒 attachment 回 null URL 但**保留 ID**（讀取路徑零寫入副作用） | `Infrastructure/Persistence/PartnerTermRepository.php` |
| UseCase / Hydrator | `CreatePartner` / `UpdatePartner` 傳遞 `image_id`；`PartnerHydrator::to_output` 帶出 `image_id` + `image_url`。**V2Api 零修改**（`from_array` / `to_array` 自動涵蓋） | `Application/UseCase/Partner/` |
| Frontend | **直接複用商品頁 `Gallery` 元件**（加 4 個 optional props：`name` / `shape` / `title` / `allowSetThumbnail`，商品頁行為零變化）+ `PartnerForm` 圖片欄（單張圓形）+ List 夥伴圖片欄 + `PartnerSelector` 選項頭像 + `utils.ts`（欄位名對映 + `toImageId`） | `js/src/components/product/fields/Gallery/` + `js/src/pages/admin/ProfitPartner/` |
| 列表頁對齊 | 順手把 `#/profit-partner` 對齊 `admin-list-ui.rule.md` 標準版型（篩選卡片 + FilterTags + 新增按鈕移進列表卡片 + `NameId` Link 欄 + `defaultTableProps`），新增 `List/Filter.tsx`（關鍵字 / 夥伴圖片有無，client-side `useMemo` 過濾） | `js/src/pages/admin/ProfitPartner/List/` |
| OpenAPI | `PartnerInput.image_id` / `PartnerOutput.image_id` + `image_url` schema | `specs/api/api.yml` |
| 測試 | Unit +2（`DtoRoundTripTest`：image_id 正規化 / output null 契約）、IT +3（`PartnerTermRepositoryTest`：持久化只寫 ID / null 清除 termmeta / 孤兒 attachment 保留 ID） | `tests/Unit/Application/DTO/` + `tests/Integration/Infrastructure/Persistence/` |

**Phase 8 完工要點**：
- **只持久化 `image_id`，`image_url` 是唯讀衍生欄位**——換網域 / CDN 時 URL 會失效，attachment ID 不會。IT 刻意傳入假 URL 鎖死 `save()` 忽略它的行為（詳見 `profit-shop.rule.md` §7「Partner 夥伴圖片」）
- 「`image_id` 非 null 但 `image_url` 為 null」是**合法狀態**（attachment 被刪除），前端退回預設頭像 / 讓站長重挑，而非顯示破圖
- `PartnerSelector` 的 `label` 變 ReactNode 後 `optionFilterProp="label"` 失效，名稱搜尋靠 option 上的純字串 `name` / `slug` + 自訂 `filterOption`（見 `react.rule.md` §6.5）
- V2Api / ExceptionMapper / Domain Service / Auth 全部零修改；不引入新 npm / composer 套件
- **驗證數據**：Unit 340 / 677 assertions / 1 failure（338 baseline + 2 新增，failure 為 pre-existing TD-2）；Integration 171 / 742 assertions / 21 failures（168 baseline + 3 新增，21 failures 全 pre-existing TD-1）；`npx tsc --noEmit` 127（baseline 127）；`npx eslint` 0 errors / 135 warnings（baseline 同）；`composer lint` 154/154 全綠；`pnpm build` 成功

### Phase 9（賣場商品變體層價格 / 灌水人數覆寫）已完工（commit `ab6d0e3` + `381d8b2`）

> 上游需求：`#/profit-shop/edit/{id}` 的「賣場商品」表格，`variable` / `variable-subscription` 商品在**商品圖片左側**顯示展開鈕，點開後可對每個變體覆寫價格與灌水購買人數。
> 實作計劃：`specs/plan/2026-07-30-profit-shop-variation-override-plan.md`（含資料流分析、錯誤 / 失敗處理登記表、8 個階段）

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain ValueObject | **新增** `VariationOverride`（`PriceOverride` + `InflatedCount` 兩參數皆必填，`to_array()` 輸出 `{override, inflated_count}`；刻意不把 `inflated_count` 塞進 `PriceOverride`，避免污染它在 `PriceCalculator` / `ProductSnapshot` 的其他用途） | `Domain/ValueObject/VariationOverride.php` |
| Domain Entity | `OverrideItem::$variations` 型別升級為 `array<int, VariationOverride>`；`get_variation_override(int): ?PriceOverride` **簽章不變、改為委派**（`return ( $this->variations[$vid] ?? null )?->override`）；新增 `get_variation_inflated_count()`；`set_variation_override()` 加可選第三參數；建構子加 fail-fast 型別守衛（非 `VariationOverride` 值立即拋 `\InvalidArgumentException`） | `Domain/Entity/OverrideItem.php` |
| Infrastructure（新層） | **新增** `SingleProductPriceHook`：WC 單品頁套用賣場覆寫價（含變體），解決「單品頁一個價、購物車另一個價」的價格跳動；`template_redirect` priority 20，只在 `is_product()` 且網址帶合法 `profit_shop_id` 時才註冊 filter；沿用 `AddToCartHook` 三道閘門（shop 存在 / `status=publish` / product 屬於該 shop）；掛 `woocommerce_product_get_price` 系列（單一變體實際結帳價）+ `woocommerce_variation_prices_*` 系列（價格區間，因 WC 讀值走 `'edit'` context 而需另掛）+ 改寫 `woocommerce_get_variation_prices_hash` 併入 shop_id（防跨賣場快取污染） | `Infrastructure/WooCommerce/SingleProductPriceHook.php` |
| Application / Persistence | `ProfitShopHydrator::build_override_item()` + `CptProfitShopRepository::build_override_item()` 的 variations 迴圈改建 `VariationOverride`；反序列化對缺 `inflated_count` 鍵的舊資料一律 `?? 0`（唯一向後相容接住點，不需 migration） | `Application/UseCase/Shop/Support/ProfitShopHydrator.php` + `Infrastructure/Persistence/CptProfitShopRepository.php` |
| 前台顯示 | 商品層 `inflated_count` 首次接上賣場前台：`get_total_sales() + inflated_count` > 0 時顯示「已售 N 件」；變體層 `inflated_count` 暫無顯示位置（見 `specs/tech-debt.md` TD-15） | `Infrastructure/WordPress/templates/profit-shop-front.php` |
| Loader | 註冊 `SingleProductPriceHook::instance()` | `Loader.php` |
| OpenAPI | `OverrideItemInput.variations.additionalProperties` 加 `inflated_count`（`integer`, `default: 0`, `minimum: 0`），description 同步標註向後相容與 TD-15 | `specs/api/api.yml` |
| Frontend 型別 / 填表 | `TVariationOverride` 加 `inflated_count: number`；`Edit/index.tsx` 填表時逐筆正規化 `variations`（補齊 `override` 三欄 + `inflated_count: 0`，與商品層同一套理由：DB 可能回缺欄位的舊資料） | `pages/admin/ProfitShop/types.ts` + `Edit/index.tsx` |
| Frontend Hook | **新增** `useProductVariations(productId, enabled)`：走 `wc-rest` `GET /products/{id}/variations`，`per_page` 當 filter 送（§3 陷阱）；**刻意設計成由展開列的子元件呼叫**，不在 `ItemsEditor` 那一層查，靠 rc-table `expandedRowRender` 天然 lazy 避開 N+1 與 Rules of Hooks 限制 | `pages/admin/ProfitShop/hooks.ts` |
| Frontend 共用工具 | **新增**（計劃外，master 自決）`utils.ts`：`emptyPriceOverride` / `emptyVariationOverride` / `toInputValue` / `toPriceString` / `isSaleOverRegular` 五個純函式，供 `ItemsEditor`（商品層）與 `VariationOverrideEditor`（變體層）共用，避免兩份實作讓「留空 vs 填 0」語意分歧 | `pages/admin/ProfitShop/utils.ts` |
| Frontend 元件 | **新增** `VariationOverrideEditor`：展開列內容，4 欄變體子表格（變體 / 目前價格 / 覆寫價格 / 灌水購買人數）+ 孤兒鍵處理（賣場存了某 variation_id 但 WC 已無此變體，顯示「變體已失效」+ 移除鈕，不可靜默隱藏）+ R-1 據實揭露 `<Alert>` | `pages/admin/ProfitShop/components/VariationOverrideEditor/index.tsx` |
| Frontend 表格改版 | `ItemsEditor`：`showExpandColumn: false` 關掉 antd 預設最左展開欄，改在「商品名稱」欄自刻展開鈕（滿足「圖片左側」需求）；受控 `expandedRowKeys`；`rowExpandable` 限定 `isVariable(product?.type)`；三個變體 patch handler（最小重建範圍：只重建該商品那一列 + 該變體那一筆）；`validateItemsPriceOverride` 擴充涵蓋變體層（訊息同時指出商品與變體）；換頁 / 移除商品時同步清理 `expandedKeys` | `pages/admin/ProfitShop/components/ItemsEditor.tsx` |
| 測試 | 5 個 PHP 測試檔異動：`OverrideItemTest`（+8）、`ProfitShopHydratorTest`（+4）、`CptProfitShopRepositoryTest`（+2）、`ProfitShopItemsRoundTripTest`（+5）、**新增** `SingleProductPriceHookTest`（11）；`PriceCalculatorTest` 的 `make_item()` helper 改用 `array_map` 包 `VariationOverride`，15 個呼叫點斷言不變 | `tests/Unit/` + `tests/Integration/` |

**Phase 9 完工要點**：
- **委派法把破壞面壓到最小**：`OverrideItem::get_variation_override()` 簽章維持 `?PriceOverride` 不變，`PriceCalculator` / `CartPriceOverrideHook` / `ItemValidator` / `V2Api` / `AddToCartHook` **五檔零修改**（`git diff --stat` 完全空白），只有 `OverrideItemTest` 需要新增測試鎖住「委派後回傳型別不漂移」這個 invariant（`assertNotInstanceOf`）；**真實環境 `wp eval` readback 已人工驗證**回傳 class 確實是 `PriceOverride`，簽章契約在真實執行環境成立
- **R-1 CRITICAL GAP 已解決，且已人工端到端驗證**：規劃階段發現「變體覆寫價只在購物車生效，WC 單品頁沒有價格 filter」會讓顧客看到「單品頁一個價、購物車另一個價」的價格跳動，一旦開放變體覆寫 UI 就會變成使用者可見的信任問題。`SingleProductPriceHook`（第六-B 階段）補上這塊，且刻意收斂作用範圍（只在 `is_product()` 且帶合法 `profit_shop_id` 時才註冊 filter），不影響購物車 / 結帳既有流程。真實環境測試確認：單品頁價格區間隨變體覆寫位移（`42–45` → `45–888`）、逐變體 `display_price` / `display_regular_price` 正確、**負向驗證**（不帶 query string 時價格與 hash 皆完整回到原始值，零殘留）、賣場卡片 / 單品頁 / 購物車三處價格一致（皆為 888）。詳見 `profit-shop.rule.md` §7「SingleProductPriceHook」
- **R-2 部分解決，商品層已人工驗證**：商品層 `inflated_count` 首次接上賣場前台顯示（此前是「存得進去、讀得出來、沒人用」的反模式），真實環境實測「已售 880 件」＝真實銷量 873 + `inflated_count` 7，數字精確吻合；一般賣場 `test-shop-alpha` 亦實測正確（含 `> 0` 守門）。變體層因為賣場前台只渲染父商品卡片、變體選擇整段發生在 WC 單品頁，暫無顯示位置，前端已誠實揭露（Tooltip 文案刻意與商品層不同，見 `react.rule.md` §6.5），登記 `specs/tech-debt.md` TD-15
- **rc-table 內部行為逐行驗證後才動手**：`showExpandColumn: false`、展開列不帶 `data-row-key`（與既有 dnd-kit 拖曳排序零衝突）、`expandedRowRender` 天然 lazy 且收合不 unmount——三條都是先讀 `rc-table` 原始碼才確認可行，不是憑經驗猜的，詳見 `react.rule.md` §6.5「`ItemsEditor` 變體展開列」
- **舊資料向後相容零 migration**：`CptProfitShopRepository` 對缺 `inflated_count` 鍵的舊 `variations[vid]` 一律 `?? 0`，下次儲存自然補齊；兩條 IT 測試鎖住這個行為（缺鍵讀為 0 且價格仍讀得出來、重新儲存後自然升級格式）
- **三層錯誤處理分工延續 Phase 7 慣例**：前端守門（批次上限、Form rule）+ 後端 Domain 驗證（`ItemValidator` + `PriceOverride` VO，唯一真相，不因前端已擋就放寬）+ 降級不阻斷（變體補齊查詢失敗只影響顯示，不影響編輯與儲存）
- 過程中發現並登記數個非本次 scope 但值得記錄的觀察：`composer lint` 在 HEAD 就不綠（成因是未追蹤的 `.claude/tmp/` 暫存目錄被 phpcs 掃到，`vendor/bin/phpcs inc/` 才是有意義的閘門，TD-18）、`memo(VariationOverrideEditor)` 因 inline callback 而實際不生效（TD-19，非正確性問題，效能觀察）、`CartItemMetaDisplay` 的 item_data 在 WooCommerce Cart Block / Store API 路徑下顯示 `undefined:`（TD-21，與 Phase 9 無關，人工驗證時發現）
- **一個差點漏掉、已當場修正的文案落差**：`VariationOverrideEditor` 的 R-1 據實揭露 `<Alert>` 文案是規劃階段（`SingleProductPriceHook` 是否要做尚未拍板時）寫的，`SingleProductPriceHook` 後來確定要做且做完之後，那句「單品頁仍顯示原價」就與事實相反了，但沒有人回頭改。真實環境端到端驗證（單品頁區間 `42–45`→`45–888`）才抓到這個落差，team lead 已即時修正文案；已解決的原委與教訓見 `specs/tech-debt.md`「已解決」章節的 TD-20，同型失誤模式已寫進 `profit-shop.rule.md` §7「`SingleProductPriceHook`」章節（往後任何改動 `SingleProductPriceHook` 作用範圍時都要對照檢查相關 UI 文案）
- 不引入新 npm / composer 套件；不動 `PriceCalculator` / `CartPriceOverrideHook` / `ItemValidator` / `V2Api` / `AddToCartHook`；`ProductPickerModal` 的「變體不可勾選」邊界不受影響（變體覆寫走 `ItemsEditor` 展開列，是不同的操作入口）

**驗證數據（自動化）**（實測，2026-07-30；baseline 以**動工前實測**為準，非沿用 Phase 8 文件記載的數字——兩者 failure 數 1 / 21 完全吻合，僅 pass 數有出入，判斷是先前記錄時機差異，不影響回歸判斷）：

| 層級 | Baseline（動工前實測） | 完成後 | 判定 |
|---|---|---|---|
| Unit | 341 tests / 670 assertions / 1 failure | **353 / 694 / 1** | +12 tests，failure 未增加（TD-2 pre-existing） |
| Integration | 173 / 754 / 21 failures | **191 / 850 / 21** | +18 tests，failure 未增加（TD-1 pre-existing），21 條失敗名稱與順序與 baseline 完全相同 |
| `vendor/bin/phpcs inc/` | 136/136 綠 | **138/138 綠** | +2 新檔 |
| `npx tsc --noEmit` | 127 | **127** | 持平 |
| `npx eslint "js/src/**/*.{ts,tsx}"` | 0 errors / 135 warnings | **0 errors / 135 warnings** | 持平 |
| `pnpm build` | 成功 | **✓ 38.70s** | 成功 |

`@group` 已逐檔驗證（`--filter XxxTest --testdox` 筆數 == 檔內 `test_` 方法數），無靜默跳過。

**驗證數據（真實環境人工端到端驗證，2026-07-30，team lead 執行）**——這是唯一直接在瀏覽器 / `wp eval` 操作真實站台的驗證，性質與上面的自動化閘門不同；⚠️ **請勿誤讀為「全部通過」**，第五階段的 admin UI 手動清單仍未執行，見表格最後一列：

**① 第六階段：商品層 `inflated_count` 前台顯示 —— ✅ 已驗證**

| 賣場 | 商品 | 真實銷量 | `inflated_count` | 前台顯示 |
|------|------|----------|-------------------|----------|
| 臨時測試賣場 | Hoodie（商品 63） | 873 | 7 | 「已售 **880** 件」＝ 873 + 7，數字精確吻合 |
| `test-shop-alpha` | `[E2E] Test Product` | — | — | NT$800 劃掉 → NT$600 +「已售 4 件」 |
| `test-shop-alpha` | `銷售方案` | — | — | NT$10,017 → NT$200 +「已售 2 件」 |
| `test-shop-alpha` | `T-Shirt` / `E2E Frontend Test Course` | — | 0 | **未顯示**「已售 0 件」（`> 0` 守門正確） |

`<title>` SEO meta 亦確認正確：「測試賣場 Alpha｜測試夥伴 Alpha 的分潤賣場 - turbo」。

**② 第六-B 階段：`SingleProductPriceHook`（單品頁變體價格）—— ✅ 已驗證**

臨時賣場設定：商品 63 Hoodie，變體 79 覆寫 `999/888`、變體 86 覆寫 `777`（不設特價）、變體 80/81 不覆寫。

- **Repository 讀回**（`wp eval`）：`vid=79 override=999/888 inflated=3 [override class: PriceOverride]`——委派法簽章契約在真實環境成立（回傳的是 `PriceOverride`，不是 `VariationOverride`）
- **單品頁價格區間**：帶 `?profit_shop_id` 從 `NT$42–NT$45` 變為 `NT$45–NT$888`（區間端點位移是 filter 生效的指紋）
- **逐變體 `get_available_variations()`**：79→`888/888`（原/特價 999/888）、86→`777/777`（只設原價，未渲染出「原價劃掉→特價」假象）、80/81→`45/45`（未覆寫，維持原樣）
- **負向驗證（最關鍵）**：**不帶** `?profit_shop_id` 時價格與逐變體價格**全數回到原始值**（`42–45`），零殘留——同時證明 filter 只在帶合法 query string 時才註冊（不常駐），且 `woocommerce_get_variation_prices_hash` 改對了（沒改的話會讀到 `45–888` 的污染快取）
- **三處價格一致性**：賣場卡片 → 單品頁（888）→ 加購變體 79 後 Store API `/wc/store/v1/cart` 的 `price` / `line_total` / `total_price` 皆為 `"888"`

**③ 第五階段：`ItemsEditor` 展開列 admin UI 手動驗收清單（計劃書列的 11 條）—— ⏳ 尚未驗證**

需登入 `wp-admin` 操作才能驗證：`+` icon 位置在商品圖片左側、展開/收合不重查（Network 面板確認）、
拖曳排序（含展開狀態下）、換頁 / 換每頁筆數清空展開狀態、移除已展開商品無 console error、
100 筆商品輸入無明顯延遲、驗證訊息切到出錯 Tab 等。這條清單需要使用者本人操作 wp-admin
（agent 不會代為輸入登入密碼），本輪未執行，**不要當作已通過**。

驗證①②所用的臨時賣場與購物車項目**已全部清理**，站台資料已還原至驗證前狀態（剩餘 powershop
只有既有的 #2225 `123` 與 #1932 `test-shop-alpha`）。

### Phase 10（賣場前台 UI 照搬 legacy 一頁商店）已完工（commit `ff81dbb`，即 `issue/L3` 原本的 Phase 9）

站長回報新版賣場前台（`/profit-shop/{slug}/`）與舊版一頁商店（`/power-shop/{slug}/`）體驗差距過大——舊版點「加入購物車」會彈出商品 Modal（左圖右詳情 + 庫存 + 已購人數 + 數量選擇器），新版只有裸 grid + 直接送 form。本 Phase 把前台 UI 與功能**完全照搬**舊版。

**關鍵突破：舊版原始碼從 source map 完整還原。** `legacy/js/dist/assets/*.js.map` 的 `sourcesContent` 內含 76 個原始 TSX 檔案，其中 `App3` chunk 就是舊版前台一頁商店的完整實作（`PowerShopProducts` / `ProductModal` / `StockInfo` / `BuyerCount` / `ProductVariationsSelect` / `Gallery` / `Price` / `PlusMinusInput` / `Cart`）。這讓「照搬」有了逐行比對的黃金依據，而非憑截圖重刻。

**同時推翻兩個前提誤解**：舊版前台**不是** jQuery + jquery-confirm（`legacy/inc/assets/js/main.js` 只有 72 行且是**後台**授權碼推銷彈窗，jquery-confirm 在前台是死碼），而是 **React 18 + Ant Design 5 + jotai + react-query**；`.ps-btn` / `.ps-cart-table` 等樣式也不在 `legacy/inc/assets/css/`，而在 `legacy/js/dist/assets/useAjaxNonce-*.css`。

| 層 | 內容 | 路徑 |
|----|------|------|
| PHP payload | `ProfitShopFrontData`：ProfitShop + WC 商品 → 前端 payload。欄位名刻意用 legacy camelCase（`regularPrice` / `extraBuyerCount` 等）讓 React 能 1:1 照搬；圖片查詢批次 `_prime_post_caches` 避免 N+1 | `Infrastructure/WordPress/ProfitShopFrontData.php` |
| PHP AJAX | `FrontAjax`：`nonce` / `add_cart` / `remove_cart` / `update_cart` 四個 admin-ajax action。**只呼叫 `WC()->cart->add_to_cart()`，不自行寫價格或分潤 meta**（⚠ **Phase 11 已刪除**，改為 `FrontCartApi` 自訂 REST，見下方 Phase 11） | `Infrastructure/WooCommerce/FrontAjax.php`（已刪除） |
| PHP template | 照搬 legacy 卡片版型（`grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-8` + `div[data-ps-product-id]` + `group-hover:scale-125` + `del` 原價 / 紅字特價 + variable 區間價）+ `<noscript>` 降級 + 站長自訂內容區（`the_content`） | `Infrastructure/WordPress/templates/profit-shop-front.php` |
| PHP Renderer | `enqueue_front_bundle()`：Vite entry + localize；manifest 缺 entry → 降級為 Phase 4-C1 的表單加購（不白頁） | `Infrastructure/WordPress/ProfitShopRenderer.php` |
| React bundle | **第三個 Vite entry** `js/src/profit-shop-front/`（22 檔）：ProductModal 全套 + Cart 表格 + 9 顆 jotai atom + 雙通道 api（admin-ajax 寫入 / Store API 讀取與運費，⚠ **Phase 11 已改為單一自訂 REST 通道**，見下方 Phase 11） | `js/src/profit-shop-front/` |
| 測試 | `ProfitShopFrontDataSettingsTest`（12 method / 47 assertion，純 PHP）：settings 正規化 + CSS injection 白名單 + 壞時間戳不誤關店 | `tests/Unit/Infrastructure/WordPress/` |

**Phase 10 完工要點**：
- **架構是混合式而非二選一**：PHP 印商品 grid（SEO 內容留在 HTML）+ React 只掛 Modal / 購物車互動層（購物車那半已於 Phase 12 移除）。這是 legacy 的原始架構，同時滿足 Pitfall #12 的三個理由與「UI 完全一致」。Pitfall #12 因此**精確化**而非推翻
- **hook 鏈零修改**：`AddToCartHook`(5) 三閘門 + `CartPriceOverrideHook`(10/999) HMAC 簽章完全沒動。三個 cart hook 的 admin guard 都是 `is_admin() && ! wp_doing_ajax()`，在 admin-ajax context 仍會掛載，所以 AJAX 加購自動繼承全部安全機制
- **`ProfitShop::$settings` 通道啟用**：settings 端到端本來就通（Repository / Hydrator / DTO / V2Api），只是沒人讀。前台現在讀 legacy 相同的 key，缺值 fallback 預設 → 後台補上設定 UI 後前台零修改即生效（後台 UI 缺口見 TD-22）
- **順手修掉 6 個 legacy 真實缺陷**（不是照搬 bug）：`useEffect` 無效 cleanup（`return` 寫在 `forEach` callback 內）/ `Gallery` hooks 順序違規 / 加購失敗 rollback 對變體漏刪 / 樂觀項目短描述欄位名錯 / variable 卡片價格 `(int)` 截斷小數 / `shopStatus` 改由伺服器時間計算
- **CSS injection 修補**：legacy 的 `btnColor` 是 `echo $btn_color` 直接進 `<style>`，新版走 hex 白名單（有測試鎖住）
- 不動 Domain 層；不引入新 composer 套件；新增 1 個 npm 套件（`canvas-confetti`，照搬加購彩帶）

**驗證數據**（實測，2026-07-30）：

| 層級 | 結果 |
|------|------|
| `npx tsc --noEmit` | 127（baseline 127），`js/src/profit-shop-front/` 貢獻 **0** |
| `npx eslint "js/src/profit-shop-front/**"` | 0 errors / 0 warnings |
| `phpcs inc/`（`composer lint`） | 138/138 全綠 |
| Unit（新增） | `ProfitShopFrontDataSettingsTest` 12 tests / 47 assertions 全綠 |
| `pnpm build` | 成功 44.56s；manifest 三個 entry 齊全 |
| bundle 隔離 | entry chunk grep `App1` / `@refinedev` / `Refine` / `dataProviderName` / `wc-rest` / `X-WP-Nonce` → **全部 0 命中** |
| Tailwind | template 用的 class 全數產出；斷點 810 / 1080 / 1280 與 legacy 完全一致 |
| bundle 大小 | entry 41.2 kB + CSS 3.9 kB（共用 chunk 與其他兩個 bundle dedup） |

**已知落差**（已登記 `specs/tech-debt.md`）：TD-22 前台設定無後台 UI／TD-23 per-variation 灌水人數／TD-24 倒數計時器未移植／TD-25 legacy `enableVirtualList` 本身就壞（刻意不移植）／TD-26 Modal 在 992–1079px 版型破碎（照搬 legacy 缺陷）／TD-27 庫存數量會被 CDN 快取／TD-28 Elementor 版型不生效／TD-29 `build_products` 與 `FrontAjax` 待補 IT（Phase 11 起對象改為 `FrontCartApi`，見下方）。

### Phase 11（賣場前台購物車改自訂 REST）已完工（commit `ff81dbb`，即 `issue/L3` 原本的 Phase 10）

Phase 10 的前台購物車是「寫入走 admin-ajax（`FrontAjax`）、讀取與選運費走 WC Store API」的雙通道，代價是同一台購物車要處理兩套回應形狀、兩套錯誤碼、兩套 nonce 機制（`power_shop_front` 與 `x-wc-store-api-nonce`），前端得各寫一份處理。本 Phase 把兩邊合而為一，全部收斂到本外掛自有的 REST namespace：`/wp-json/power-shop/profit-shop-cart*`。

| 層 | 內容 | 路徑 |
|----|------|------|
| PHP 新增 | `FrontCartApi`（`final class ... extends ApiBase`，`namespace = 'power-shop'`）：6 個 endpoint（`GET nonce` / `GET`（讀取整台購物車）/ `POST add-item` / `POST update-item` / `POST remove-item` / `POST select-shipping-rate`），全部 `permission_callback => '__return_true'`（customer-facing，未登入訪客必須能加購，防護落在 nonce 與既有 hook 鏈的三道閘門上） | `Infrastructure/Rest/FrontCartApi.php` |
| PHP 刪除 | `FrontAjax`（admin-ajax 的 `nonce` / `add_cart` / `remove_cart` / `update_cart` 四個 action 全數移除） | （已刪除） |
| PHP 修改 | `Loader.php`（`FrontAjax::instance()` → `FrontCartApi::instance()`）；`ProfitShopFrontData.php`（`env.nonce` 改為 `wp_create_nonce('wp_rest')`，新增 `env.cartApiUrl`，移除 `env.ajaxUrl` / `env.restUrl`） | `Loader.php` + `Infrastructure/WordPress/ProfitShopFrontData.php` |
| 前端 | `js/src/profit-shop-front/api.ts` 改為單一 axios instance（`baseURL = cartApiUrl`、`withCredentials`、`Content-Type: application/json`、一律送 `X-WP-Nonce`）；型別 `TStoreApi*` → `TFront*`；移除 `storeApiNonceAtom`；`variation` 改送物件陣列（不再 `JSON.stringify`） | `js/src/profit-shop-front/api.ts` + `types.ts` |

**三個關鍵知識（這次踩到、確立進 `react.rule.md` §6.7 與 `wordpress.rule.md` 的部分）**：

1. **`$_POST` 橋接是分潤三閘門的生命線**：`AddToCartHook::extract_shop_id_from_request()` 只讀 `$_GET` / `$_POST`，REST 送 JSON body 時 PHP **不會**填 `$_POST` → shop_id 取不到 → 靜默走商品原價、分潤失效、無任何錯誤訊息。`FrontCartApi::add_to_cart_with_shop_context()` 因此在呼叫 `WC()->cart->add_to_cart()` **之前**把 `profit_shop_id` 橋接進 `$_POST`，呼叫完（`finally`）還原。**絕對禁止**改用 `add_to_cart()` 第 5 個參數 `$cart_item_data` 傳 `profit_shop_id`——`AddToCartHook` 開頭有 short-circuit（`isset($cart_item_data['profit_shop_id'])` 就 return），那會**跳過全部三道閘門**，變成任何人都能對任意商品套任意賣場的分潤價。也**禁止**在 `FrontCartApi` 內複製一份三閘門驗證（違反 DRY／單一真相）。
2. **REST context 下 WC cart 不會自動載入**：WooCommerce 只在 frontend 與 `DOING_AJAX` context 呼叫 `load_cart()`，REST route 必須自己 `wc_load_cart()`，且要再主動呼叫 `WC_Cart::get_cart()`（內部補跑 `get_cart_from_session()`）才能還原 session 購物車——`WC_Cart_Session::init()` 把還原邏輯掛在 `wp_loaded`，REST callback 執行時該 hook 早已跑完。三個 cart hook 的 admin guard 都是 `is_admin() && ! wp_doing_ajax()`；REST request 走 `/wp-json/`，`is_admin()` 為 false，guard 不觸發、hook 正常掛載——**不需要改任何 hook 檔案**。
3. **nonce refresh 的死鎖陷阱**：賣場頁 `status=publish` 刻意不送 nocache（Phase 5-B CDN 優化），HTML 內 nonce 可能已過期。`rest_cookie_check_errors()` 的行為是「沒送 nonce → 當 guest 放行」「送了無效 nonce 且有登入 cookie → 403 `rest_cookie_invalid_nonce`」，若 nonce endpoint 本身也要求有效 nonce 才簽發會構成死結。`get_profit_shop_cart_nonce_callback()` 因此先從 `logged_in` cookie 還原身分（`wp_validate_auth_cookie` + `wp_set_current_user`）再產生 nonce，與 WordPress 自己的 `wp_refresh_nonces()` 同一手法。前端判斷「該不該重試」的條件因此是 **403 且 body `code === 'rest_cookie_invalid_nonce'`**（不再是舊 admin-ajax 時代自訂的 `invalid_nonce`）。

**Phase 11 完工要點**：
- 回應包裝統一為 `{ code, message, data }`（與 admin endpoint 一致，非 partner 的裸 payload）；`data` 一律是完整購物車物件，mutation 後前端不需再補一次 GET
- 金額一律是「最小單位整數字串」+ `currency_minor_unit`（沿用 WC Store API 慣例，前端 `Number(x) / 10 ** minor_unit` 還原，避免浮點誤差）
- 順手修掉三處 UI 缺陷（用完整的舊版原始碼重新核對照搬完整度後發現）：`styles.css` 補上 `.ant-message, .ant-notification { z-index: 999999 !important }`（照搬 legacy `custom.scss`；antd v5 預設 zIndex 只有 1010，會被佈景主題 sticky header 蓋掉「加入購物車成功」提示）；`ToggleContent.tsx` 漸層遮罩與 `Gallery.tsx` 縮圖的 `<button>` 改成 `<div role="button" tabIndex={0} onKeyDown>`（本專案 Tailwind `preflight: false`，佈景主題對 `<button>` 的樣式會蓋進來，詳見 `react.rule.md` §6.7）
- 不動 Domain 層；不引入新 npm / composer 套件
- **已知落差**：TD-29 更新為 `FrontCartApi` 待補 IT（原 `FrontAjax` 已不存在）；新增 TD-30（加購 `variation` 屬性鍵缺 `attribute_` 前綴，Phase 10 / legacy 既有缺陷、非 Phase 11 引入，刻意不修——理由見 `specs/tech-debt.md`）

**驗證數據**（實測，2026-07-30）：

| 項目 | 結果 |
|---|---|
| `npx tsc --noEmit` | 127（baseline 127），`js/src/profit-shop-front/` 貢獻 **0** |
| `npx eslint "js/src/profit-shop-front/**"` | 0 errors / 0 warnings |
| `composer lint`（PHPCS） | 156/156 全綠（Phase 10 原為 138） |
| `php -l` × 4 檔 | 全過 |
| `pnpm build` | 成功 42.72s，三個 entry 齊全 |
| profit-shop-front entry | JS 39.7 kB + CSS 19.1 kB（CSS 含 Tailwind 與新補的 `.ant-notification`） |
| bundle 隔離 | `App1` / `@refinedev` / `dataProviderName` / `wc-rest` / `wc/store` / `admin-ajax` → **全部 0 命中**；`X-WP-Nonce` 1 命中（新 API 預期使用） |
| 端到端執行期驗證 | **32 項全通過**（用 `rest_do_request()` 在真實 WP+WC 環境內部呼叫）：6 個 route 註冊成功／`wc_load_cart()` 在 REST context 生效／`cart_item_data['profit_shop_id']` 注入後三閘門確實跑到／4 筆 `_profit_*` meta 全部寫入／結帳前套用價格正確（商品原價 18 → 賣場覆寫特價 240，priority 999 驗章通過並 `set_price`）／3 則攻擊情境全擋（偽造不存在 shop_id、正版賣場套未授權商品、非法 `cart_item_key` 400）／nonce endpoint 回傳的 nonce 可通過 `wp_verify_nonce(..., 'wp_rest')` |

### Phase 12（賣場前台退回 WooCommerce 原生加購、移除自訂購物車）已完工

站長要求賣場前台「移除底下的購物車等資訊」，且商品 Modal 的「加入購物車」改用**原本 WordPress 的方式**加入購物車。Phase 10 / 11 為了「不離開頁面完成購物」而建的整條自訂購物車通道（前台購物車表格 + 樂觀更新 + 自訂 REST）因此失去存在理由，整條移除。

| 層 | 變更 | 路徑 |
|----|------|------|
| 前端加購 | `AddToCartButton` 從 `useMutation` + `api.addToCart()` 改為**動態建立隱藏 `<form method="post">` POST 回本頁**，欄位 `add-to-cart`（父商品 ID）/ `quantity` / `variation_id` / `attribute_*` / `profit_shop_id`，交給 `WC_Form_Handler::add_to_cart_action()` 處理 | `js/src/profit-shop-front/ProductModal/AddToCartButton.tsx` |
| 前端刪除 | `Cart/`（3 檔）、`hooks/useCart.ts`、`api.ts`、`components/LoadingText.tsx` 整批刪除；`atoms.ts` 移除 `cartDataAtom` / `defaultCartData`；`types.ts` 移除全部購物車 REST 型別（只留 `TProductVariationAttribute`）；`utils.tsx` 移除 `getPrice()`；`styles.css` 移除 `.ps-cart-table` 全區段；`main.tsx` 移除 `QueryClientProvider` | `js/src/profit-shop-front/` |
| 前端連帶 | `StockInfo` 拿掉「，已加入購物車 N 件」（無資料來源）；`useProductModalProps` 的數量上限不再扣購物車既有數量（超買改由 WooCommerce 自己擋） | 同上 |
| PHP 刪除 | `Infrastructure/Rest/FrontCartApi.php`（1590 行 / 6 endpoint）整檔刪除 + `Loader` 移除註冊；`ProfitShopFrontData::build_env()` 移除 `cartApiUrl` / `nonce` / `checkoutUrl` | `inc/classes/Domains/ProfitShop/` |
| PHP 新增 | template 自印 `wc_print_notices()`（賣場頁不是 WC template，佈景主題掛在 `woocommerce_before_main_content` 的 `woocommerce_output_all_notices` 不會被觸發；少了它使用者按下加購後畫面**除了重新載入之外毫無變化**） | `Infrastructure/WordPress/templates/profit-shop-front.php` |

**Phase 12 完工要點**：
- **分潤鏈路完全沒動**：`AddToCartHook`(5) 三閘門 + `CartPriceOverrideHook`(10/999) HMAC 簽章 + `CartItemMetaDisplay` + `SingleProductPriceHook` 五個檔案一行未改。原生表單是 `method="post"`，`profit_shop_id` 正常進 `$_POST`，`AddToCartHook::extract_shop_id_from_request()` 讀得到——這正是 Phase 11 得靠 `$_POST` 橋接才能達成的事，退回原生表單後不再需要任何橋接
- **`attribute_*` 的鍵取自選中變體的 `attributes` 物件**（WC `get_available_variations()` 的原始輸出，形如 `attribute_pa_color`），**不是**從 `selectedAttributesAtom` 的 `name` 自行加前綴組出來——自訂（非 taxonomy）屬性經 WC `sanitize_title()` 後可能與原始名稱不同（中文屬性會變成 percent-encoded），自行組鍵會在那些商品上安靜地對不上而被判為「缺少屬性」。順帶讓 TD-30 在這條新路徑上不再成立
- **保留 `<AntdApp>` provider 與 `.ant-notification` z-index 規則**（目前無消費者）：缺了 provider 時 `App.useApp()` 回空物件、呼叫當下直接 throw 並中斷後續程式碼且無清楚錯誤訊息（`react.rule.md` §2），留 3 行 CSS + 一層零 DOM provider 的成本遠低於下一個人重踩。反之 `QueryClientProvider` 移除——本 bundle 已無任何非同步查詢，留著會誤導後人以為還有資料流
- **UX 取捨（已知且刻意）**：原生加購會**重新載入整頁**，因此樂觀更新、彩帶（`showConfetti`）、「加入購物車成功」通知全部移除——它們在頁面 reload 前來不及被看到。加購結果改由 WC notice 呈現，若站台 WooCommerce 設定「加入購物車後轉跳購物車頁面」則會直接跳轉，這是 WooCommerce 原生行為

**驗證數據**（實測，2026-07-31）：

| 項目 | 結果 |
|---|---|
| `npx tsc --noEmit` | 127（baseline 127），`js/src/profit-shop-front/` 貢獻 **0** |
| `vendor/bin/phpcs inc/` | **140/140 全綠**（Phase 11 為 156，差額是刪掉 `FrontCartApi.php` 後的檔案數變化） |
| `php -l` × 3 檔 | 全過 |
| `pnpm build` | 成功 45.01s，三個 entry 齊全 |
| profit-shop-front entry | JS **16.7 kB**（Phase 11 為 39.7 kB，−58%）+ CSS **16.2 kB**（Phase 11 為 19.1 kB）；Tailwind 有產出、`ps-cart-table` 已不在 CSS 內 |
| bundle 隔離 | `App1` / `@refinedev` / `Refine` / `dataProviderName` / `wc-rest` / `wc/store` / `admin-ajax` / `profit-shop-cart` / `axios` / **`X-WP-Nonce`** → **全部 0 命中**（`X-WP-Nonce` 從 Phase 11 的 1 命中回到 0，本 bundle 已不打任何 API） |
| `npx eslint` | ⚠ **無法執行**：`eslint-import-resolver-typescript` 的 native binding 在本機載入失敗（`unrs-resolver`），對**未改動**的 `js/src/partner-portal/**` 跑同一指令也報同樣的 112 errors，確認是 pre-existing 環境問題、非本次改動造成。已登記 `specs/tech-debt.md` TD-31 |

**真實站台端到端驗證**（實測，2026-07-31，對 `local-turbo.powerhouse.tw` 的 `test-shop-alpha` 賣場、商品 #65 T-Shirt，賣場覆寫價 300/240、商品原始價 18）：

| 項目 | 結果 |
|---|---|
| 賣場頁 HTML 購物車殘留 | `ps-cart-table` / `profit-shop-cart` / `cartApiUrl` / `前往結帳` → **全部 0 命中** |
| localize `env` 欄位 | 只剩 `{siteUrl, shopId, shopSlug}`，`cartApiUrl` / `nonce` / `checkoutUrl` 已消失 |
| **A. POST 表單 + 合法 `profit_shop_id=1932`** | 加入購物車成功；分潤標記「來自賣場: 測試賣場 Alpha」顯示；**小計 NT$240（賣場覆寫特價）** |
| **B. POST 表單但不送 `profit_shop_id`** | 加入成功；**無分潤標記**；小計 NT$18（商品原價） |
| **C. POST 表單 + 偽造 `profit_shop_id=999999`** | 加入成功；**無分潤標記**；小計 NT$18（商品原價）—— 三道閘門正確拒絕 |

A/B/C 三組對照同時證明：`$_POST['profit_shop_id']` 確實被 `AddToCartHook`（priority 5）讀到、三道閘門對偽造 shop_id 有效、`CartPriceOverrideHook`（priority 999）驗章後 `set_price` 正確、`CartItemMetaDisplay` 分潤標記正常。**退回原生表單後不需要任何 `$_POST` 橋接**這個推論在真實環境成立。

⚠ 驗證過程踩到兩個**與本次改動無關**的環境陷阱，記錄下來避免重踩：(1) 站台裝了 **WP Rocket**，`/cart/` 頁與賣場頁的 GET 都會拿到快取版本，直接用 HTTP client 讀 `/cart/` 會看到「購物車是空的」的假象——要查真實購物車狀態必須走 `POST /?wc-ajax=get_refreshed_fragments`（見 TD-32）；(2) 系統 PATH 上的 `php` 不是 Local by Flywheel 的 PHP，`require wp-load.php` 會 Database Error，CLI 診斷腳本必須用 Local 自己的 PHP。

### Phase 13（購物車顯示賣場覆寫後的原價 / 特價）已完工

> 上游：team lead 已完成 systematic-debugging Phase 1-3 根因調查（不重複調查，直接進規劃）
> 規劃：`specs/plan/2026-08-03-cart-regular-price-override-plan.md`（含 9 條已知風險 R-1～R-9、10 條失敗模式登記表、簽章版本遷移設計、過渡期行為矩陣、4 條待 team lead 拍板的決策點）
> 對照實作：`SingleProductPriceHook`（Phase 9，單品頁的同型問題早已修好的另一半）

站長回報：分潤賣場覆寫商品價格（原價 800 / 特價 600）後，購物車（WooCommerce Cart Block / Store API）顯示的**劃線原價**是商品的**原始** `regular_price`（1000），只有特價正確。成因是 `CartPriceOverrideHook::apply_override()` 只呼叫 `set_price()`，從未覆寫 `regular_price` / `sale_price`，而其 `resolve_effective_price()` 回傳型別 `?string` 在結構上就丟棄了原價覆寫這個資訊；`SingleProductPriceHook`（Phase 9，單品頁）當時早就是回傳 `{price, regular, sale}` 三元組，兩條路徑因此分歧，卻都「正常運作」、沒有任何錯誤訊息。

| 層 | 內容 | 路徑 |
|----|------|------|
| Domain Service | **新增** `OverridePriceResolver`：從 `SingleProductPriceHook::resolve_prices()` 抽出的純 Domain 服務（不依賴 WP / WC），回傳 `{price, regular, sale}` 三元組，是 cart 與單品頁**唯一的取價真相**——取代原本靠人維持「逐字一致」的紀律（那個約定已失敗過一次，本 bug 即後果） | `Domain/Service/OverridePriceResolver.php` |
| Infrastructure | `SingleProductPriceHook::resolve_prices()` 改為委派 `OverridePriceResolver`（純重構，行為零變更；`SingleProductPriceHookTest` 11 method **零修改**全綠，是重構無誤的直接證據） | `Infrastructure/WooCommerce/SingleProductPriceHook.php` |
| Application Service | `CartPriceSignatureService`：`PAYLOAD_VERSION` 升 `v2`（payload 納入 regular / sale）+ `LEGACY_PAYLOAD_VERSION = 'v1'` 永久保留驗證能力 + `sign()` / `verify()` 各加兩個 optional 尾參（皆有預設空字串，既有 16 個測試方法僅 2 條版本前綴斷言需更新，其餘零修改）+ 新增 `payload_version(): ?string`（前綴不認得回 `null`）+ 抽 `build_payload()` 依版本組出 v1 / v2 payload | `Application/Service/CartPriceSignatureService.php` |
| Infrastructure | `CartPriceOverrideHook`：新增 `META_REGULAR_OVERRIDE` / `META_SALE_OVERRIDE` 2 個 meta 常數 + meta key 清單收斂為 `META_KEYS_BASE` / `META_KEYS_V2` / `META_KEYS_ALL` 常數陣列（`restore_from_session()` / `strip_profit_meta()` / `has_all_profit_meta()` 三處共用，根除清單漂移）+ `resolve_effective_price()` 改名並委派 `OverridePriceResolver` → `resolve_override_prices()` 回傳三元組 + `before_calculate_totals()` 新增 `try_apply_for_version()` 依 `payload_version()` 分派 v1 / v2 驗證邏輯 + 新增 `apply_prices_to_product()` helper（`regular`/`sale` 的 `''` / `null` 語意分開處理）+ `has_all_profit_meta()` 改為條件式（`$require_v2` 參數，防新 meta 被無條件判必填）+ `reset_to_original_price()` 一併還原 regular / sale（防同一 request 內二次計算殘留竄改值）+ 新增 `normalize_nullable_price()`（`null`/`''` 提前 `return ''`，避免進 `wc_format_decimal` 被轉成 `'0.00'`）+ 修正 `normalize_price_for_signature()` docblock（僅改註解，不改行為，見下方要點） | `Infrastructure/WooCommerce/CartPriceOverrideHook.php` |
| Loader | 建立共用的 `OverridePriceResolver` 實例，同時注入 `CartPriceOverrideHook` 與 `SingleProductPriceHook` 建構子 | `Loader.php` |
| 測試 | **新增** `OverridePriceResolverTest`（11 method）；`CartPriceSignatureServiceTest` 16→26 method（**+10 新增**：v2 預設輸出 / regular 被竄改 / sale 被竄改 / 手動組出的 v1 簽章仍可驗證 / v1 簽章換 `v2.` 前綴必須失敗 / `payload_version()` 對各種前綴的回傳等；既有 16 method 中僅 2 條硬編 `'v1'` 字面值的斷言更新為 `'v2'`，其餘零修改）；`CartPriceOverrideHookTest` 14→27 method（**+13 新增**：核心回歸 5 + v1 相容與安全 6 + 連帶行為 2；另修正既有 6 條斷言為實測值 `'888.00'` / `'666.00'`，即 R-5） | `tests/Unit/Domain/Service/` + `tests/Unit/Application/Service/` + `tests/Integration/Infrastructure/WooCommerce/` |

**Phase 13 完工要點**：
- **`OverridePriceResolver` 是本次最重要的架構收斂**：把 cart 與單品頁「取價語意必須逐字一致」從一條**靠人記住**的紀律，變成**結構上做不到分歧**的設計——兩邊都委派同一份 Domain 服務。詳見 `profit-shop.rule.md` §7「`OverridePriceResolver`」
- **簽章版本遷移是本次複雜度核心**：`CartPriceSignatureService` 從 v1 升級到 v2（payload 納入 regular/sale），**v1 驗證分支永久保留、不設下架期限**——登入使用者的持久化購物車（`_woocommerce_persistent_cart_{blog_id}`）沒有任何過期機制，拔掉 v1 會讓那些既有購物車的分潤價**靜默消失**且無任何錯誤訊息。7 種情境的過渡期行為矩陣、版本判斷規則、v1→v2 就地升級的前置條件詳見 `profit-shop.rule.md` §7
- **安全紀律**：版本判斷一律用 `CartPriceSignatureService::payload_version()`，**不可**用「regular/sale meta 是否存在」判斷——否則攻擊者可保留合法 v1 簽章、額外注入偽造的 `_profit_regular_price_override`，讓假原價繞過簽章保護生效。已有 IT 安全測試鎖死
- **`regular === ''` 與 `sale === ''` 語意不同**：regular 空字串 = 不覆寫（沿用商品原本原價）；sale 空字串 = **必須寫入** `set_sale_price('')` 清掉商品原本特價，否則會出現「原價劃掉 → 特價」但實收是覆寫原價的矛盾
- **修正一處既有文件錯誤描述（R-5）**：`normalize_price_for_signature()` 的舊 docblock 宣稱 `wc_format_decimal($price, '')` 會 trim trailing zeros（`'888'` → `'888'`），實際行為是補足到預設小數位（`'888'` → `'888.00'`，因 `$trim_zeros` 未傳 `true`）。這極可能就是 `specs/tech-debt.md` TD-1 記載的「cart hook 6 個 pre-existing 失敗」的成因，Phase 13 修正了 docblock 與對應的 6 條測試斷言（**只改文件與測試，不改行為**——修行為會讓所有既有 v1 簽章的 payload 格式改變，導致既有購物車驗章全滅）
- **行為變更（刻意接受，已告知站長）**：套用 regular/sale 後 `WC_Product::is_on_sale()` 會反映真實特價狀態，可能讓「排除特價商品」的優惠券對分潤商品從可折扣變成不可折扣。語意上正確（與 `SingleProductPriceHook` 在單品頁的既有行為一致），已寫 IT 測試鎖住此行為變更
- 新增技術債 2 條：TD-34（跨層級 regular < sale 的荒謬顯示，R-8，與單品頁行為一致、刻意不修）、TD-35（v1 驗證分支不是死碼，必須永久保留）；另發現 1 條 pre-existing 測試 flaky，登記 TD-36（見下方「failure 減 5 而非減 6」）
- 不引入新 npm / composer 套件；不改 `AddToCartHook`（三道閘門）、`CartItemMetaDisplay`、`OverrideItem` / `PriceOverride` / `VariationOverride` / `PriceCalculator` Domain 層、`V2Api`、`specs/api/api.yml`（cart meta 不是 wire 契約）、任何前端檔案；訂單 / 訂單 email 已查證完全不使用 `regular_price`（line item 存的是 `subtotal` / `total`），故本次不需改動

**驗證數據**（實測，2026-08-03；baseline 為動工前實測，非沿用文件記載數字）：

| 層級 | Baseline（動工前實測） | 完成後 | 判定 |
|---|---|---|---|
| Unit | 362 tests / 736 assertions / 1 failure | **399 / 816 / 1** | +37 tests，failure 未增加（TD-2 pre-existing） |
| Integration | 216 / 928 / 20 failures | **229 / 976 / 15** | +13 tests，failure **減 5**（詳見下方說明） |
| `vendor/bin/phpcs inc/` | 133/133 綠 | **134/134 綠** | +1 新檔（`OverridePriceResolver.php`） |
| `CartPriceOverrideHookTest` 單獨跑 | 6 failures | **OK（27 tests / 84 assertions）** | 全綠 |
| `OverridePriceResolverTest` 單獨跑 | — | **OK（11 tests / 37 assertions）** | 新增 |
| `SingleProductPriceHookTest` | 11 method | **`git diff` 為空、全綠** | 重構零破壞的直接證據 |

**failure 減 5 而非減 6**：cart hook 那 6 條確實全數轉綠（R-5 修正生效），但 `PartnerReportsScopeTest` 在完整跑 integration testsuite 時多浮現 1 條失敗（`test_partner_a_token_settlements_query_string_attack_ignored`，預期 401 實得 200）。已用 `git stash` 對照確認：baseline 狀態下**單獨**跑該檔同樣失敗（只是失敗的是另一條方法 `test_partner_a_token_can_only_see_partner_a_kpi`），屬於 order-dependent 的 pre-existing flaky，與本次改動無關，已登記 `specs/tech-debt.md` TD-36。

**真實站台端到端驗證**（`local-turbo.powerhouse.tw`，賣場 `test-shop-alpha` shopId=1932，商品 65 T-Shirt 原始價 18、賣場覆寫 300/240），直接讀 Store API `/wp-json/wc/store/v1/cart` 的 `items[].prices`：

| 情境 | price | regular_price | sale_price | item_data 分潤標記 |
|---|---|---|---|---|
| POST 加購帶 `profit_shop_id=1932` | 240 | **300** | 240 | 有 |
| POST 加購**不帶** `profit_shop_id` | 18 | 18 | 18 | 無（零殘留） |

修復前 `regular_price` 會是商品原始的 18（劃線價顯示錯誤）。負向驗證（不帶 `profit_shop_id`）證明作用範圍精準收斂、零殘留。

### Phase 14（賣場前台改 PHP + daisyUI + vanilla JS，CSS 統一交給 powerhouse 管理）已完工

站長提出三件事：(1) power-shop 不再自己管 CSS，全部交給 powerhouse 統一提供；(2) 賣場前台「獨立頁面」與「shortcode」兩條渲染路徑統一 DOM；(3) 賣場前台移除 React / antd，改回 PHP + daisyUI + 手寫 vanilla JS。三者互相牽動——CSS 收斂到 powerhouse 之後，賣場前台若仍是 antd 元件，會變成「antd 元件外觀 + daisyUI 語意色」的四不像，因此三件事一次做完。

| 需求 | 內容 | 路徑 |
|---|---|---|
| 1. 移除自有 CSS | **刪除** `js/src/assets/styles/tailwind.css`（admin + partner portal 原共用 entry）/ `js/src/profit-shop-front/styles.css` / `tailwind.config.cjs` / `postcss.config.cjs`；`package.json` devDependencies 移除 `tailwindcss` / `daisyui` / `autoprefixer` / `postcss` / `postcss-import` / `postcss-nesting`；`App1.tsx` 移除 `@refinedev/antd/dist/reset.css` 與 `@/assets/styles/tailwind.css` 兩個 import；`partner-portal/main.tsx` 移除 tailwind.css import。`pnpm build` 後 `js/dist/` **零 CSS 檔案**（manifest 兩個 entry 的 `css` 皆為 `[]`） | `package.json` + `js/src/App1.tsx` + `js/src/partner-portal/main.tsx` |
| 配套（跨 plugin，powerhouse） | powerhouse `inc/templates/pages/admin-layout/index.php` 的 `<body>` 補上 `id="tw"`——powerhouse 的 Tailwind config 是 `important: '#tw'`，utility 全輸出成 `#tw .flex{}`；前台由 powerhouse 自己的 `language_attributes` filter 補上 `id="tw"`，但**後台一直沒有**，導致 `admin.min.css` 內約 900 條 utility 從未生效，各 power-* 子外掛才會各自打包一份無 scope 的 Tailwind。補上之後 powerhouse 的 admin CSS 才真的能被子外掛共用 | powerhouse repo（跨 plugin，非本次直接改動範圍） |
| 配套（partner portal） | `PartnerPortalRenderer::render_portal()` 顯式呼叫 `\J7\Powerhouse\Bootstrap::enqueue_frontend_assets()`——portal 刻意不呼叫 `wp_head()`（避免 theme / admin bar / 其他 plugin 注入污染），powerhouse 掛在 `wp_enqueue_scripts` 的 CSS 因此不會自動印出；且自印的 `<html>` 加上 `id="tw" class="tailwind"` | `Infrastructure/WordPress/PartnerPortalRenderer.php` |
| 2. 統一渲染 DOM | 移除 `$is_shortcode_context` 旗標，`templates/profit-shop-front.php` 外層**一律 `<div>`**（獨立頁面走 theme 的 header.php 時同樣可能已有 `<main>`，統一 `<div>` 才是唯一安全解）。同時修正一個實質 bug：早期只有 `ProfitShopRenderer` 會傳 `$front_settings`，shortcode 路徑拿到空陣列 → `shopStatus` 一律 fallback 成 `'published'` → **已結束販售的賣場用 shortcode 嵌入時照樣能加購**；現在兩條路徑都傳 `$front_settings` 並都呼叫 `ProfitShopFrontView::enqueue()`（早期 shortcode 完全沒 enqueue，商品 Modal 開不起來） | `ProfitShopRenderer.php` + `ProfitShopShortcode.php` + `templates/profit-shop-front.php` |
| 3. 賣場前台改 PHP + daisyUI + vanilla JS | **刪除** `js/src/profit-shop-front/` 整目錄（19 檔：`App.tsx` / `ProductModal/` / `components/` / `hooks/` / `atoms.ts` / `env.ts` / `utils.tsx` / `styles.css` / `main.tsx` / `types.ts` 等）+ `vite.config.ts` 第三個 entry。**新增** `inc/assets/js/profit-shop-front.js`（手寫 vanilla JS，**不進 Vite build pipeline**，只負責五件互動：開 Modal、Gallery 縮圖、描述展開收合、數量加減、變體選擇）+ `Infrastructure/WordPress/ProfitShopFrontView.php`（呈現層工具：`currency()` / `number()` / `price_range()` / `embed_youtube()` / `stock_presentation()` / `max_qty()` / `icon()`（內嵌 SVG，取代 `@ant-design/icons`）/ `js_i18n()` / `enqueue()`；當時為繞過主題蓋掉 daisyUI 尺寸的問題加上 `BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE` 三個 Tailwind utility 尺寸常數，**已於 2026-08-04 隨 powerhouse CSS 建置層系統性修正而移除**，見下方「Phase 14 補充修正」與 Pitfall #26）+ `templates/partials/profit-shop-card.php`（商品卡片）+ `templates/partials/profit-shop-modal.php`（商品 Modal，`<dialog class="pc-modal">`）。`ProfitShopFrontData::build_products()` 改為 **public**（卡片與 Modal 共用同一份 payload，根除「有卡片但 Modal 找不到商品」的同步風險）；`build()` / `build_env()` 隨 React bundle 一併刪除（前台不再有 `wp_localize_script` 的全域 payload） | `inc/assets/js/profit-shop-front.js` + `Infrastructure/WordPress/ProfitShopFrontView.php`（新檔）+ `templates/partials/*`（新檔）+ `ProfitShopFrontData.php` |
| 測試 | `ProfitShopShortcodeTest` 新增 `test_renderer_and_shortcode_paths_render_identical_dom`（逐字比對兩條路徑完整輸出）+ `test_shortcode_respects_closed_shop_status`（閉店賣場不得印出可送出的加購表單）；移除已失去前提的 `test_template_defaults_to_main_wrapper_without_flag` | `tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php` |

**Phase 14 完工要點**：
- **資料不再走全域 `wp_localize_script`**：每個商品的變體資料改印在自己 Modal 內的 `<script type="application/json" class="ps-variations-data">`，JS 查找一律限定在最近的 `.ps-profit-shop-front` 祖先內——這讓「一頁放多個 shortcode 賣場」天然可行（早期 React 版的全域物件會互相覆蓋），也讓「獨立頁面」與「shortcode」兩條路徑的 DOM 與行為完全一致
- **漸進增強，不再需要任何 `<noscript>` CSS**：每張卡片只印**一個**動作元素（simple → `<form method="get">`；其他 → `<a href="{permalink}?profit_shop_id=">`），本身無 JS 即可用；JS 在卡片層攔 click 並 `preventDefault()` 改開 Modal，同時把按鈕文字換成 `data-ps-js-label`。因此不再需要舊版那組 `<noscript>` 樣式、`.ps-not-ready` 灰階、或任何 inline `<style>`
- **顏色全走 daisyUI 語意色**（`pc-btn-primary` / `text-error` / `text-success` / `text-warning` / `text-base-content/60` / `bg-base-200` / `border-primary`），跟隨站台 daisyUI theme；賣場設定的 `btnColor` 與 `--ps-primary` CSS 變數**不再驅動前台顏色**——`ProfitShopFrontData::settings()` 的 `colorPrimary` 欄位保留（wire 契約穩定 + hex 白名單仍是有效輸入驗證 + 日後接 daisyUI `data-theme` 的天然載體）但**已無消費者**，登記 `specs/tech-debt.md` TD-38
- **三個實測踩到的新陷阱**（完整說明見 Common Pitfalls #26 / #27 / #28）：(A) 佈景主題會用相同 specificity 蓋掉 daisyUI 按鈕尺寸，**動工當下**靠 power-shop 這一側逐檔補 Tailwind utility（`#tw` 前綴、specificity 較高）釘住——**已於 2026-08-04 由 powerhouse CSS 建置層系統性修正取代**（daisyUI 選擇器整批收進 `#tw`），繞過用的常數已移除，見下方「Phase 14 補充修正」與 Pitfall #26（B、C 仍照舊）；(B) `inline` 在 blocklist 內、替代品 `tw-inline` 又會蓋掉 `tw-hidden` 讓隱藏失效，圖示 display 什麼都不加最安全；(C) powerhouse CSS enqueue 版本號不會因為重跑 build 自動 bump，部署後必須手動升版，否則既有訪客吃舊快取
- 不引入新 npm 套件（移除 6 個）；不引入新 composer 套件；不動 Domain / Application 層與 `AddToCartHook` / `CartPriceOverrideHook` / `CartItemMetaDisplay` / `SingleProductPriceHook` 四個 hook 檔案——原生表單 `profit_shop_id` 正常進 `$_POST`，分潤鏈路一行未改
- 技術債異動：新增 TD-37（powerhouse CSS 快取版本號）、TD-38（`colorPrimary` 無消費者）、TD-39（power-shop 樣式跨 repo 建置相依 powerhouse）；TD-26（Modal 992–1079px 版型破碎，antd `Col lg` 992px vs Tailwind `lg` 1080px 兩套斷點不一致）因 antd 整個移除而**結構性消失**，移至「已解決」；TD-29 更新（Phase 12 的 React `AddToCartButton.tsx` 已隨本次刪除，加購表單契約改由 PHP template 承載，理論上比 React 元件更容易補 IT 斷言，但目前仍未補）

**驗證數據**（實測，2026-08-04）：

| 項目 | 結果 |
|---|---|
| `vendor/bin/phpcs inc/` | **137/137 全綠** |
| `php -l` × 10 檔 | 全過 |
| `pnpm build` | 成功；manifest 剩 2 個 entry，**`css` 陣列皆為空**，`js/dist/assets/*.css` **0 個檔案** |
| `npx tsc --noEmit` | **127**（= baseline，零回歸） |
| Unit testsuite | **402 tests / 824 assertions / 1 failure**（failure 為 pre-existing `LoginRateLimiterIpDimensionTest`，見 TD-2） |
| Integration testsuite | **236 tests / 1001 assertions / 15 failures**（15 條全部 pre-existing：partner auth / CptRegistrar / RewriteRules，沒有一條在本次改動的檔案內） |
| `ProfitShopShortcodeTest` 單獨跑 | **OK（18 tests / 66 assertions）** 全綠 |
| `npx eslint` | ⚠ 無法執行（`eslint-import-resolver-typescript` 的 `unrs-resolver` native binding 載入失敗，pre-existing 環境問題，見 TD-31） |
| Class 覆蓋驗證（自寫腳本比對） | 賣場前台 111 個 class **全數**由 powerhouse `front.min.css` 產出；Admin SPA 214 個 class **全數**由 `admin.min.css` 產出 |

**真實站台端到端驗證**（`https://local-turbo.powerhouse.tw/profit-shop/test-shop-alpha/`，`agent-browser`）：

| 驗證項 | 結果 |
|---|---|
| HTML 標記 | `<main class="ps-profit-shop-front` **0**／`<div class="ps-profit-shop-front` **1**；`<dialog` **4**；`--ps-primary` **0**；`ps-btn ps-btn-primary` **0**；`ps-not-ready` **0**；`profit_shop_front_app` **0**；`front.min.css` **1**；`id="tw"` **1**；`profit-shop-front.js` **1** |
| 計算後樣式 | 卡片按鈕 **32px / min-height 32px / font-size 14px**（與原版 `.ps-btn` 完全一致）；`pc-btn-primary` 背景 = 站台 daisyUI theme 的綠色；`text-error` = 紅色 |
| Modal 互動 | 點卡片開啟成功；加購表單欄位 `add-to-cart=65` / `profit_shop_id=1932` / `quantity=1`（分潤鏈路完整）；價格「NT$ 300 → NT$ 240」（賣場覆寫價正確）；庫存文案「此商品還有庫存」；數量加減 1→2→3→2→1 且下限守在 1 |
| backdrop | 修正後為正確的半透明深色遮罩；關閉鈕的綠圈確認是 focus ring（僅聚焦時出現），非 bug——原生 `<dialog>` 自動聚焦第一個可聚焦元素，是正確 a11y 行為 |

### Phase 14 補充修正（daisyUI 樣式系統性修正，powerhouse CSS 建置層）已完工（2026-08-04）

Phase 14 剛完工時，賣場前台的 daisyUI class（`pc-btn-sm` 之類）會被佈景主題整批蓋掉，當時的因應是在 power-shop 這一側逐顆按鈕補 Tailwind utility（`ProfitShopFrontView::BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE` 三個常數）。**本次改為在 powerhouse 的 CSS 建置階段做系統性修正**，那三個常數已全數移除。

**根因**：
1. daisyUI v4 的元件 class 以 specificity **(0,1,0)** 輸出，例如 `.pc-btn{}`。
2. daisyUI v4 **沒有** `important` 之類的設定可以提高權重——config 只有 `styled` / `themes` / `base` / `utils` / `logs` / `darkTheme` / `prefix` / `themeRoot`。
3. Tailwind 的 `important: '#tw'` **只作用在 utilities**，不會套到外掛用 `addComponents` 加進來的規則。實測：修正前 `front.min.css` 內有 **438 條**未加 scope 的 `.pc-*`，只有 23 條帶 `#tw`（那 23 條是 `js/src/assets/scss/_daisyui.scss` 手寫補的）。
4. WordPress 佈景主題（實測 Blocksy `main.min.css`）有一條 `.button, .ct-button, .added_to_cart, .ct-button-ghost, [type="submit"], .wp-element-button, …` 的規則，specificity **同樣是 (0,1,0)**。
5. **CSS 同分時由載入順序決勝**，而佈景主題的 CSS 排在外掛 CSS 之後 → **主題永遠贏**。⇒ `pc-btn-sm` / `pc-btn-ghost` / `pc-btn-circle` / `pc-input-sm` 全部被蓋掉（按鈕變成主題的 40px、ghost 不透明、輸入框 40px/16px）。
6. Tailwind utility 之所以免疫，正是因為 `#tw .h-8` 是 **(1,1,0)**，嚴格大於主題那條，**與載入順序無關**。

**修正方案**：在 powerhouse 的 CSS 建置階段，把所有 daisyUI 選擇器也收進 `#tw`，與 Tailwind utility 用同一套機制。powerhouse 新增 `scripts/postcss-scope-daisyui.cjs`（PostCSS plugin，從 `tailwind.config.cjs` 的 `important` 讀 scope，避免與 Tailwind 設定漂移）+ `postcss.tailwind.cjs`（給 Tailwind CLI `--postcss` 旗標用的設定，內含上面那個 plugin + `autoprefixer`），並把 `build-css:admin` / `build-css:front` / `watch-css:admin` / `watch-css:front` 四個 script 都加上 `--postcss ./postcss.tailwind.cjs`。

⚠️ **一個必須記住的重要事實**：實測 **Tailwind CLI 不會自動讀取 `postcss.config.cjs`**（在裡面放一個會印字的 probe plugin，跑 `npx tailwindcss ...` 完全沒有輸出；那份設定只有在有人直接呼叫 postcss——例如 Vite 的 CSS pipeline——時才生效）。CSS 建置必須用 `--postcss` 旗標**明確指定** `postcss.tailwind.cjs`，額外的 PostCSS 步驟才會跑。**不要**把 plugin 加進 `postcss.config.cjs` 就以為生效了。

**三個實作細節**：

1. **識別方式**：靠 `daisyui.prefix: 'pc-'`。比對 `.pc-` 與 `\:pc-` 兩種形式（後者是 Tailwind 對響應式變體的轉義寫法，`sm:pc-btn-sm` → `.sm\:pc-btn-sm`）。刻意要求 `pc-` 緊接在 `.` 或 `\:` 之後，才不會誤傷 `.upc-code` 這種剛好含 `pc-` 的第三方 class。
2. **🔴 根部錨定的選擇器絕對不能加 scope**：`#tw` 本身就掛在 `<html>`（前台）或 `<body>`（後台 admin-layout）。把 `:root .pc-countdown` 變成 `#tw :root .pc-countdown` 之後，會變成「找 `#tw` 底下的 `:root` **後代**」——`:root` 永遠是 `<html>`、不可能是自己的後代，**這條規則從此永遠匹配不到，且沒有任何錯誤訊息**。實際踩到兩條（daisyUI v4.12）：`:root .pc-countdown`（倒數計時元件樣式整個失效）與 `:root:has(:is(.pc-modal-open, .pc-modal:target, .pc-modal-toggle:checked + .pc-modal, .pc-modal[open]))`（**Modal 開啟時鎖住背景捲動的規則失效**）。plugin 因此有一條 `ROOT_ANCHORED` 守門：selector 第一個 compound 若是 `:root` / `html` / `body` / `*` / `[data-theme` 一律跳過（這類規則本來就已經是 (0,2,0) 以上、或根本沒有主題會來競爭，不加也安全）。**改動 daisyUI 版本或 `daisyui.prefix` 時，這條識別規則要跟著檢查**——build log 那行「已把 N 條…」數字掉到 0 就代表保護整個失效。
3. **不會反過來蓋掉 Tailwind utility**：轉換後 daisyUI 是 `#tw .pc-btn` (1,1,0)、Tailwind utility 是 `#tw .h-8` (1,1,0)，**同分**；但 Tailwind 的輸出順序是 base → components → utilities，utility 排在後面 → utility 仍然勝出。「utility 覆寫元件樣式」這個 Tailwind 基本心智模型完全不變（已實測位置比對確認）。

**涵蓋不到的情況**：本轉換只能讓「**daisyUI 有規則、但輸給主題**」的情況翻盤。若 daisyUI **根本沒有**對某個元素出規則，主題的樣式沒有對手，仍要由使用端自己加 Tailwind utility 壓過去。已知案例：`.pc-modal-backdrop` 內那顆 `<button>`——daisyUI 只對 form 本身（`.pc-modal-backdrop`）出規則、完全沒管 button，於是主題的 `[type="submit"]` 把它塗成整片實心色塊，**把 Modal 以外的畫面全部蓋掉**。`templates/partials/profit-shop-modal.php` 因此保留 `class="bg-transparent text-transparent border-0 cursor-default"`——本次修正後**唯一僅存**的手動 utility 用法，記為 `specs/tech-debt.md` TD-40。

**power-shop 端的連帶變更**：原本為了繞過這個問題加的三個常數 **`ProfitShopFrontView::BTN_SIZE_SM` / `BTN_SIZE_XS` / `INPUT_SIZE`（內容是 `h-8 min-h-8 px-3 text-sm` 這類 utility）已全部移除**，改回原生 daisyUI class：卡片按鈕 `pc-btn pc-btn-primary pc-btn-sm`、Modal 加購鈕 `pc-btn pc-btn-primary pc-btn-sm`、關閉鈕 `pc-btn pc-btn-ghost pc-btn-circle pc-btn-sm`（原本還加了 `bg-transparent border-0 h-8 min-h-8 w-8 p-0`，現已移除）、變體屬性選項 `pc-btn pc-btn-xs`、數量 −/+ `pc-btn pc-btn-sm pc-join-item`、數量輸入框 `pc-input pc-input-bordered pc-input-sm pc-join-item`；backdrop button **保留** `bg-transparent text-transparent border-0 cursor-default`（見上「涵蓋不到的情況」）。`ProfitShopFrontView` 內留了一段註解說明那三個常數為何被移除。

**驗證數據**（實測）：

| 項目 | 結果 |
|---|---|
| `pnpm build-css:front`（powerhouse） | `✓ scope-daisyui: 已把 637 條 daisyUI 選擇器收進 "#tw"（另有 4 條已錨定在根部，刻意不加）` |
| `pnpm build-css:admin`（powerhouse） | `✓ scope-daisyui: 已把 614 條…（另有 4 條…）` |
| front.min.css | 已 scope **635** / 未 scope **0** / `:root` 誤加 **false** |
| admin.min.css | 已 scope **604** / 未 scope **0** / `:root` 誤加 **false** |
| `vendor/bin/phpcs inc/`（power-shop） | 137/137 全綠 |
| `ProfitShopShortcodeTest` | OK（18 tests, 66 assertions） |

**真實站台計算後樣式**（`local-turbo.powerhouse.tw` 賣場頁，**全部使用純 daisyUI class、零手動 utility**）：

| 元素 | 修正前 | 修正後 |
|---|---|---|
| 卡片按鈕 | 40px / 16px（主題的） | **32px / minH 32px / 14px** |
| Modal 加購鈕 | 40px | **32px / 14px** |
| 關閉鈕 | 40px + 主題邊框背景 | **32px，ghost 透明生效** |
| 數量 −/+ | 40px | **32px / 14px** |
| 數量輸入框 | 40px / minH 40px / 16px | **32px / 14px** |
| backdrop button | 整片實心主題色 | 透明（靠使用端 `bg-transparent`，daisyUI 無規則） |

**前提**：`#tw` 必須存在於祖先。前台由 powerhouse `Theme\Core\FrontEnd::add_html_attr()` 保證；後台由 powerhouse `inc/templates/pages/admin-layout/index.php` 的 `<body id="tw">` 保證（這個 id 是這次一併補上的，先前後台的 Tailwind utility 從未生效）。任何自印 HTML 骨架的地方（例如本外掛的 `PartnerPortalRenderer`）都必須自帶 `id="tw"`。詳見 powerhouse `.claude/CLAUDE.md` 的 CSS Build Pipeline 章節。

### Phase 15（商品 Modal 改 AJAX 加購 + loading + 成功通知）已完工（2026-08-05）

站長要求：Modal 點開後的「加入購物車」改成 AJAX（不換頁）+ loading 狀態 + 成功通知。這個需求正面撞上 Phase 12 的決定（整條前台購物車 REST `FrontCartApi` 已刪檔）與 Pitfall #22（禁止用 `fetch` 送 JSON body 加購），因此**通道選擇是本 Phase 的核心決策**，不是隨便接一個 endpoint。

| 層 | 內容 | 路徑 |
|----|------|------|
| PHP 呈現層 | `ProfitShopFrontView` 新增 `js_config()`（`addToCartUrl` / `cartUrl` / `redirectAfterAdd`）+ 第二個 `wp_localize_script`（`power_shop_front_config`）+ 4 個 AJAX 相關 i18n key | `Infrastructure/WordPress/ProfitShopFrontView.php` |
| Modal 版型 | `<form method="post" data-ps-form>`；加購按鈕內改為 `spinner span + label span`；按鈕下方新增就地錯誤列 `[data-ps-submit-error]`（含「查看購物車」連結）。**`variation_id` / `attribute_*` hidden input 一行未動**——它們是降級路徑所必需 | `templates/partials/profit-shop-modal.php` |
| 成功通知 | 賣場容器內印一個 `pc-toast pc-toast-top pc-toast-center` + `pc-alert`，`$is_shop_open` 才印 | `templates/profit-shop-front.php` |
| 前台 JS | 新增 8 個函式（`hasAnyAttribute` / `buildAddToCartPayload` / `setSubmitBusy` / `freezeSubmit` / `showSubmitError` / `clearSubmitError` / `showToast` / `applyFragments` / `notifyCartUpdated` / `handleResponse` / `bindAddToCart`）+ `CONFIG` / `canAjax()` / `AJAX_TYPES` 白名單；`setSubmitEnabled()` 加上飛行中守門；`refresh()` 記住 `modal.psCurrentVariation` | `inc/assets/js/profit-shop-front.js` |
| 測試 | `ProfitShopShortcodeTest` 新增 `test_open_shop_renders_ajax_dom_contract`（8 個 `data-ps-*` 屬性 + `name="profit_shop_id"` 全鎖）；`test_shortcode_respects_closed_shop_status` 加一條「閉店不得印 `data-ps-toast`」 | `tests/Integration/Infrastructure/WordPress/ProfitShopShortcodeTest.php` |

**通道決策：`?wc-ajax=add_to_cart`（WooCommerce 原生），零新增 endpoint**

五條判準逐一對照（都以原始碼求證，非推測）：

| 判準 | 結論 |
|---|---|
| `$_POST` 必須被填充（分潤三閘門的生命線） | ✅ form-urlencoded POST，PHP 自動填 `$_POST`；`AddToCartHook::extract_shop_id_from_request()` 讀得到 |
| 不新增自訂 endpoint（`FrontCartApi` 不復活） | ✅ `wc_ajax_add_to_cart` 是 WooCommerce 內建 action，power-shop 一行 PHP handler 都不用寫 |
| 變體商品可用 | ✅ **關鍵發現**：`WC_AJAX::add_to_cart()` 偵測到傳入的是 variation 時，會 `$variation_id = $product_id; $product_id = $product->get_parent_id(); $variation = $product->get_variation_attributes();`，**換算之後**才呼叫 `add_to_cart()`。第三閘門 `has_item($product_id)` 拿到的是父商品 ID，對得上；且這條通道**不需要前端送 `attribute_*`**，順帶消滅 Pitfall #22 記載的組鍵風險 |
| 未登入訪客可用 | ✅ 在 `$ajax_events_nopriv` 清單內，且**完全沒有 nonce 驗證**（原始碼有 `phpcs:disable WordPress.Security.NonceVerification.Missing` 為證），不需要任何 nonce 基礎設施 |
| 改動範圍最小 | ✅ 0 個新 endpoint、0 個新 npm / composer 套件、0 行 cart hook 變更 |

三個 cart hook 的 admin guard 都是 `is_admin() && ! wp_doing_ajax()`；`?wc-ajax=` 是前台 URL，`is_admin()` 為 false 直接短路，hook 全數正常掛載。`WC_AJAX` 只傳 4 個參數給 `add_to_cart()`（第 5 個 `$cart_item_data` 是預設空陣列），因此 `AddToCartHook` 開頭的 short-circuit 不會誤觸發，三道閘門完整跑完。

**⚠ 五條「分錯類就會讓顧客被重複收費」的紀律（3 個 lens 對抗驗證抓出 9 條 blocking issue 後定案）**

1. **飛行中必須擋住第二次送出**：`modal.psSubmitting` 旗標 + submit handler 開頭吞掉 + **`setSubmitEnabled()` 也要尊重它**。少了第三點，使用者在請求飛行中改選變體會觸發 `refresh()` → 把按鈕解鎖 → 再按一次 → 兩筆都成功。原生表單沒這個問題（送出後整頁導航，DOM 直接消失）。
2. **「請求沒送出去」與「送出了但回應異常」必須分開**：先 `res.text()` 再自行 `JSON.parse`，才能區分 `!res.ok` / 解析失敗（**商品很可能已經進購物車**）與 fetch reject（真的沒送出）。直接用 `res.json()` 會把兩者併進同一個 `catch`，而這兩種情況該給的處置完全相反。前者**凍結按鈕**並提示「無法確認加購結果，請重新整理頁面並查看購物車」；後者才允許重試。
3. **成功後的副作用不得落進失敗分支**：`handleResponse()` 整個包在 try/catch 內。`notifyCartUpdated()` 會同步執行佈景主題 / 其他外掛綁在 `added_to_cart` 上的 jQuery handler，而 jQuery 的 `.trigger()` 不捕捉 handler 例外——任何一個 handler 丟錯就會冒到 `.catch()`，把**已經成功**的加購誤報成連線問題並邀請使用者重按。
4. **任何失敗都不得自動重送表單**：`wc_add_notice()` 沒有去重，重送會讓同一則錯誤寫進 session 兩次、在畫面上重複顯示；邊界情況（第一次其實成功了）更會變成重複加購。業務失敗（`{error:true}`）一律依 WooCommerce 官方前端契約導向回應內的 `product_url`。
5. **`added_to_cart` 事件不可傳第三個參數（按鈕元素）**：傳了之後 WooCommerce 的 `updateButton()` 會在我們的表單裡插入一條佈景主題樣式的「檢視購物車」連結，與 toast 內的按鈕重複，而且**每加購一次就永久多一條**。WooCommerce 對「不傳按鈕」有明文守門，省略不會出錯。同理有 jQuery 時**只發事件、不自己套 fragments**（WC 自己會套），否則 mini-cart 會多一次閃爍。

**漸進增強：四道降級關卡，任何一道不成立就走原生表單**

1. JS 停用 → PHP 印出的 `<form method="post">` 本身就是完整加購路徑
2. `<dialog>` 不支援 → 既有守門讓卡片的原生 form / 連結生效
3. 能力不足（`canAjax()`：缺 `fetch` / `URLSearchParams` / `Promise` / `addToCartUrl`）→ 不註冊 submit 監聽器
4. `buildAddToCartPayload()` 回 `null` → **不呼叫 `preventDefault()`**，瀏覽器執行原生送出

第 4 點涵蓋：商品型別不在 `AJAX_TYPES` 白名單（`simple` / `variable` / `subscription`；grouped / external / variable-subscription 走 AJAX 必然失敗，直接不浪費 round trip）、未選變體、**「任意」屬性變體**（`get_variation_attributes()` 對這類屬性回空字串，`WC_Cart::add_to_cart()` 會濾掉空值後判定缺少必填屬性而拒絕；原生表單送的是使用者實際選的值，不受影響）、缺 `profit_shop_id`（寧可換頁，也不送出一筆沒有分潤標記的加購）。

**兩個 CSS 陷阱（都是實測踩到的）**

1. **`pc-alert-success` / `pc-alert-error` 在 `front.min.css` 內不存在**（daisyUI 的語意變體需要被掃到才產出），因此 toast 用中性的 `pc-alert` + 由圖示的 `text-success` / `text-error` 承載語意色，tone 切換直接複用檔內既有的 `applyIconTone()`。
2. **`whitespace-normal` 單獨使用會讓 toast 文字變直排**：`.pc-toast` 是 `position:fixed`，只加 `whitespace-normal` 會讓 shrink-to-fit 改用 **min-content** 寬度，而中文可在任意字元間斷行 → 整段文字被壓成一字一行。必須 `w-max`（`width:max-content`）配 `max-w-[min(24rem,90vw)]` 才會是正常的單行 / 折行。
3. `.pc-toast` **沒有** z-index，powerhouse 的 Tailwind 只產出到 `z-50`，佈景主題 sticky header 常在 999 以上 → toast 保留 `style="z-index:9999"` 靜態 inline style（這與 Phase 14 移除的「動態 `<style>` 區塊」是兩回事，那是 CSS injection 面，靜態屬性沒有此風險）。

**成功時先關 Modal 再顯示 toast**：原生 `<dialog>` 開啟時進入 **top layer**，body 層元素不論 z-index 多大都在它與 `::backdrop` 之下且因 inert 不可點。先關 Modal 讓這個問題結構性消失，不需要在每顆 dialog 內各印一份 toast。失敗時 Modal 不關，錯誤改由 Modal **內**的 `[data-ps-submit-error]` 就地顯示。

不引入新 npm / composer 套件；不動 Domain / Application 層與 `AddToCartHook` / `CartPriceOverrideHook` / `CartItemMetaDisplay` / `SingleProductPriceHook` 四個 hook 檔案（`git diff` 對它們完全空白）。

**同批處理：移除 /cart 與 /checkout 的「來自賣場 XXX」標記**

站長要求前台不顯示分潤來源。`Infrastructure/WooCommerce/CartItemMetaDisplay.php` **整檔刪除**（`Loader` 註冊一併移除），不是只拿掉註冊——它是 100% 純顯示（唯一作用是一個 `woocommerce_get_item_data` filter，往 `item_data` append 一筆），留著一個「只為了不執行而存在」的檔案只會讓下一個人困惑；專案先例是 Phase 12 把 `FrontCartApi` 直接刪檔。復原見 git commit `f813e07`。

安全性依據：定價鏈路（`AddToCartHook` 三閘門 / `CartPriceOverrideHook` 的 `_profit_*` meta 與 HMAC 簽章）與結算紀錄都在別的 class，完全不碰這個 filter。訂單頁與訂單 email **不需要另外處理**：line item 的 `_profit_*` meta 以底線開頭，WooCommerce 的 `get_formatted_meta_data()` 預設就會濾掉。實測（未登入訪客）：`/cart/` 與 `/checkout/` 的 HTML 皆 **0 命中**「來自賣場」/ 賣場名稱 / `/profit-shop/` 連結，Store API 的 `item_data` 為 `[]`，而**賣場覆寫價完全不受影響**（NT$300 劃線 → NT$240，總計 NT$240）。

⚠ 連帶讓 `specs/tech-debt.md` **TD-21**（Cart Block 下該標記顯示成 `undefined:`）移入「已解決」——但那是「功能不存在了」而不是「修好了」：**日後若恢復顯示，`undefined:` 問題會一併回來**，屆時要先解決 Store API 的鍵名對映再上線。

**驗證數據**（實測，2026-08-05）：

| 項目 | 結果 |
|---|---|
| `vendor/bin/phpcs inc/` | **137/137 全綠** |
| `php -l` × 4 檔 | 全過 |
| `node --check inc/assets/js/profit-shop-front.js` | 通過 |
| powerhouse `pnpm build-css:front` | 成功；新用到的 `w-max` / `max-w-[min(24rem,90vw)]` / `pc-toast*` / `pc-alert` / `pc-loading-spinner` / `pc-btn-xs` / `size-4` / `whitespace-normal` / `shadow-lg` 逐一 grep 確認已產出並 scope 進 `#tw` |

**真實站台端到端驗證**（`local-turbo.powerhouse.tw`，**未登入訪客**身分）：

| 驗證項 | 結果 |
|---|---|
| localize `power_shop_front_config` | `{addToCartUrl:"/?wc-ajax=add_to_cart", cartUrl:".../cart/", redirectAfterAdd:""}` |
| DOM 契約 8 項 | 全部存在；`profit_shop_id=1932`、`add-to-cart=65` |
| AJAX 加購（simple） | **不換頁**、按鈕 disabled + `aria-busy` + spinner、Modal 自動關閉、toast「已加入購物車」出現 |
| 購物車實收 | T-Shirt `price=240` / `regular=300` / `sale=240`（賣場覆寫價 + Phase 13 的劃線原價都正確）+ 分潤標記 |
| **分潤三閘門 A/B/C** | A 帶合法 `1932` → 240/300 + 有標記；B 不帶 → 18/18 + 無標記；C 偽造 `999999` → 18/18 + 無標記 |
| 併發防護 | 連按 3 次，購物車只 +1 件 |
| 變體 payload | 注入已選變體後攔截 fetch，送出 `product_id=730&quantity=1&profit_shop_id=2225`（**變體 ID，不是父商品 729**），Content-Type 為 `application/x-www-form-urlencoded` |
| 降級路徑 | 未選變體 → **不發 AJAX**、交給原生表單送出 |
| toast 版型 | `position:fixed top=0 left=50% zIndex=9999`，`grid-auto-flow:column`，236×54px 橫向排列，浮在佈景主題 sticky header 之上 |

⚠ **唯一未能端到端驗證的項目：variable 商品的 AJAX 正向路徑**。站上兩個賣場（`test-shop-alpha` / `123`）中唯一的 variable 商品（#729 `TEST`）其變體 730/731 **價格為 0、`is_purchasable: false`**，被 WooCommerce 的 `get_available_variations()` 濾掉，因此該商品在賣場前台**本來就無法加購**（與本次改動無關的測試資料問題）。已改以「攔截 fetch 驗證送出內容」+「讀 WooCommerce 原始碼確認 variation→parent 換算」兩段各自驗證，但**沒有**真的把一個變體商品用 AJAX 加進購物車。要補這一項，需要一個含可購買 variable 商品的賣場。

**新增技術債**：TD-41（業務失敗時仍需離開賣場頁才能看到 WooCommerce 原文訊息）、TD-42（WooCommerce Subscriptions 在 `woocommerce_add_to_cart_fragments` 上掛了不存在的靜態方法）、TD-43（variable AJAX 正向路徑尚未端到端驗證）。

### Phase 16（賣場開啟 / 關閉時間 + 期間外呈現方式）已完工（2026-08-05）

站長要求賣場編輯頁新增「開啟 / 關閉時間」分頁，可設定賣場只在特定期間內可見，並針對「尚未開賣」與「已結束販售」兩種狀態各自選擇前台要顯示哪些內容。呈現方式是**三個互相獨立的勾選框**（顯示倒數計時 / 顯示自訂文字 / 正常顯示商品但不能購買），可任意組合；**全部不勾＝什麼都不顯示**。

**這是 TD-22 的部分兌現**：`ProfitShop::$settings` 的 `startTime` / `endTime` 端到端本來就通（Repository / Hydrator / DTO / V2Api / `ProfitShopFrontData::settings()` 全鏈），只是後台從來沒有寫入口。本 Phase **後端 Domain / Application 層零修改**（`settings` 是自由 array，不需要新 DTO 欄位、不需要 migration、`V2Api` 一行未動），改動集中在「呈現層正規化 + template 分支 + vanilla JS 倒數 + 後台 Tab」四處。

| 層 | 內容 | 路徑 |
|---|---|---|
| 呈現層正規化 | `ProfitShopFrontData::settings()` 新增 9 個輸出欄位：`beforeDisplay` / `beforeText` / `afterDisplay` / `afterText`（站長的**原始意圖**，前兩者是 `string[]` 區塊陣列）+ `showCountdown` / `showDisplayText` / `displayText` / `showProducts` / `countdownTarget`（與當下 `shopStatus` 合併後的**結論**，template 只讀這組）。新增 `to_display_parts()`（白名單過濾 + 舊單選字串相容）與 `to_text()`（非字串 → 空字串、trim、以 codepoint 截斷至 200） | `Infrastructure/WordPress/ProfitShopFrontData.php` |
| 倒數盤 | `ProfitShopFrontView::countdown()` + `countdown_unit()`：daisyUI `pc-countdown`（large text with labels under），PHP 用**伺服器時間**印出首屏數字 | `Infrastructure/WordPress/ProfitShopFrontView.php` |
| Template 分支 | 讀 `showCountdown` / `showDisplayText` / `showProducts` 三個布林決定各區塊印不印。**Modal 的 `$is_shop_open` gate 完全不變** | `templates/profit-shop-front.php` |
| 前台 JS | `bindCountdown()` + `renderCountdownUnit()`：每秒遞減、> 99 切純文字、歸零後重新載入頁面 | `inc/assets/js/profit-shop-front.js` |
| 後台 Tab | 新增 `ScheduleTab`（單一提示 Alert + 左右兩欄：左欄「開啟時間 + 尚未開賣時」、右欄「關閉時間 + 已結束販售時」，各欄含 `DatePicker` + `Checkbox.Group` + 自訂文字欄）；`utils.ts` 新增 `toScheduleFormValues()` / `mergeScheduleSettings()`；`FIELD_TO_TAB` 加 `settings: 'schedule'` | `js/src/pages/admin/ProfitShop/` |
| 測試 | Unit +9（`ProfitShopFrontDataSettingsTest` 12 → 21）、IT +7（`ProfitShopShortcodeTest` 18 → 25） | `tests/` |

**七條必須記住的紀律：**

1. **預設值一定是 `['products']`**（`ProfitShopFrontData::DEFAULT_DISPLAY_PARTS`）。既有賣場的 `settings` 裡沒有 `beforeDisplay` / `afterDisplay` 兩個 key，預設若不是「只顯示商品」（＝本功能上線前「照印商品卡片、按鈕停用」的既有行為），全站已排程的賣場會在時間外**突然變成空白頁**。前後端各有一條回歸鎖（`test_display_parts_default_to_products_only` / `test_closed_shop_without_display_setting_keeps_browse_behaviour`）。
2. **🔴「沒設定」與「設定為空」必須用 `is_array()` 區分，不可用 `empty()`**。空陣列 `[]` 是**合法值**（站長刻意把三個勾選框全部取消＝什麼都不顯示），而 `empty([])` 為 true——用它判斷會讓站長的取消勾選被靜默還原成「顯示商品」，改了設定卻沒有反應且沒有任何錯誤訊息。前端同理：用 `Array.isArray()`，不可用 `value?.length` 之類的真值判斷。
3. **任何區塊組合都不印商品 Modal**——`$is_shop_open` 那道 gate 一行未改。Modal 內含一張真正的 `<form method="post">`，而 `AddToCartHook` 的三道閘門**不檢查販售起訖時間**，接不住「把 `disabled` 屬性拔掉再送出」。`test_no_display_combination_ever_renders_modal_when_closed` 對 5 種組合逐一鎖死。
4. **`showCountdown` / `showDisplayText` / `showProducts` 由 `settings()` 算好，template 只讀結論**。`shopStatus` 有三態、呈現設定有兩組，若讓 template 自己配對，獨立頁面與 shortcode 兩條路徑就各有一份配對邏輯要維持一致——這個 codebase 已經因為「靠人維持兩份邏輯逐字一致」吃過虧（Phase 13 的 `OverridePriceResolver`）。「勾了自訂文字但文字是空的」也在這一層收斂成 `false`，template 不會印出空的 `<p>`。
5. **後台送出 `settings` 一定要以既有 settings 展開為底**（`mergeScheduleSettings(record.settings, values.settings)`）。`settings` 是自由 array，裡面還有 `btnColor` / `showStock` / `showBuyerCount` / `showConfetti` 與 legacy 匯入帶進來的未知 key，只送排程欄位會把它們**整批清掉且沒有任何錯誤訊息**。
6. **清空的時間寫 `0`，不是刪 key**。`base` 裡可能已有舊值，刪 key 不會覆蓋它——症狀是「時間清掉了，存檔後又跑回來」。PHP 端 `to_timestamp_ms()` 把 0 正規化為「未設定」。
7. **daisyUI `pc-countdown` 只支援 0–99**（`::before` 的 content 是 `"00\A 01\A … 99"` 字面值，`--value` 超過就捲出範圍、**顯示成空白**）。天數欄位因此同時印出 `.pc-countdown` 與純文字兩格，用 `tw-hidden` 切換；**不可**改用 `tw-inline` 之類的 display utility 做切換（Pitfall #27）。
8. **🔴 字級 utility 必須掛在倒數盤的外層 `<div>`，不可掛在 `.pc-countdown` 本身**（即使 daisyUI 官方範例就是那樣寫的）。powerhouse 的 `#tw` scope 讓 `.text-*` 的 (1,1,0) 蓋掉 daisyUI 根部錨定的 `:root .pc-countdown{line-height:1em}` (0,2,0)，行高與 `1em` 一不相等，倒數盤每一格就累積偏移、變成「上下兩個數字各露一半」，且**沒有任何錯誤訊息**。實測踩過一次（`text-3xl`：font 30px / line-height 36px，每格偏 6px）。完整機制與為何「移到外層」是決定性修法（而非用 `leading-none` 對打）見 Common Pitfall #29 與 `ProfitShopFrontView::countdown_unit()` docblock。**修正後實測**：四格 `font-size = line-height = height = 36px`，`::before` 的 `top` 精確等於 `-值 × 36px`。

⚠️ `to_display_parts()` 另有一條**舊格式相容分支**：這個設定最初實作成單選字串（`hidden` / `countdown` / `browse`），同一天內改成多選，開發中的資料庫可能還留著字串值，因此對映成等價的區塊組合（`hidden` → `[]`、`countdown` → `['countdown','text']`、其餘 → 預設）而不是丟掉。前端 `toDisplayParts()` 有對應的同款分支。這批相容碼**沒有正式發版過**，確認所有開發環境都已重新存檔後可以移除。

**其他刻意的取捨**（都已登記 tech-debt）：TD-44 時間以瀏覽器時區呈現（`useEnv()` 沒有站台時區，加它要動共用 env 契約）／TD-45「已結束 + 倒數」盤面一律歸零（沒有未來目標可倒數，不猜測）／TD-46「全部不勾」時公開網址仍回 200（改 404 會打破兩條路徑 DOM 一致的核心回歸鎖）／TD-47 排程欄位無後端 Domain 驗證（`settings` 沒有對應 VO，後果有界：時間顛倒只會讓賣場永不開賣）。

**驗證數據**（實測，2026-08-05）：

| 項目 | 結果 |
|---|---|
| `vendor/bin/phpcs inc/` | **136/136 全綠** |
| `php -l` × 5 檔 / `node --check` | 全過 |
| `npx tsc --noEmit` | **127**（= baseline，零回歸） |
| Unit testsuite | **411 tests / 869 assertions / 1 failure**（+9 新增；failure 為 pre-existing TD-2） |
| Integration testsuite | **243 tests / 1061 assertions / 15 failures**（+7 新增；15 條全部 pre-existing，與 Phase 14 baseline 同一批） |
| `pnpm build` | 成功 38.33s |
| CSS class 覆蓋 | 新用到的 class 逐一比對 powerhouse `front.min.css` / `admin.min.css`，**全部已產出**（刻意避開沒產出的 `my-12` / `max-w-2xl` / `text-base-content/70` / `leading-none` / **所有 `md:text-*` 響應式字級**，改用已存在的等效 class，因此**不需要重跑 powerhouse 的 CSS build**） |
| `npx eslint` | ⚠ 無法執行（`unrs-resolver` native binding 載入失敗，pre-existing 環境問題，見 TD-31） |

**真實站台驗證**（`agent-browser`，賣場 `test-shop-alpha` 設 2 天後開賣 + 勾「倒數計時 + 自訂文字」）：

| 驗證項 | 結果 |
|---|---|
| 倒數盤四格計算後樣式 | `font-size = line-height = height = 36px`（三者相等是元件正確運作的充要條件） |
| `::before` 位移 | days=2 → `-72px`、hours=7 → `-252px`、minutes=57 → `-2052px`，精確等於 `-值 × 36px` |
| 視覺 | 截圖確認 `02 07 57 16` + 天/時/分/秒 標籤，無裁切、無錯位 |

⚠ **仍未驗證**：「倒數歸零後自動重新載入」這條時間相關行為（要等實際倒數到 0，或另外造一個數秒後開賣的賣場）。

⚠ 驗證過程踩到 TD-32：`curl` 抓賣場頁拿到的是 **WP Rocket 的舊快取**（內容還是 Phase 14 之前的 inline style 版），一度誤判「倒數區塊沒印出來」。網址加 `?nocache=xxx` 才看得到真實渲染結果，已寫進 Common Pitfall #30。

### Phase 5+ 預告（reviewer 順手清單彙整 + 產品決策層）

> 本清單於 Phase 9 文件同步時清過一輪：原第 8、9、13 項（賣場前台 IP-based rate-limit / 賣場前台快取效能 / `ProfitShop::has_item` Domain method）核對程式碼後確認**已分別在 Phase 5-A.1（`PageRateLimitService` + `ProfitShopRenderer::maybe_render` 的 429 處理）與 Phase 5-B（`ProfitShopRenderer` publish 不送 `nocache_headers()` 條件化 + `ProfitShop::has_item()`，後者現由 Phase 9 `SingleProductPriceHook` 直接消費）完工**，只是 commit 之後這份清單沒有跟著移除，予以刪除並重新編號。本清單已於 Phase 14 文件同步時再清過一輪：原第 10 項（賣場前台 inline style 抽 CSS file + `img loading='lazy'`）核對後確認**已分別在 Phase 14（inline style 隨 React bundle 一併移除，樣式全改用 daisyUI / Tailwind class，已無任何 inline `<style>`）與更早的 Phase 10（`img loading='lazy'` 已內建於 `templates/partials/profit-shop-card.php` 的商品圖）完工**，予以刪除並重新編號；其餘項目核對後仍未完工，維持原樣。

優先順序：
1. **LoginRateLimiter context-aware message provider**（reviewer 順手：audit log + admin email 在 `pwchange:{id}` pseudo-slug 場景目前語意誤導為「登入失敗」，需抽 message provider 區分 login / pwchange context）
2. **partner-reports/{kpi,trend,settlements} 三 callback 缺 `_partner_term_id <= 0` fail-fast 一致性**（reviewer 順手，與 6-A1 L-4 對齊）
3. **PartnerPasswordTest 補 multibyte / emoji / 中文邊界測試**（reviewer 順手，現有 8 method 已涵蓋 ASCII codepoint 但未顯式驗證 emoji / 中文與英文混排）
4. **密碼強度規則升級**（產品決策層，例如 NIST SP 800-63B 12 字元 / pwned password 黑名單）
5. **6-A2 OBS 順手 3 條**：partnerExceptionMapper context-aware DRY 重構（LOW-7 master 自決跳過：兩函式 80% 相同但語意差異點需保留 caller） / Login `?reason=` query string 多 reason 擴充（目前僅 `password_changed`） / ChangePassword 失敗清欄位策略文件化到 react.rule.md HIGH-RISK 表
6. **'power-shop' dataProvider thin wrapper**（Phase 4-A1 自決踩坑紀錄遺留）：包 `{code, data}` 讓未來頁面能用 useTable / useForm
7. **賣場前台 SEO 強化**：`pre_get_document_title` filter（與 SEO plugin 整合，4-C1 m-3）/ Open Graph / structured data
8. **rate-limit 5/15min 改 3 次**（reviewer L-3，產品決策層）
9. **token 三條讀取路徑收斂**（reviewer M-5，目前已 PHPDoc 化但未刪路徑）
10. **變體層 `inflated_count` 前台顯示**（Phase 9 R-2 未竟事項，見 `specs/tech-debt.md` TD-15）：賣場前台目前只渲染父商品卡片，變體選擇整段發生在 WC 單品頁；要顯示變體層灌水人數需另外介入單品頁渲染，範圍大於商品層
11. **變體查詢改走分頁載入**（Phase 9 R-4，見 `specs/tech-debt.md` TD-16）：`wc-rest` `per_page` 硬上限 100，變體超過 100 個的商品目前只顯示前 100 個

### Reviewer 累積的「未來補強建議」（非 blocking）

由 Phase 3-D Batch 1-3 的 reviewer 順手清單彙整（已於 Batch 4 解決部分）：

- [ ] **security L-1**：全域 mass-spray rate-limit（1000/hour 跨任意 slug + IP）
- [ ] **security M-4**：`error_log` 路徑安全提示寫入 README / 設計文件
- [ ] **T-3 補測**：`PartnerAuthServiceTest` 加 unknown slug + IP-only record 端到端測試
- [ ] **T-3 文件**：`specs/2026-05-06-profit-shop-design.md` §6 加 IP 偵測信任邊界章節 + `power_shop_partner_login_client_ip` filter hook 用法範例
- [ ] **T-8 perf**：`reset_to_original_price` 的 N+1 query micro-cache
- [ ] **T-8 邊界**：`before_calculate_totals` priority 999 對 `PHP_INT_MAX` 的其他 plugin 防護評估
- [ ] **rate-limit 5/15min 改 3 次**（reviewer L-3，產品決策層）
- [ ] **token 三條讀取路徑收斂**（reviewer M-5，目前已 PHPDoc 化但未刪路徑）

## Specs

Complete specs live in `specs/` using AIBDD Discovery multi-view architecture. See `specs/README.md` for the full index.

| View | Path | Count |
|------|------|-------|
| Activity | `specs/activities/*.activity` | 4 |
| Feature | `specs/features/**/*.feature` | 20 (12 command + 8 query) |
| UI | `specs/ui/*.md` | 9 pages |
| API | `specs/api/api.yml` | OpenAPI 3.0 |
| Entity | `specs/entity/erm.dbml` | 9 tables |

> **已知問題與技術債**：跨領域、暫不處理的項目（測試環境缺口、CI 設定、第三方套件契約落差、既有 UX 限制等）統一登記在 `specs/tech-debt.md`。單一領域內的已知落差（例如列表頁版型尚未對齊）記在對應的 `.claude/rules/*.rule.md`。
