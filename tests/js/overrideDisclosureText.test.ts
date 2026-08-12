/**
 * 據實揭露文案與實際作用範圍的一致性
 *
 * 對應規格：specs/features/profit-shop/apply-single-product-override-price.feature
 *   Rule: 文案（一致）- 後台的據實揭露文案必須與實際作用範圍一致
 *
 * ── 這條 Rule 是為了 TD-20 那次失誤而存在 ────────────────────
 *
 * 曾經發生：規劃階段先寫了「單品頁仍顯示原價」的緩解文案，同一次改動後來把
 * `SingleProductPriceHook`（單品頁套價）做出來了，**但沒有人回頭改那句話**。
 * 於是後台對站長說的話，與系統實際的行為**正好相反**——而
 * lint / tsc / PHPUnit **一個都攔不到**，因為那只是一句中文。
 *
 * 本專案已經因為同型失誤吃虧三次（TD-20 / TD-54 / TD-67：註解或文案的宣稱
 * 與實際行為相反）。這個檔案是目前唯一會對這類落差轉紅的東西。
 *
 * ⚠️ 這裡驗的是**文字內容**，不是執行期行為，所以讀原始碼是正確的做法而非取巧：
 * 要守的東西本來就住在那段文字裡。
 */
import { describe, it, expect } from 'vitest'
import * as fs from 'node:fs'
import * as path from 'node:path'

const src = fs.readFileSync(
	path.resolve(
		process.cwd(),
		'js/src/pages/admin/ProfitShop/components/VariationOverrideEditor/index.tsx'
	),
	'utf-8'
)

describe('變體覆寫編輯區的說明必須涵蓋四條顧客動線', () => {
	// 站長要求把那張 info Alert 移除（展開列已經夠長，每展開一個商品就重複一次），
	// 內容整段搬進元件 docblock。因此這幾條驗的是 docblock——
	// 說明的載體換了位置，但「必須說清楚四條動線」這條規則沒有變。

	// ⚠️ 刻意逐條寫 `it()` 而非用 `it.each`：`bin/check-traceability.php` 是拿
	// `.feature` 的 `@covered-by` 標題去原始碼裡比對子字串，而 `it.each` 的
	// 標題模板（`'…「%s」…'`）在原始碼裡永遠是未展開的字面值，對不上——
	// 症狀是明明測試全綠、traceability 卻報「指到不存在的方法」。

	it('說明必須交代「賣場前台卡片」這條動線', () => {
		expect(src).toMatch(/賣場前台卡片/)
	})

	it('說明必須交代「從賣場進入的商品單頁」這條動線', () => {
		expect(src).toMatch(/從賣場點進商品單頁/)
	})

	it('說明必須交代「加入購物車 / 結帳」這條動線', () => {
		expect(src).toMatch(/加入購物車\s*\/\s*結帳/)
	})

	it('說明必須交代「未經賣場直接開啟商品頁」這條動線', () => {
		expect(src).toMatch(/未經賣場直接開啟商品頁/)
	})

	it('必須明講「未帶 profit_shop_id 看到原始價格」是刻意設計而非缺口', () => {
		// 少了這句，下一個人會把它當成 bug 去「修好」——而那會讓覆寫價
		// 脫離賣場情境、套用到所有訪客身上
		expect(src).toMatch(/刻意保留/)
		expect(src).toMatch(/不是缺口/)
	})
})

describe('🔴 不得出現已經與事實相反的宣稱', () => {
	it('不得再宣稱「單品頁仍顯示原價」（TD-20 的原句）', () => {
		// SingleProductPriceHook 上線後，帶 profit_shop_id 的單品頁**會**顯示覆寫價。
		// 這句話一旦復活，站長會照著它做出錯誤的定價決策。
        //
        // ⚠️ 比對的是「單品頁 + 仍顯示原價」這個組合，不是單一詞彙——
        // 現行文案裡「原始價格」是合法出現的（用於描述未帶 shop_id 的情境）。
		expect(src).not.toMatch(/單品頁[^。\n]{0,20}仍(顯示|為)原價/)
		expect(src).not.toMatch(/單品頁[^。\n]{0,20}不會套用/)
	})

	it('變體層灌水人數必須據實標示為「尚未顯示」', () => {
		// 商品層的 Tooltip 寫「前台會顯示 真實 + 灌水購買人數」，那對商品層即將成真；
		// 但對變體層是**假的**——賣場前台只渲染父商品卡片，變體層灌水人數
		// 目前沒有任何可顯示的位置（TD-15）。兩處文案刻意不同，不要「順手統一」。
		expect(src).toMatch(/僅會儲存/)
		expect(src).toMatch(/尚未顯示/)
	})

	it('🔴 變體層與商品層的灌水人數文案必須不同', () => {
		// 若有人把商品層那句「前台會顯示」複製過來，站長會以為設了就會顯示，
		// 然後在前台找不到而回報「功能壞掉」——實際上是文案對他做了做不到的承諾。
		//
		// ⚠️ 只比對**使用者看得到的字串**（`title="…"`），不比對整份原始碼：
		// VariationOverrideEditor 的註解**刻意引用**了商品層那句話來解釋
		// 兩者為何必須不同，那是正當的，不該被判成違規。第一版沒分開，
		// 把那段解釋用的引用當成了複製貼上。
		const titles = (source: string) =>
			[...source.matchAll(/title="([^"]*)"/g)].map((m) => m[1] ?? '').join('\n')

		const itemsEditor = fs.readFileSync(
			path.resolve(
				process.cwd(),
				'js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx'
			),
			'utf-8'
		)
		const productLevelPromise = /前台會顯示/

		expect(titles(itemsEditor)).toMatch(productLevelPromise)
		expect(titles(src)).not.toMatch(productLevelPromise)
	})
})

describe('fallback 語意的說明必須與 OverridePriceResolver 一致', () => {
	it('說明必須寫明「整組退回」而非逐欄混用', () => {
		// OverridePriceResolver 的契約：變體只要有**任一**覆寫欄位，就整組採用變體層，
		// 不與商品層逐欄混用。文案若寫成逐欄 fallback，站長會以為
		// 「只設特價、原價自動沿用商品層」——實際上原價會維持 null。
		expect(src).toMatch(/整組退回/)
		expect(src).toMatch(/不是逐欄混用/)
	})

	it('說明必須寫明「留空 ＝ 不覆寫、填 0 ＝ 覆寫為 0 元」', () => {
		// 這組語意是價格欄最容易誤解的一點，且兩者的後果差距極大
		//（沿用原價 vs 變成免費贈品）
		expect(src).toMatch(/留空\s*＝\s*不覆寫/)
		expect(src).toMatch(/填\s*0\s*＝\s*覆寫為\s*0\s*元/)
	})
})
