/**
 * 賣場 / 夥伴列表操作欄的動作判定
 *
 * 對應規格：
 *   specs/features/profit-shop/change-shop-status.feature
 *     Rule: 前端（列表）- 操作欄依賣場狀態與模式呈現不同的主要動作
 *   specs/features/profit-shop/render-shop-via-shortcode.feature
 *     Rule: 前端（後台）- 短碼模式的賣場在列表提供複製短碼
 *   specs/features/profit-shop/manage-partner-credentials.feature
 *     Rule: 前端（列表）- 無明文密碼的夥伴不提供複製入口
 *
 * ── 四個判定函式看起來 80% 相同，差異點才是全部的價值 ────────
 *
 * `admin-list-ui.rule.md` §1 明文寫著「別為了 DRY 併成一個」，因為併掉會各自
 * 產生一個具體的損失：
 *   - 併掉 slug 條件 → 沒設永久連結的 shortcode 賣場白白失去複製入口
 *   - 併掉 status 條件 → 草稿 / 已刪除賣場白白失去夥伴報表入口
 *
 * 本檔因此**刻意逐條測差異**，而不是只測「四個都會擋 trash」。
 * 若日後有人把它們合併，這裡會立刻紅——那正是這些測試存在的理由。
 *
 * ── ⚠️ 但第四個（複製密碼）是那條規則的**反例**，方向相反 ──────
 *
 * `resolveCopyPartnerPasswordBlockedReason` 刻意**跨 `#/profit-partner` 與
 * `#/profit-shop` 兩頁共用**，這不違反上面那條規則。那條規則防的是
 * 「條件看似相同、但差異點才是關鍵」（開啟要 slug、複製短碼不要 slug、
 * 查看報表連賣場狀態都不看——三者檢查的根本是不同的東西）。
 * 而複製密碼在兩頁複製的是**同一個東西**（`partner.password`），
 * 條件與訊息逐字相同，各寫一份反而會分家：改了一頁的措辭、另一頁沒改，
 * 同一列相鄰的兩顆按鈕會對同一個狀況給出兩種說法。
 *
 * ⇒ 本檔因此同時鎖住兩個方向：
 *   1. 該分開的必須分開（差異矩陣）
 *   2. 該一致的必須逐字一致（跨頁共用矩陣）
 */
import { describe, it, expect } from 'vitest'

import type { TPartnerQueryState } from '@/pages/admin/ProfitPartner/types'
import { resolveCopyPartnerPasswordBlockedReason } from '@/pages/admin/ProfitPartner/utils'
import {
	resolveCopyShortcodeBlockedReason,
	resolveOpenShopBlockedReason,
	resolvePartnerQueryState,
	resolveViewReportBlockedReason,
} from '@/pages/admin/ProfitShop/List'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	buildProfitShopShortcode,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'

const shop = (over: Partial<TProfitShop> = {}): TProfitShop =>
	({
		id: 1932,
		title: '測試賣場',
		slug: 'test-shop',
		status: PROFIT_SHOP_STATUS.PUBLISH,
		mode: PROFIT_SHOP_MODE.PAGE,
		...over,
	}) as TProfitShop

const partner = (over: Record<string, unknown> = {}) =>
	({
		id: 34,
		name: '夥伴 A',
		slug: 'partner-a',
		report_url: 'https://example.com/profit-report/partner-a/',
		password: 'Abc12345',
		...over,
	}) as never

describe('「開啟」按鈕', () => {
	it('正常賣場可開啟', () => {
		expect(resolveOpenShopBlockedReason(shop())).toBeNull()
	})

	it('沒有永久連結時停用（組不出前台網址）', () => {
		expect(resolveOpenShopBlockedReason(shop({ slug: '' }))).toContain('永久連結')
	})

	it('已刪除的賣場停用（後端一律回 404）', () => {
		expect(
			resolveOpenShopBlockedReason(shop({ status: PROFIT_SHOP_STATUS.TRASH }))
		).toContain('已刪除')
	})

	it('草稿賣場**可以**開啟（站長預覽用）', () => {
		// 後端 ProfitShopRenderer 對 draft 走 edit_post 權限檢查後允許預覽，
		// 前端擋掉的話站長就沒有預覽入口了
		expect(
			resolveOpenShopBlockedReason(shop({ status: PROFIT_SHOP_STATUS.DRAFT }))
		).toBeNull()
	})
})

describe('「複製短碼」按鈕', () => {
	it('正常賣場可複製', () => {
		expect(resolveCopyShortcodeBlockedReason(shop())).toBeNull()
	})

	it('🔴 沒有永久連結時**仍可**複製——這是與「開啟」的唯一差異', () => {
		// 短碼以 `id` 定位賣場（見 buildProfitShopShortcode），不需要 slug。
		// 為了 DRY 把兩個函式併成一個，會讓沒設永久連結的 shortcode 賣場
		// 白白失去複製入口——而那正是 shortcode 模式最常見的用法
		//（站長根本不打算用獨立網址，自然不會去設 slug）。
		expect(resolveCopyShortcodeBlockedReason(shop({ slug: '' }))).toBeNull()
		expect(resolveOpenShopBlockedReason(shop({ slug: '' }))).not.toBeNull()
	})

	it('已刪除的賣場停用（短碼貼上後什麼都不顯示，且無錯誤提示）', () => {
		expect(
			resolveCopyShortcodeBlockedReason(shop({ status: PROFIT_SHOP_STATUS.TRASH }))
		).toContain('已刪除')
	})

	it('短碼以 id 組成，與 slug 完全無關', () => {
		expect(buildProfitShopShortcode(1932)).toBe('[power_shop_profit_shop id="1932"]')
	})
})

describe('resolvePartnerQueryState — react-query 兩個布林 → 三態', () => {
	// 🔴 這個函式是「載入失敗 ≠ 找不到夥伴」這條推導的收斂點。
	// 它壞掉的話，下面兩個 resolver 全部跟著錯，而症狀是站長在
	// #/profit-shop 看到「每一列的分潤夥伴都被刪除了」。
	it('載入中 → loading', () => {
		expect(resolvePartnerQueryState(true, false)).toBe('loading')
	})

	it('🔴 查詢失敗 → error，不可退化成 ready', () => {
		// react-query v4 在查詢失敗後會把 isLoading 設回 false 而 data 仍是
		// undefined。少了這一態，`GET /profit-partners` 回 500 / 403 時
		// **每一列**都會落進「找不到夥伴」分支——站長可能因此去重建夥伴、
		// 或懷疑資料損毀，實際上只是一次查詢失敗、重新整理就好。
		expect(resolvePartnerQueryState(false, true)).toBe('error')
	})

	it('都不是 → ready（此時 partner 為 undefined 才真的是「找不到」）', () => {
		expect(resolvePartnerQueryState(false, false)).toBe('ready')
	})

	it('loading 優先於 error（兩者理論上不同時為真，但順序要確定）', () => {
		expect(resolvePartnerQueryState(true, true)).toBe('loading')
	})
})

describe('「查看報表」按鈕', () => {
	it('夥伴存在且有報表網址時可查看', () => {
		expect(resolveViewReportBlockedReason(partner(), 'ready')).toBeNull()
	})

	it('🔴 賣場的 status 完全不影響報表入口', () => {
		// 報表屬於**分潤夥伴**（/profit-report/{partner-slug}/），不屬於賣場。
		// 夥伴的報表涵蓋他名下所有賣場，某個賣場是草稿或已刪除，
		// 都不影響那張報表存不存在、也不影響其他賣場的數字。
		//
		// 這條的驗法是「函式簽章根本收不到賣場」——它只吃 partner 與 state，
		// 因此賣場狀態在結構上就影響不了它。若有人為了 DRY 把賣場塞進來，
		// 這條測試的存在會讓那個改動變得刺眼。
		//
		// ⚠️ 第二參數從 `isLoading: boolean` 改成三態 `TPartnerQueryState` 時，
		// 參數**個數**刻意維持 2——這條斷言守的是「不准多收一個賣場」，
		// 不是「不准換型別」，所以換型別時不需要（也不該）放寬它。
		expect(resolveViewReportBlockedReason).toHaveLength(2)
		expect(resolveViewReportBlockedReason(partner(), 'ready')).toBeNull()
	})

	it('🔴 載入中的訊息必須與「真的找不到」區分', () => {
		// 不區分的話，每次進頁面都會先閃一次「夥伴已被刪除」的假警告，
		// 站長會以為資料出問題而跑去檢查
		const loading = resolveViewReportBlockedReason(undefined, 'loading')
		const notFound = resolveViewReportBlockedReason(undefined, 'ready')

		expect(loading).toContain('載入中')
		expect(notFound).toContain('找不到')
		expect(loading).not.toBe(notFound)
	})

	it('🔴 載入失敗的訊息必須與「真的找不到」區分', () => {
		// 查詢失敗 ≠ 資料不存在。說成「已被刪除」會讓站長對著一批其實好好的
		// 資料採取補救動作（重建夥伴、懷疑資料損毀）。
		const failed = resolveViewReportBlockedReason(undefined, 'error')
		const notFound = resolveViewReportBlockedReason(undefined, 'ready')

		expect(failed).toContain('載入失敗')
		expect(failed).not.toContain('找不到')
		expect(failed).not.toBe(notFound)
	})

	it('載入中即使已有夥伴資料也先停用', () => {
		expect(resolveViewReportBlockedReason(partner(), 'loading')).toContain('載入中')
	})

	it('載入失敗即使已有夥伴資料也先停用（資料可能是上一次的殘留）', () => {
		expect(resolveViewReportBlockedReason(partner(), 'error')).toContain('載入失敗')
	})

	it('夥伴沒有報表網址時停用（契約是 nullable，照實處理不用 ! 斷言）', () => {
		expect(
			resolveViewReportBlockedReason(partner({ report_url: null }), 'ready')
		).toContain('報表網址')
	})
})

describe('「複製密碼」按鈕（跨兩頁共用的第四個判定）', () => {
	it('夥伴存在且有明文密碼時可複製', () => {
		expect(resolveCopyPartnerPasswordBlockedReason(partner(), 'ready')).toBeNull()
	})

	it('🔴 沒有明文密碼時停用——這是舊資料的合法狀態，不是錯誤', () => {
		// password 為 null 代表這位夥伴建立於「保存明文」功能上線之前，
		// DB 裡只有不可逆的雜湊。UI 若顯示空白，站長會誤以為密碼是空的。
		expect(
			resolveCopyPartnerPasswordBlockedReason(partner({ password: null }), 'ready')
		).toContain('無法還原密碼')
	})

	it('空字串密碼同樣停用（後端把空密碼正規化為 null，前端不該只判 null）', () => {
		expect(
			resolveCopyPartnerPasswordBlockedReason(partner({ password: '' }), 'ready')
		).not.toBeNull()
	})

	it('🔴 訊息必須明說是「分潤夥伴編輯頁」，不能只說「編輯頁」', () => {
		// 舊措辭是「請進編輯頁重設一次密碼」。這個函式現在也被 #/profit-shop
		// 呼叫，而那一列點進去是**賣場**編輯頁——四個 Tab 都沒有密碼欄位。
		// 站長會翻遍找不到、認定系統壞了。
		//
		// 這正是「共用一個函式」必須付的代價：訊息措辭得在**兩個**呼叫端
		// 都成立，不能只顧著自己那一頁讀起來順。
		const msg = resolveCopyPartnerPasswordBlockedReason(
			partner({ password: null }),
			'ready'
		)
		expect(msg).toContain('分潤夥伴編輯頁')
	})

	it('查不到夥伴時停用（#/profit-shop 以 partner_term_id 反查，可能查不到）', () => {
		// ⚠️ 這條分支在 #/profit-partner 不可達（該頁一列就是一個夥伴，
		// record 必然存在），但**不可刪**——跨頁共用的另一端會走到。
		expect(resolveCopyPartnerPasswordBlockedReason(undefined, 'ready')).toContain(
			'找不到'
		)
	})

	it('簽章與「查看報表」對稱：同樣只吃 (partner, state) 兩個參數', () => {
		expect(resolveCopyPartnerPasswordBlockedReason).toHaveLength(2)
	})
})

describe('🔴 「報表」與「複製密碼」對同一狀況必須逐字同一句話', () => {
	// 這兩顆按鈕在 #/profit-shop 是**同一列相鄰**的兩顆。同一個狀況若兩顆
	// 給出不同說法（一顆說「載入失敗」、一顆說「已被刪除」），站長會以為
	// 是兩件不同的事，而實際上只是一次查詢失敗。
	//
	// 這是 resolveCopyPartnerPasswordBlockedReason 跨頁共用的**唯一理由**，
	// 也因此是唯一值得鎖的性質：各寫一份的話，改了一邊的措辭另一邊不會跟著改，
	// 而那種漂移沒有任何自動化訊號會發現。
	const both = (p: never | undefined, s: TPartnerQueryState) => ({
		report: resolveViewReportBlockedReason(p, s),
		copyPw: resolveCopyPartnerPasswordBlockedReason(p, s),
	})

	it('載入中：兩顆逐字相同', () => {
		const { report, copyPw } = both(undefined, 'loading')
		expect(report).toBe(copyPw)
		expect(report).toContain('載入中')
	})

	it('載入失敗：兩顆逐字相同', () => {
		const { report, copyPw } = both(undefined, 'error')
		expect(report).toBe(copyPw)
		expect(report).toContain('載入失敗')
	})

	it('查不到夥伴：兩顆逐字相同', () => {
		const { report, copyPw } = both(undefined, 'ready')
		expect(report).toBe(copyPw)
		expect(report).toContain('找不到')
	})

	it('已有夥伴資料但仍在載入 / 失敗：兩顆仍逐字相同', () => {
		const loading = both(partner(), 'loading')
		const failed = both(partner(), 'error')
		expect(loading.report).toBe(loading.copyPw)
		expect(failed.report).toBe(failed.copyPw)
	})

	it('🔴 但欄位缺失時必須分家：報表看 report_url、複製密碼看 password', () => {
		// 共用的是「夥伴查詢狀態」這一段，不是整個判定。兩者最後檢查的欄位
		// 本來就不同——若有人把這段也一起併掉，會出現
		// 「沒有報表網址的夥伴連密碼都複製不了」這種毫無道理的停用。
		const noUrl = partner({ report_url: null })
		expect(resolveViewReportBlockedReason(noUrl, 'ready')).toContain('報表網址')
		expect(resolveCopyPartnerPasswordBlockedReason(noUrl, 'ready')).toBeNull()

		const noPassword = partner({ password: null })
		expect(resolveViewReportBlockedReason(noPassword, 'ready')).toBeNull()
		expect(
			resolveCopyPartnerPasswordBlockedReason(noPassword, 'ready')
		).not.toBeNull()
	})
})

describe('🔴 四個判定的差異矩陣（合併就會紅）', () => {
	// 把「哪個函式對哪個情境有意見」攤成一張表。任何一次「為了 DRY 併起來」
	// 的改動都會讓其中至少一格翻面。
	it('沒有 slug：只有「開啟」擋，「複製短碼」不擋', () => {
		const s = shop({ slug: '' })
		expect(resolveOpenShopBlockedReason(s)).not.toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).toBeNull()
	})

	it('已刪除：「開啟」與「複製短碼」都擋，但「查看報表」/「複製密碼」不受賣場狀態影響', () => {
		const s = shop({ status: PROFIT_SHOP_STATUS.TRASH })
		expect(resolveOpenShopBlockedReason(s)).not.toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).not.toBeNull()
		// 後兩者收不到賣場，因此無論賣場什麼狀態，只要夥伴在就可用
		expect(resolveViewReportBlockedReason(partner(), 'ready')).toBeNull()
		expect(resolveCopyPartnerPasswordBlockedReason(partner(), 'ready')).toBeNull()
	})

	it('草稿：四者皆不擋', () => {
		const s = shop({ status: PROFIT_SHOP_STATUS.DRAFT })
		expect(resolveOpenShopBlockedReason(s)).toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).toBeNull()
		expect(resolveViewReportBlockedReason(partner(), 'ready')).toBeNull()
		expect(resolveCopyPartnerPasswordBlockedReason(partner(), 'ready')).toBeNull()
	})

	it('🔴 兩個賣場層判定收不到夥伴、兩個夥伴層判定收不到賣場', () => {
		// 這條把「四個函式各自的定義域」釘死：賣場層的兩個只吃 shop（1 個參數），
		// 夥伴層的兩個只吃 (partner, state)（2 個參數）。
		// 任何一次「順手多傳一個參數進來」的合併嘗試都會讓這裡翻面。
		expect(resolveOpenShopBlockedReason).toHaveLength(1)
		expect(resolveCopyShortcodeBlockedReason).toHaveLength(1)
		expect(resolveViewReportBlockedReason).toHaveLength(2)
		expect(resolveCopyPartnerPasswordBlockedReason).toHaveLength(2)
	})
})
