/**
 * legacy 一頁商店遷入的四道閘門
 *
 * 對應規格：specs/features/profit-shop/import-legacy-shop.feature
 *   Rule: 前端（閘門）- 不可逆操作必須經過四道閘門
 *
 * ── 遷入是不可逆的，而且沒有「復原」按鈕 ──────────────────────
 *
 * 匯入會建立一個新的分潤賣場並把 legacy 資料搬過去。誤觸之後只能手動刪掉
 * 新賣場再重來，而站長通常要等到看見兩個一模一樣的賣場才會發現。
 */
import { describe, it, expect } from 'vitest'
import {
	CONFIRM_PHRASE,
	canSubmitImport,
} from '@/pages/admin/ProfitMigration/ImportModal'

const ok = {
	legacyShop: { importable: true },
	partnerId: 34,
	confirmText: CONFIRM_PHRASE,
	isLoading: false,
}

describe('canSubmitImport — 四道閘門', () => {
	it('四道全過時可送出', () => {
		expect(canSubmitImport(ok)).toBe(true)
	})

	it('🔴 每一道閘門都能獨立擋下送出', () => {
		// 逐一翻掉單一條件，確認每一道都是**必要**的——
		// 若某一道其實可有可無，這裡會綠，那就代表它是裝飾而不是閘門
		const cases: Array<[string, Partial<typeof ok>]> = [
			['legacyShop 為 null', { legacyShop: null }],
			['importable 為 false', { legacyShop: { importable: false } }],
			['未選擇分潤夥伴', { partnerId: null }],
			['確認字串未輸入', { confirmText: '' }],
			['正在送出中', { isLoading: true }],
		]
		cases.forEach(([label, override]) => {
			expect(canSubmitImport({ ...ok, ...override }), label).toBe(false)
		})
	})

	it('🔴 確認字串必須完整大寫（避免「import」誤觸）', () => {
		// 小寫的 import 是使用者打字時最可能出現的形式。
		// 大小寫不敏感的比對等於把「二次確認」降級成「隨便打點什麼」。
		for (const wrong of ['import', 'Import', 'IMPORt', ' IMPORT', 'IMPORT ']) {
			expect(canSubmitImport({ ...ok, confirmText: wrong }), wrong).toBe(false)
		}
		expect(canSubmitImport({ ...ok, confirmText: 'IMPORT' })).toBe(true)
	})

	it('🔴 importable 必須在這裡再驗一次，不能只信列表的 disabled', () => {
		// 列表資料可能是快取的：站長開著頁面時，另一個人剛好匯入了同一個賣場。
		// 只靠列表的 disabled 會讓那顆按鈕仍然可按，然後匯入第二份。
        expect(canSubmitImport({ ...ok, legacyShop: { importable: false } })).toBe(false)
	})

	it('🔴 未選夥伴一律擋下（沒有分潤對象的賣場毫無意義）', () => {
		// legacy 一頁商店沒有 partner 概念，遷入後必須掛在某個夥伴下。
		// 漏掉的話會產生一個永遠不會產生分潤紀錄的賣場，
		// 而它在列表上看起來與正常賣場一模一樣。
		expect(canSubmitImport({ ...ok, partnerId: null })).toBe(false)
	})

	it('🔒 現況鎖：partnerId 為 0 時**會**放行（判斷是 `!== null`）', () => {
		// 寫測試時預期這裡會擋，實測發現不會——判斷式是 `partnerId !== null`，
		// 而 `0 !== null` 為真。記錄現況而不是順手改行為，理由是這條路徑
		// **走不到**，改了只是增加一個沒有對應情境的分支：
		//
		//   - PartnerSelector 的 option value 取自 `p.id`（後端 term id，恆 ≥ 1），
		//     清除時走 `onChange(null)`——UI 上產生不出 0
		//   - 即使產生得出來，後端會以 404「Partner term 0 不存在」擋下，
		//     不會建立出沒有分潤對象的賣場
		//
		// ⚠️ 這條轉紅代表有人把判斷改成 `> 0`（那是好事，但請一併確認
		// PartnerSelector 的 null / 0 語意仍然一致），或 PartnerSelector
		// 的 value 來源改了——後者才是真正需要警覺的情況。
		expect(canSubmitImport({ ...ok, partnerId: 0 })).toBe(true)
	})

	it('飛行中停用（重複送出會匯入兩份）', () => {
		expect(canSubmitImport({ ...ok, isLoading: true })).toBe(false)
	})
})
