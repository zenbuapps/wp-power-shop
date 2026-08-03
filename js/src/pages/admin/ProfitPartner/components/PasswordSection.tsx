import {
	App,
	Button,
	Form,
	Input,
	Space,
	type FormInstance,
	type FormRule,
} from 'antd'
import { memo } from 'react'

import { RegeneratePasswordButton } from '@/pages/admin/ProfitPartner/Edit/RegeneratePasswordButton'
import { useSetPartnerPassword } from '@/pages/admin/ProfitPartner/hooks'
import type { TPartnerFormValues } from '@/pages/admin/ProfitPartner/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Item } = Form

type TPasswordSectionProps = {
	form: FormInstance<TPartnerFormValues>
	partnerId: number
	partnerName?: string
	disabled?: boolean
}

/**
 * 密碼強度規則（與後端 `PartnerPassword` VO 對齊）
 *
 * ⚠️ **不可加 `required`**：這兩欄住在主表單的 `Form` 裡，主表單存檔會跑
 * `form.validateFields()` 驗全部欄位——加了 required 會變成「不改密碼就存不了名稱」。
 * 「必填」的語意由「更新密碼」按鈕的 disabled + 送出前的單欄位驗證負責。
 *
 * 前端規則只是**提前回饋**，不是守門——真正的驗證在後端 VO
 * （不合格回 422 weak_password）。兩邊若分家以後端為準：前端放寬不會造成漏洞
 * （後端仍會擋），前端收緊則會讓合法密碼被誤擋，所以**不要**在這裡加後端沒有的規則。
 *
 * 長度用 `[...value].length` 以 **codepoint** 計，與後端 `mb_strlen($v, 'UTF-8')`
 * 一致——`value.length` 是 UTF-16 code unit，emoji 會被算成 2，
 * 造成「前端說夠長、後端說太短」的矛盾。
 */
const passwordRules: FormRule[] = [
	{
		validator: (_rule, value: string) => {
			if (!value) return Promise.resolve()

			const reasons: string[] = []
			if ([...value].length < 8) reasons.push('至少 8 個字元')
			if (!/[A-Za-z]/.test(value)) reasons.push('至少 1 個英文字母')
			if (!/[0-9]/.test(value)) reasons.push('至少 1 個數字')

			return reasons.length > 0
				? Promise.reject(new Error(`密碼需${reasons.join('、')}`))
				: Promise.resolve()
		},
	},
]

/**
 * 夥伴報表登入密碼設定區塊（HIGH-RISK 安全核心）
 *
 * 位置：Edit 頁「聯絡 Email」欄位下方，視覺上就是表單的一部分。
 *
 * ⚠️ **動作上獨立於主表單**：兩個密碼欄雖然掛在主表單的 `Form` 上（為了取得一般欄位
 * 的 label / 對齊 / 驗證體驗），但送出走自己的「更新密碼」按鈕 →
 * `POST /profit-partners/{id}/set-password`，**不隨主表單的「儲存」一起送**。
 *
 * 這個分離不是實作偷懶，是 Phase 4-A2 就訂下的安全紀律：站長改個名稱或 Email 時
 * 不該有任何機會動到密碼。`Edit/index.tsx` 的 payload 因此是**明確列舉欄位**
 * （不是 `...values` 展開）——改那段時務必維持列舉寫法，否則密碼會跟著漏進主表單請求。
 *
 * 安全紀律：
 *   1. 明文密碼**只**在 antd Form state；不進 useState / Jotai / Context / cache
 *   2. **不**寫 sessionStorage / localStorage / cookie
 *   3. **不** console.log 任何含密碼的物件
 *   4. 用 useCustomMutation（非 useCustom）→ 結果不進 React Query cache
 *   5. 成功後 `resetFields` 兩欄 **並** `mutation.reset()`
 *      （後者清 react-query 保留的 `variables`，那裡面有明文，React DevTools 讀得到）
 *   6. 送出鈕三重守門 `disabled = isLoading || !newPassword || !confirm`
 *   7. 兩欄確認，rule 阻擋 `confirm !== new_password`
 *
 * ⚠️ 送出成功後該夥伴**所有舊 token 立即失效**（後端 bump `password_changed_at`，
 * `PartnerTokenStore::verify` 比對 `issued_at <= password_changed_at`）。
 * 成功訊息必須講明，否則站長不會知道夥伴會被踢出登入。
 */
const PasswordSectionComponent = ({
	form,
	partnerId,
	partnerName,
	disabled,
}: TPasswordSectionProps) => {
	const { notification } = App.useApp()
	const { mutateAsync, isLoading, reset } = useSetPartnerPassword()

	// 只讀「有沒有填」用於 disabled 判斷；**不**把值存進元件 state
	const newPassword = Form.useWatch('new_password', form)
	const confirm = Form.useWatch('new_password_confirm', form)

	const handleUpdate = async () => {
		try {
			// 只驗這兩欄——不可用無參數的 validateFields()，那會連帶驗主表單其他欄位，
			// 變成「名稱沒填就不能改密碼」這種不相干的阻擋。
			await form.validateFields(['new_password', 'new_password_confirm'])

			const password = form.getFieldValue('new_password') as string

			await mutateAsync(partnerId, password)

			notification.success({
				message: '已更新密碼',
				description: `${partnerName ?? `#${partnerId}`} 的報表登入密碼已變更。該夥伴目前的登入階段已全部失效，需用新密碼重新登入。`,
				duration: 8,
			})

			form.resetFields(['new_password', 'new_password_confirm'])

			// react-query 會把 mutation 的 variables（含明文密碼）留到下次呼叫為止
			reset()
		} catch (err) {
			// antd 表單驗證失敗：錯誤已顯示在欄位上，不另外彈通知
			if (err && typeof err === 'object' && 'errorFields' in err) return

			const mapped = mapProfitShopException(err)

			// ⚠️ **不要**寫 `if (mapped.code === 'weak_password')` 之類的分支——
			// Refine 的 HttpError 只保留 `{ message, statusCode }`，原始 response body
			// （含後端的 `code` 與 `data.reasons`）已不存在，`mapped.code` 恆為 undefined
			// （詳見 react.rule.md §3）。`mapped.toastMessage` 解析不出 body 時會 fallback
			// 成 `error.message`，也就是後端 WeakPassword 組出的中文原因，資訊量足夠。
			form.setFields([
				{ name: 'new_password', errors: [mapped.toastMessage] },
			])
			form.resetFields(['new_password_confirm'])

			notification.error({
				message: '更新密碼失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<>
			<Item
				name="new_password"
				label="設定新密碼"
				rules={passwordRules}
				extra="留空表示不變更。至少 8 個字元，且需包含英文字母與數字。變更後該夥伴會被登出，需用新密碼重新登入。"
			>
				<Input.Password
					placeholder="留空表示不變更"
					autoComplete="new-password"
					disabled={disabled || isLoading}
				/>
			</Item>

			<Item
				name="new_password_confirm"
				label="確認新密碼"
				dependencies={['new_password']}
				rules={[
					({ getFieldValue }) => ({
						validator: (_rule, value: string) => {
							const pw = getFieldValue('new_password') as string | undefined
							if (!pw && !value) return Promise.resolve()
							if (pw === value) return Promise.resolve()
							return Promise.reject(new Error('兩次輸入的密碼不一致'))
						},
					}),
				]}
			>
				<Input.Password
					placeholder="再次輸入新密碼"
					autoComplete="new-password"
					disabled={disabled || isLoading}
				/>
			</Item>

			<Item>
				<Space>
					<Button
						type="primary"
						loading={isLoading}
						disabled={disabled || isLoading || !newPassword || !confirm}
						onClick={handleUpdate}
					>
						更新密碼
					</Button>

					{/*
					 * 次要操作：夥伴忘記密碼 / 疑似外洩時，一鍵重設成系統產生的強密碼
					 * （明文一次性顯示）。與左邊「站長指定一組自己知道的密碼」互補，
					 * 兩者刻意並存——合併掉任一個都會讓其中一種情境變得難操作。
					 */}
					<RegeneratePasswordButton
						partnerId={partnerId}
						partnerName={partnerName}
					/>
				</Space>
			</Item>
		</>
	)
}

export const PasswordSection = memo(PasswordSectionComponent)
