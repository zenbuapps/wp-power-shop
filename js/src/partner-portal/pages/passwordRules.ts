/**
 * 改密表單的三道前端防呆
 *
 * ⚠️ **這些不是安全控制**，後端 `PartnerPassword` VO 與 `ChangePasswordUseCase`
 * 才是。它們的作用是讓夥伴在按下送出**之前**就知道問題在哪——否則每一次
 * 格式錯誤都要吃一次 round trip，而 rate-limit 在 5 次失敗後就會鎖住。
 *
 * 因此規則必須與後端**對齊但不更嚴**：前端擋掉後端會放行的輸入，
 * 夥伴會遇到「明明符合規定卻送不出去」而完全無從得知原因。
 */

/** 後端 `PartnerPassword` VO 的最小長度（以 codepoint 計） */
export const MIN_PASSWORD_LENGTH = 8

/**
 * 密碼強度：需 ≥ 1 個英文字母且 ≥ 1 個數字
 *
 * 與後端 `PartnerPassword` VO 逐條對齊。回傳錯誤訊息，通過則回 null。
 *
 * ⚠️ 空值一律放行——那是 `required` 規則的職責，在這裡重複檢查會讓
 * 空欄位同時跳出兩則訊息。
 */
export const validatePasswordStrength = (
	value: string | undefined
): string | null => {
	if (!value) return null
	if (!/[A-Za-z]/.test(value)) return '密碼需含至少 1 個英文字母'
	if (!/[0-9]/.test(value)) return '密碼需含至少 1 個數字'
	return null
}

/**
 * 新密碼不可與目前密碼相同
 *
 * 後端不會擋這件事（送出去會成功，只是 `password_changed_at` 被 bump、
 * 該夥伴所有 session 立即失效）。結果是夥伴「什麼都沒改」卻被登出，
 * 還得用同一組密碼重新登入一次——所以這道防呆只存在於前端。
 */
export const validateNewPasswordDiffers = (
	value: string | undefined,
	currentPassword: string | undefined
): string | null => {
	if (!value) return null
	if (currentPassword && value === currentPassword) {
		return '新密碼不可與目前密碼相同'
	}
	return null
}

/** 兩次輸入的新密碼必須一致 */
export const validateConfirmMatches = (
	value: string | undefined,
	newPassword: string | undefined
): string | null => {
	if (!value) return null
	if (newPassword && value !== newPassword) {
		return '兩次輸入的新密碼不一致'
	}
	return null
}

/**
 * 送出按鈕是否應停用
 *
 * 三重守門，**三個條件缺一不可**：
 *   - `isSubmitting`：飛行中再按一次會送出第二個改密請求
 *   - `isCoolingDown`：rate-limit 倒數期間再送只會加深鎖定
 *   - `isSuccess`：成功後有一段 1.5 秒的 state lock 等待強制登出生效，
 *     這期間再按會對一個**已經失效的 token** 送出請求
 */
export const isSubmitDisabled = ({
	isSubmitting,
	isCoolingDown,
	isSuccess,
}: {
	isSubmitting: boolean
	isCoolingDown: boolean
	isSuccess: boolean
}): boolean => isSubmitting || isCoolingDown || isSuccess
