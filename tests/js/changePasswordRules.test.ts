/**
 * 改密表單的三道前端防呆與送出守門
 *
 * 對應規格：specs/features/profit-shop/change-partner-password.feature
 *   Rule: 前端（防呆）- 表單必須阻擋無意義的輸入並防止重複送出
 *
 * ── 這些不是安全控制，但它們的缺席會讓人被鎖在門外 ──────────
 *
 * 後端 `PartnerPassword` VO 與 `ChangePasswordUseCase` 才是安全邊界。
 * 前端這三道的作用是讓夥伴在按下送出**之前**就知道問題在哪——否則每一次
 * 格式錯誤都要吃一次 round trip，而 rate-limit 在 5 次失敗後就會鎖住帳號。
 *
 * 🔴 因此規則必須與後端**對齊但不更嚴**：前端若擋掉後端會放行的輸入，
 * 夥伴會遇到「明明符合規定卻送不出去」，而畫面上完全看不出為什麼。
 */
import { describe, it, expect } from 'vitest'
import * as fs from 'node:fs'
import * as path from 'node:path'
import {
	MIN_PASSWORD_LENGTH,
	isSubmitDisabled,
	validateConfirmMatches,
	validateNewPasswordDiffers,
	validatePasswordStrength,
} from '@/partner-portal/pages/passwordRules'

describe('validatePasswordStrength — 與後端 VO 對齊', () => {
	it('英文加數字通過', () => {
		expect(validatePasswordStrength('abc12345')).toBeNull()
	})

	it('純數字被擋（缺英文字母）', () => {
		expect(validatePasswordStrength('12345678')).toContain('英文字母')
	})

	it('純英文被擋（缺數字）', () => {
		expect(validatePasswordStrength('abcdefgh')).toContain('數字')
	})

	it('🔴 空值一律放行——那是 required 規則的職責', () => {
		// 在這裡重複檢查會讓空欄位同時跳出兩則訊息（「請輸入新密碼」+
		// 「密碼需含至少 1 個英文字母」），使用者會以為自己犯了兩個錯
		expect(validatePasswordStrength('')).toBeNull()
		expect(validatePasswordStrength(undefined)).toBeNull()
	})

	it('🔴 不得比後端更嚴：後端允許的符號 / 中文一律放行', () => {
		// 後端 PartnerPassword VO 只要求「≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字」，
		// 沒有字元集限制。前端若擅自加「只能英數」之類的規則，
		// 夥伴用符號密碼會被擋在自己這一頁，而後端明明會接受。
		expect(validatePasswordStrength('abc123!@#$')).toBeNull()
		expect(validatePasswordStrength('密碼abc123')).toBeNull()
		expect(validatePasswordStrength('a1 b2 c3 d4')).toBeNull()
	})

	it('長度不在這個函式的職責內（由 min 規則負責）', () => {
		// 分開處理才能各自給出精準訊息；混在一起的話「太短」會被
		// 「需含數字」的訊息蓋掉
		expect(validatePasswordStrength('a1')).toBeNull()
	})

	it('最小長度常數與後端 VO 一致（8 codepoint）', () => {
		expect(MIN_PASSWORD_LENGTH).toBe(8)

		const vo = fs.readFileSync(
			path.resolve(
				process.cwd(),
				'inc/classes/Domains/ProfitShop/Domain/ValueObject/PartnerPassword.php'
			),
			'utf-8'
		)
		expect(vo).toContain(String(MIN_PASSWORD_LENGTH))
	})
})

describe('validateNewPasswordDiffers — 新舊密碼不可相同', () => {
	it('🔴 相同時擋下（後端不會擋，但會把人登出）', () => {
		// 送出去會成功，只是 password_changed_at 被 bump、該夥伴所有 session
		// 立即失效。結果是夥伴「什麼都沒改」卻被登出，還得用同一組密碼
		// 重新登入一次——所以這道防呆只存在於前端。
		expect(validateNewPasswordDiffers('abc12345', 'abc12345')).toContain('不可與目前密碼相同')
	})

	it('不同時通過', () => {
		expect(validateNewPasswordDiffers('new12345', 'abc12345')).toBeNull()
	})

	it('目前密碼還沒填時不判定（避免尚未輸入就跳錯）', () => {
		expect(validateNewPasswordDiffers('abc12345', undefined)).toBeNull()
		expect(validateNewPasswordDiffers('abc12345', '')).toBeNull()
	})

	it('新密碼為空時不判定', () => {
		expect(validateNewPasswordDiffers('', 'abc12345')).toBeNull()
	})

	it('比對區分大小寫與空白（不做 normalize）', () => {
		expect(validateNewPasswordDiffers('ABC12345', 'abc12345')).toBeNull()
		expect(validateNewPasswordDiffers('abc12345 ', 'abc12345')).toBeNull()
	})
})

describe('validateConfirmMatches — 兩次輸入必須一致', () => {
	it('一致時通過', () => {
		expect(validateConfirmMatches('abc12345', 'abc12345')).toBeNull()
	})

	it('不一致時擋下', () => {
		expect(validateConfirmMatches('abc12346', 'abc12345')).toContain('不一致')
	})

	it('任一為空時不判定', () => {
		expect(validateConfirmMatches('', 'abc12345')).toBeNull()
		expect(validateConfirmMatches('abc12345', undefined)).toBeNull()
	})

	it('差一個空白也算不一致（密碼不做 trim）', () => {
		expect(validateConfirmMatches('abc12345 ', 'abc12345')).toContain('不一致')
	})
})

describe('isSubmitDisabled — 三重守門', () => {
	it('三者皆否時可送出', () => {
		expect(
			isSubmitDisabled({ isSubmitting: false, isCoolingDown: false, isSuccess: false })
		).toBe(false)
	})

	it('🔴 飛行中停用（再按一次會送出第二個改密請求）', () => {
		expect(
			isSubmitDisabled({ isSubmitting: true, isCoolingDown: false, isSuccess: false })
		).toBe(true)
	})

	it('🔴 rate-limit 倒數期間停用（再送只會加深鎖定）', () => {
		expect(
			isSubmitDisabled({ isSubmitting: false, isCoolingDown: true, isSuccess: false })
		).toBe(true)
	})

	it('🔴 成功後停用（那 1.5 秒的 token 已經失效）', () => {
		// 改密成功時後端已 bump password_changed_at，舊 token 立即失效。
		// 這期間再按會對一個**已經失效的 token** 送出請求，得到的是 401——
		// 使用者會以為改密失敗，實際上早就成功了。
		expect(
			isSubmitDisabled({ isSubmitting: false, isCoolingDown: false, isSuccess: true })
		).toBe(true)
	})

	it('三個條件缺一不可（逐一驗證每個都能獨立觸發停用）', () => {
		const keys = ['isSubmitting', 'isCoolingDown', 'isSuccess'] as const
		keys.forEach((key) => {
			const state = { isSubmitting: false, isCoolingDown: false, isSuccess: false }
			expect(isSubmitDisabled({ ...state, [key]: true }), key).toBe(true)
		})
	})
})
