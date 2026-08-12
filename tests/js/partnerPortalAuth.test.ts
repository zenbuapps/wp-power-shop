/**
 * Partner Portal 的認證狀態推導與 session 儲存
 *
 * 對應規格：specs/features/profit-shop/login-partner.feature
 *   Rule: 隔離（跨夥伴）- 網址的夥伴與本機登入身分不一致時必須遮蔽
 *   Rule: 後置（登出）- 登出必須是冪等的且永遠清除本機狀態
 *
 * ── 這兩條都是安全不變量，不是 UX 細節 ──────────────────────
 *
 * Partner Portal 是**公開網址**（`/profit-report/{slug}/`），任何人都能直接
 * 打開別的夥伴的那一頁。真正的權限由後端 token 把關，前端這層負責的是
 * 「在強制登出生效之前，畫面不能閃出另一位夥伴的資料」。
 */
import { describe, it, expect, beforeEach } from 'vitest'
import { resolveAuth } from '@/partner-portal/auth/resolveAuth'
import { session } from '@/partner-portal/auth/session'

const me = (slug: string) =>
	({
		partner_id: 1,
		partner_name: '夥伴 A',
		partner_slug: slug,
		expires_at: 1900000000,
	}) as never

const q = (over: Partial<Parameters<typeof resolveAuth>[0]> = {}) => ({
	isLoading: false,
	isSuccess: false,
	data: undefined,
	...over,
})

describe('resolveAuth — 跨夥伴遮蔽', () => {
	it('slug 相符時正常認證，partner 完整帶出', () => {
		const r = resolveAuth(q({ isSuccess: true, data: me('alpha') }), 'alpha')
		expect(r.status).toBe('authenticated')
		expect(r.partner).not.toBeNull()
		expect(r.isMismatch).toBe(false)
	})

	it('🔴 slug 不符時 status 是 loading，**不是** authenticated', () => {
		// 第一道防線。設成 'authenticated' 的話，在 useEffect 觸發強制登出前的
		// 那一幀，Dashboard 會閃出另一位夥伴的姓名。
		const r = resolveAuth(q({ isSuccess: true, data: me('alpha') }), 'beta')
		expect(r.status).toBe('loading')
	})

	it('🔴 slug 不符時 partner 同時被遮蔽成 null（雙保險）', () => {
		// 第二道防線。即使某種 race 下 status 短暫為 'authenticated'，
		// 下游拿到的 partner 仍是 null → 不會渲染出別人的資料。
		const r = resolveAuth(q({ isSuccess: true, data: me('alpha') }), 'beta')
		expect(r.partner).toBeNull()
	})

	it('🔴 slug 不符時 status **不可**是 guest', () => {
		// 設成 guest 會讓畫面直接 redirect 到登入頁，使用者看到的是
		// 「莫名其妙被登出」而不是「這個網址不屬於你」——而且會在
		// 強制登出真正完成前就跳走，logout 請求可能來不及送出。
		const r = resolveAuth(q({ isSuccess: true, data: me('alpha') }), 'beta')
		expect(r.status).not.toBe('guest')
	})

	it('slug 不符時回報 isMismatch，呼叫端據此觸發強制登出', () => {
		expect(resolveAuth(q({ isSuccess: true, data: me('alpha') }), 'beta').isMismatch).toBe(true)
	})

	it('查詢中一律 loading（即使已有舊資料）', () => {
		expect(resolveAuth(q({ isLoading: true, data: me('alpha') }), 'alpha').status).toBe('loading')
	})

	it('查詢失敗 / 未登入 → guest，partner 為 null', () => {
		const r = resolveAuth(q(), 'alpha')
		expect(r.status).toBe('guest')
		expect(r.partner).toBeNull()
		expect(r.isMismatch).toBe(false)
	})

	it('⚠️ slug 取不到時視為相符（向後相容的刻意降級）', () => {
		// env 解密失敗或測試環境下取不到 slug。此時若一律判為 mismatch，
		// **所有夥伴都會登不進去**。真正的權限由後端 token 把關，
		// 前端這層只是防止畫面閃錯資料。
		for (const noSlug of [null, undefined, '']) {
			const r = resolveAuth(q({ isSuccess: true, data: me('alpha') }), noSlug)
			expect(r.status, `slug=${String(noSlug)}`).toBe('authenticated')
			expect(r.partner).not.toBeNull()
		}
	})

	it('data 為 null 時不算 mismatch（那是「還沒登入」不是「登錯人」）', () => {
		expect(resolveAuth(q({ isSuccess: true, data: null }), 'alpha').isMismatch).toBe(false)
	})
})

describe('session — 本機 metadata', () => {
	beforeEach(() => sessionStorage.clear())

	it('存得進去、讀得出來', () => {
		const data = {
			partner_id: 7,
			partner_name: '夥伴 A',
			partner_slug: 'alpha',
			expires_at: 1900000000,
		}
		session.save(data)
		expect(session.read()).toEqual(data)
	})

	it('🔴 永不儲存 token 或任何敏感欄位', () => {
		// partner token 由 HttpOnly cookie 管理，前端 JS 讀不到也不該讀。
		// 一旦有人「順手」把 token 存進來，XSS 就能直接把它偷走——
		// 而那正是整個 cookie-only 設計要防的東西。
		session.save({
			partner_id: 7,
			partner_name: '夥伴 A',
			partner_slug: 'alpha',
			expires_at: 1900000000,
			// @ts-expect-error 刻意傳入不該存在的欄位，驗證它不會被帶進 read 的輸出
			token: 'SECRET-TOKEN-VALUE',
		})

		const readBack = session.read()
		expect(readBack).not.toBeNull()
		expect(Object.keys(readBack!).sort()).toEqual([
			'expires_at',
			'partner_id',
			'partner_name',
			'partner_slug',
		])
		expect(JSON.stringify(readBack)).not.toContain('SECRET-TOKEN-VALUE')
	})

	it('🔴 clear 是冪等的：連續呼叫、或本來就沒東西都不得拋錯', () => {
		// Rule: 後置（登出）- 登出必須是冪等的且永遠清除本機狀態。
		// 登出流程是「呼叫 API → 清本機」，而 API 失敗時**仍然要清**。
		// 這裡若拋錯，清除會被中斷，使用者以為登出了、下次開頁面卻還在。
		expect(() => session.clear()).not.toThrow()
		session.save({
			partner_id: 1,
			partner_name: 'x',
			partner_slug: 'y',
			expires_at: 1,
		})
		expect(() => {
			session.clear()
			session.clear()
		}).not.toThrow()
		expect(session.read()).toBeNull()
	})

	it('沒有資料時 read 回 null', () => {
		expect(session.read()).toBeNull()
	})

	it('壞掉的 JSON 回 null，不拋錯', () => {
		sessionStorage.setItem('profit_partner_session', '{ not json')
		expect(session.read()).toBeNull()
	})

	it('shape 不符（缺欄位 / 型別錯）一律回 null', () => {
		// 半殘的 session 比沒有 session 更糟：畫面會以為已登入、
		// 然後在讀 partner_name 時炸掉
		const bad = [
			'{}',
			'null',
			'[]',
			'"字串"',
			JSON.stringify({ partner_id: '7', partner_name: 'a', partner_slug: 'b', expires_at: 1 }),
			JSON.stringify({ partner_id: 7, partner_slug: 'b', expires_at: 1 }),
			JSON.stringify({ partner_id: 7, partner_name: 'a', partner_slug: 'b' }),
		]
		for (const raw of bad) {
			sessionStorage.setItem('profit_partner_session', raw)
			expect(session.read(), raw).toBeNull()
		}
	})

	it('用 sessionStorage 而非 localStorage（分頁關閉即清除）', () => {
		session.save({
			partner_id: 1,
			partner_name: 'x',
			partner_slug: 'y',
			expires_at: 1,
		})
		expect(sessionStorage.getItem('profit_partner_session')).not.toBeNull()
		expect(localStorage.getItem('profit_partner_session')).toBeNull()
	})
})
