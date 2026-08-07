/**
 * 賣場列表操作欄的主要動作判定
 *
 * 對應規格：
 *   specs/features/profit-shop/change-shop-status.feature
 *     Rule: 前端（列表）- 操作欄依賣場狀態與模式呈現不同的主要動作
 *   specs/features/profit-shop/render-shop-via-shortcode.feature
 *     Rule: 前端（後台）- 短碼模式的賣場在列表提供複製短碼
 *
 * ── 三個判定函式看起來 80% 相同，差異點才是全部的價值 ────────
 *
 * `admin-list-ui.rule.md` 明文寫著「別為了 DRY 併成一個」，因為併掉會各自
 * 產生一個具體的損失：
 *   - 併掉 slug 條件 → 沒設永久連結的 shortcode 賣場白白失去複製入口
 *   - 併掉 status 條件 → 草稿 / 已刪除賣場白白失去夥伴報表入口
 *
 * 本檔因此**刻意逐條測差異**，而不是只測「三個都會擋 trash」。
 * 若日後有人把它們合併，這裡會立刻紅——那正是這些測試存在的理由。
 */
import { describe, it, expect } from 'vitest'
import {
	resolveOpenShopBlockedReason,
	resolveCopyShortcodeBlockedReason,
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

describe('「查看報表」按鈕', () => {
	it('夥伴存在且有報表網址時可查看', () => {
		expect(resolveViewReportBlockedReason(partner(), false)).toBeNull()
	})

	it('🔴 賣場的 status 完全不影響報表入口', () => {
		// 報表屬於**分潤夥伴**（/profit-report/{partner-slug}/），不屬於賣場。
		// 夥伴的報表涵蓋他名下所有賣場，某個賣場是草稿或已刪除，
		// 都不影響那張報表存不存在、也不影響其他賣場的數字。
		//
		// 這條的驗法是「函式簽章根本收不到賣場」——它只吃 partner 與 isLoading，
		// 因此賣場狀態在結構上就影響不了它。若有人為了 DRY 把賣場塞進來，
		// 這條測試的存在會讓那個改動變得刺眼。
		expect(resolveViewReportBlockedReason).toHaveLength(2)
		expect(resolveViewReportBlockedReason(partner(), false)).toBeNull()
	})

	it('🔴 載入中的訊息必須與「真的找不到」區分', () => {
		// 不區分的話，每次進頁面都會先閃一次「夥伴已被刪除」的假警告，
		// 站長會以為資料出問題而跑去檢查
		const loading = resolveViewReportBlockedReason(undefined, true)
		const notFound = resolveViewReportBlockedReason(undefined, false)

		expect(loading).toContain('載入中')
		expect(notFound).toContain('找不到')
		expect(loading).not.toBe(notFound)
	})

	it('載入中即使已有夥伴資料也先停用', () => {
		expect(resolveViewReportBlockedReason(partner(), true)).toContain('載入中')
	})

	it('夥伴沒有報表網址時停用（契約是 nullable，照實處理不用 ! 斷言）', () => {
		expect(
			resolveViewReportBlockedReason(partner({ report_url: null }), false)
		).toContain('報表網址')
	})
})

describe('🔴 三個判定的差異矩陣（合併就會紅）', () => {
	// 把「哪個函式對哪個情境有意見」攤成一張表。任何一次「為了 DRY 併起來」
	// 的改動都會讓其中至少一格翻面。
	it('沒有 slug：只有「開啟」擋，「複製短碼」不擋', () => {
		const s = shop({ slug: '' })
		expect(resolveOpenShopBlockedReason(s)).not.toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).toBeNull()
	})

	it('已刪除：「開啟」與「複製短碼」都擋，但「查看報表」不受賣場狀態影響', () => {
		const s = shop({ status: PROFIT_SHOP_STATUS.TRASH })
		expect(resolveOpenShopBlockedReason(s)).not.toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).not.toBeNull()
		// 報表函式收不到賣場，因此無論賣場什麼狀態，只要夥伴在就可查看
		expect(resolveViewReportBlockedReason(partner(), false)).toBeNull()
	})

	it('草稿：三者皆不擋', () => {
		const s = shop({ status: PROFIT_SHOP_STATUS.DRAFT })
		expect(resolveOpenShopBlockedReason(s)).toBeNull()
		expect(resolveCopyShortcodeBlockedReason(s)).toBeNull()
		expect(resolveViewReportBlockedReason(partner(), false)).toBeNull()
	})
})
