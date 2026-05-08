/**
 * Partner 自助修改密碼頁
 *
 * URL: #/change-password（authenticated only，由 AuthGate 守門）
 *
 * ⚠ HIGH-RISK 紀律（對齊 admin 的 RegeneratePasswordButton 8 條紀律）：
 *  1. 明文密碼**只**在 React useState（透過 antd Form），**不**進 cache /
 *     sessionStorage / Jotai / Context / log / 任何外部 storage
 *  2. 不寫 console.log / debug 印密碼欄位
 *  3. submit 完成後皆 form.resetFields() 清空欄位（成功全清；失敗依 error code
 *     分支：weak_password 只清 current 留 UX、其餘安全敏感全清）
 *  4. submit button loading + disabled 防雙擊（mutation.isLoading + success lock
 *     蓋 1.5s redirect 視窗 race）
 *  5. 成功 → forceLogoutAndRedirect('password_changed')，後端已撤銷 token
 *  6. 失敗時 mutation.error 不會被外洩到 storage（僅顯示 notification +
 *     mutation.reset() 清掉 react-query mutation state，避免 variables 內的
 *     明文密碼短暫殘留被 React DevTools 窺視）
 *  7. Form rule 阻擋 new_password === current_password / new !== confirm
 *  8. TypeScript 型別守衛：mutateAsync 回傳 typed payload，axios error 走 mapper
 *
 * 後端 contract（Phase 6-A1，commit e974ae4）：
 * - POST /partner-auth/change-password
 * - 200 → { success, password_changed_at } + Set-Cookie expire + Cache-Control no-store
 * - 422 invalid_credentials / weak_password (data.reasons)
 * - 429 rate_limited (header Retry-After)
 *
 * Phase 6-A2 主窗口裁決：
 * - 獨立頁面（非 Modal）；改密成功強制 logout 重登；client-side new === confirm 比對
 *
 * Phase 6-A2 reviewer 二輪修補：
 * - MAJOR-1: isPending → isLoading（partner-portal v4 慣例）
 * - MAJOR-2: success setTimeout 加 useRef cleanup（避免 unmount 後仍觸發 logout）
 * - MAJOR-3: 失敗清欄位策略依錯誤 code 分支
 * - MAJOR-4: success state lock 蓋 1.5s race（防使用者在 redirect 前重複點擊）
 * - LOW-2 (security): try/finally mutation.reset() 清明文 variables
 */

import {
	Alert,
	Button,
	Card,
	Form,
	Input,
	Space,
	Typography,
	notification,
} from 'antd'
import type { AxiosError } from 'axios'
import { memo, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'

import { useAuth } from '../auth/AuthContext'
import { useChangePassword } from '../hooks/useChangePassword'
import { mapPartnerException } from '../utils/partnerExceptionMapper'
import { parseRetryAfter } from '../utils/retryAfter'

type TFormValues = {
	current_password: string
	new_password: string
	confirm_new_password: string
}

/** 成功後 logout 倒數毫秒（足夠讓 notification 顯示完成） */
const SUCCESS_REDIRECT_DELAY_MS = 1500

/** 從 axios error response.data 取出 code（用於 MAJOR-3 分支） */
const extractErrorCode = (error: unknown): string | null => {
	const code = (error as { response?: { data?: { code?: unknown } } })?.response
		?.data?.code
	return typeof code === 'string' ? code : null
}

/** 修改密碼頁元件 */
const ChangePasswordComponent = () => {
	const { partner, forceLogoutAndRedirect } = useAuth()
	const [form] = Form.useForm<TFormValues>()
	const mutation = useChangePassword()

	// rate-limit cooldown UX（複用 Login 模式）
	const [cooldownUntil, setCooldownUntil] = useState<number | null>(null)
	const [secondsLeft, setSecondsLeft] = useState(0)

	// MAJOR-4: success state lock —— 蓋住 success notification → 1.5s setTimeout
	// → forceLogoutAndRedirect 期間，避免使用者重複點擊或 isLoading 已 false
	// 卻按鈕還可按造成 race
	const [success, setSuccess] = useState(false)

	// MAJOR-2: success setTimeout cleanup —— 元件 unmount 時清掉 timer，
	// 避免 unmount 後（例如使用者手動切走）仍觸發 forceLogoutAndRedirect
	const successTimerRef = useRef<number | null>(null)
	useEffect(
		() => () => {
			if (successTimerRef.current !== null) {
				window.clearTimeout(successTimerRef.current)
				successTimerRef.current = null
			}
		},
		[]
	)

	useEffect(() => {
		if (!cooldownUntil) return undefined
		const tick = (): void => {
			const left = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000))
			setSecondsLeft(left)
			if (left === 0) setCooldownUntil(null)
		}
		tick()
		const id = window.setInterval(tick, 1000)
		return () => window.clearInterval(id)
	}, [cooldownUntil])

	const isCoolingDown = cooldownUntil !== null && secondsLeft > 0

	const handleSubmit = async (values: TFormValues): Promise<void> => {
		// 鐵律：current_password / new_password 原樣送，**不** trim / normalize / sanitize
		try {
			await mutation.mutateAsync({
				current_password: values.current_password,
				new_password: values.new_password,
			})

			// 紀律 #3：成功立即清空全部欄位，不留明文 state
			form.resetFields()

			// MAJOR-4: 鎖住 button 直到 redirect
			setSuccess(true)

			notification.success({
				message: '密碼已更新',
				description: '請使用新密碼重新登入。',
			})

			// 紀律 #5 + MAJOR-2: 1.5s 後強制 logout（後端已撤銷舊 token）
			// timer ref 在 unmount 時清除（見 useEffect cleanup）
			successTimerRef.current = window.setTimeout(() => {
				successTimerRef.current = null

				// LOW-2: 在 redirect 前 reset，清掉 mutation.variables 內明文密碼
				mutation.reset()
				void forceLogoutAndRedirect('password_changed')
			}, SUCCESS_REDIRECT_DELAY_MS)
		} catch (error) {
			// MAJOR-3: 依錯誤 code 決定清空策略
			const code = extractErrorCode(error)
			if (code === 'weak_password') {
				// UX 友善：weak_password 只清 current（讓使用者修正 new/confirm）
				form.resetFields(['current_password'])
			} else {
				// 安全敏感（invalid_credentials / rate_limited / too_many_attempts /
				// 未知）：保守全清三個密碼欄位
				form.resetFields([
					'current_password',
					'new_password',
					'confirm_new_password',
				])
			}

			const retryAfter = parseRetryAfter(error as AxiosError)
			if (retryAfter) {
				setCooldownUntil(Date.now() + retryAfter * 1000)
			}

			notification.error({
				message: '修改密碼失敗',
				description: mapPartnerException(error, 'change-password'),
			})

			// LOW-2 (security): 失敗路徑立即 reset 清掉 mutation.variables 內明文密碼
			// （成功路徑改在 setTimeout 內 reset 以避免影響 success notification 顯示）
			mutation.reset()
		}
	}

	return (
		<div
			style={{
				maxWidth: 480,
				margin: '40px auto',
				padding: 24,
			}}
		>
			<Card>
				<Space direction="vertical" size={16} style={{ width: '100%' }}>
					<div>
						<Typography.Title level={3} style={{ marginTop: 0 }}>
							修改密碼
						</Typography.Title>
						{partner && (
							<Typography.Paragraph
								type="secondary"
								style={{ marginBottom: 0 }}
							>
								帳號：{partner.partner_slug}
							</Typography.Paragraph>
						)}
					</div>

					<Alert
						type="info"
						showIcon
						message="密碼複雜度需求"
						description="新密碼至少需 8 字元，且須同時包含至少 1 個英文字母與 1 個數字。"
					/>

					<Form<TFormValues>
						form={form}
						onFinish={handleSubmit}
						layout="vertical"
						autoComplete="off"
						preserve={false}
					>
						<Form.Item
							name="current_password"
							label="目前密碼"
							rules={[{ required: true, message: '請輸入目前密碼' }]}
						>
							<Input.Password autoComplete="current-password" />
						</Form.Item>

						<Form.Item
							name="new_password"
							label="新密碼"
							rules={[
								{ required: true, message: '請輸入新密碼' },
								{ min: 8, message: '密碼至少需 8 字元' },
								{
									validator: (_, value: string | undefined) => {
										if (!value) return Promise.resolve()

										// 客戶端先做基本格式檢查（後端仍會 enforce）
										if (!/[A-Za-z]/.test(value)) {
											return Promise.reject(
												new Error('密碼需含至少 1 個英文字母')
											)
										}
										if (!/[0-9]/.test(value)) {
											return Promise.reject(new Error('密碼需含至少 1 個數字'))
										}
										return Promise.resolve()
									},
								},
								({ getFieldValue }) => ({
									validator(_, value: string | undefined) {
										if (!value) return Promise.resolve()
										const current = getFieldValue('current_password') as
											| string
											| undefined
										if (current && value === current) {
											return Promise.reject(
												new Error('新密碼不可與目前密碼相同')
											)
										}
										return Promise.resolve()
									},
								}),
							]}
						>
							<Input.Password autoComplete="new-password" />
						</Form.Item>

						<Form.Item
							name="confirm_new_password"
							label="確認新密碼"
							dependencies={['new_password']}
							rules={[
								{ required: true, message: '請再次輸入新密碼' },
								({ getFieldValue }) => ({
									validator(_, value: string | undefined) {
										if (!value) return Promise.resolve()
										const next = getFieldValue('new_password') as
											| string
											| undefined
										if (next && value !== next) {
											return Promise.reject(new Error('兩次輸入的新密碼不一致'))
										}
										return Promise.resolve()
									},
								}),
							]}
						>
							<Input.Password autoComplete="new-password" />
						</Form.Item>

						<Form.Item style={{ marginBottom: 0 }}>
							<Space style={{ width: '100%', justifyContent: 'space-between' }}>
								<Link to="/">
									<Button disabled={success}>返回</Button>
								</Link>
								<Button
									type="primary"
									htmlType="submit"
									loading={mutation.isLoading}
									disabled={mutation.isLoading || isCoolingDown || success}
								>
									{isCoolingDown ? `請於 ${secondsLeft} 秒後重試` : '更新密碼'}
								</Button>
							</Space>
						</Form.Item>
					</Form>
				</Space>
			</Card>
		</div>
	)
}

export const ChangePassword = memo(ChangePasswordComponent)
