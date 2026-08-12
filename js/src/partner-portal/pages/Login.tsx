/**
 * Partner 登入頁
 *
 * - 表單欄位：slug + password
 * - rate-limit 倒數：後端 429 帶 Retry-After header → 顯示倒數秒數，期間禁止再次提交
 * - autoComplete：username + current-password（瀏覽器密碼管理員相容）
 * - slug 預填：從 URL 解密的 SLUG（partner 通常不需自行輸入）
 *
 * Mobile-first 但桌機為主：max-width 400px 置中，padding 自適應。
 */

import { Button, Card, Form, Input, Typography, notification } from 'antd'
import type { AxiosError } from 'axios'
import { memo, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'

import { useAuth } from '../auth/AuthContext'
import { usePartnerEnv } from '../hooks/usePartnerEnv'
import { mapPartnerException } from '../utils/partnerExceptionMapper'
import { parseRetryAfter } from '../utils/retryAfter'

type TLoginFormValues = {
	slug: string
	password: string
}

/**
 * 已知 reason query string 白名單（LOW-1 security）
 *
 * 為什麼用白名單：避免攻擊者塞任意字串到 ?reason= 觸發未預期的訊息顯示
 * （目前訊息走 antd notification，已自動 escape，但白名單作為深度防禦）
 */
const KNOWN_REASONS: ReadonlySet<string> = new Set(['password_changed'])

/** Partner 登入頁元件 */
const LoginComponent = () => {
	const { login } = useAuth()
	const { SLUG } = usePartnerEnv()
	const [form] = Form.useForm<TLoginFormValues>()
	const navigate = useNavigate()
	const location = useLocation()

	const [submitting, setSubmitting] = useState(false)
	const [cooldownUntil, setCooldownUntil] = useState<number | null>(null)
	const [secondsLeft, setSecondsLeft] = useState(0)

	// 6-A2：讀 query string ?reason=password_changed 顯示提示
	// reasonShownRef 避免 React 18 StrictMode 重渲染或 location 變動造成重複觸發
	// LOW-1 (security): 用白名單比對，避免攻擊者塞任意字串造成 XSS / 訊息污染
	const reasonShownRef = useRef<string | null>(null)
	useEffect(() => {
		const reason = new URLSearchParams(location.search).get('reason')
		if (
			reason &&
			KNOWN_REASONS.has(reason) &&
			reasonShownRef.current !== reason
		) {
			reasonShownRef.current = reason
			if (reason === 'password_changed') {
				notification.success({
					message: '密碼已更新',
					description: '請使用新密碼登入。',
				})
			}
		}
	}, [location.search])

	// 倒數計時器
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

	const handleSubmit = async (values: TLoginFormValues): Promise<void> => {
		setSubmitting(true)
		try {
			await login(values.slug.trim(), values.password)
			navigate('/', { replace: true })
		} catch (error) {
			const retryAfter = parseRetryAfter(error as AxiosError)
			if (retryAfter) {
				setCooldownUntil(Date.now() + retryAfter * 1000)
			}
			notification.error({
				message: '登入失敗',
				description: mapPartnerException(error),
			})
		} finally {
			setSubmitting(false)
		}
	}

	const isCoolingDown = cooldownUntil !== null && secondsLeft > 0

	return (
		<div
			style={{
				maxWidth: 400,
				margin: '40px auto',
				padding: 24,
			}}
		>
			<Card>
				<Typography.Title level={3} style={{ marginTop: 0 }}>
					分潤夥伴登入
				</Typography.Title>
				<Typography.Paragraph type="secondary">
					請輸入您的分潤夥伴帳號與密碼。
				</Typography.Paragraph>
				<Form<TLoginFormValues>
					form={form}
					onFinish={handleSubmit}
					initialValues={{ slug: SLUG }}
					layout="vertical"
					autoComplete="on"
				>
					<Form.Item
						name="slug"
						label="夥伴帳號"
						rules={[{ required: true, message: '請輸入帳號' }]}
					>
						<Input autoComplete="username" disabled={Boolean(SLUG)} />
					</Form.Item>
					<Form.Item
						name="password"
						label="密碼"
						rules={[{ required: true, message: '請輸入密碼' }]}
					>
						<Input.Password autoComplete="current-password" />
					</Form.Item>
					<Form.Item style={{ marginBottom: 0 }}>
						<Button
							type="primary"
							htmlType="submit"
							block
							loading={submitting}
							disabled={isCoolingDown}
						>
							{isCoolingDown ? `請於 ${secondsLeft} 秒後重試` : '登入'}
						</Button>
					</Form.Item>
				</Form>
			</Card>
		</div>
	)
}

export const Login = memo(LoginComponent)
