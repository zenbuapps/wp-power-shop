import { CopyOutlined } from '@ant-design/icons'
import { Alert, App, Button, Input, Modal, Popconfirm, Space } from 'antd'
import { memo, useCallback, useEffect, useRef, useState } from 'react'

import { useRegeneratePartnerPassword } from '@/pages/admin/ProfitPartner/hooks'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

type TRegeneratePasswordButtonProps = {
	partnerId: number
	partnerName?: string
}

/** 5 分鐘 auto-clear（毫秒） */
const AUTO_CLEAR_MS = 5 * 60 * 1000

/**
 * 重新產生 Partner 密碼按鈕（HIGH-RISK 安全核心）
 *
 * 安全紀律（不可妥協）：
 *   1. 明文密碼**只**在 Modal 父元件 useState 暫存（不寫進其他 React state、Jotai、Context）
 *   2. **不**寫 sessionStorage / localStorage / cookie
 *   3. **不** console.log / console.warn / 任何 dev tool 可讀取的紀錄
 *   4. 用 useCustomMutation（非 useCustom）→ 結果不進 React Query cache
 *   5. 5 分鐘 auto-clear timer：超過時間自動清除明文 + 關閉 modal
 *   6. modal close → 立即 setPlainPassword(null)，timer 立即 clearTimeout
 *   7. unmount 時 cleanup timer（避免 memory leak）
 *   8. 後端已強制 Cache-Control: no-store + Pragma: no-cache（spec line 3183-3192），
 *      response 不會被 axios 快取或 proxy 攔截
 *
 * 雙重確認流程：
 *   Popconfirm → 確認 → API 呼叫 → 成功後彈 Modal 顯示明文 → 使用者複製後關閉
 */
const RegeneratePasswordButtonComponent = ({
	partnerId,
	partnerName,
}: TRegeneratePasswordButtonProps) => {
	const { notification, message } = App.useApp()
	const { mutateAsync, isLoading } = useRegeneratePartnerPassword()

	// 明文密碼僅暫存於此 state；任何「複製到別處」的需求都應 reject
	const [plainPassword, setPlainPassword] = useState<string | null>(null)
	const [modalOpen, setModalOpen] = useState(false)

	// LOW-2: Input.Password 預設 mask（密碼不直接呈現於 DOM 文字）；
	// 由 user 主動點 toggle 才顯示，降低 shoulder-surfing 風險。
	const [pwdVisible, setPwdVisible] = useState(false)

	const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

	/** 立即清除明文 + 關閉 modal + 取消 timer */
	const clearPassword = useCallback(() => {
		setPlainPassword(null)
		setModalOpen(false)
		setPwdVisible(false)
		if (timerRef.current !== null) {
			clearTimeout(timerRef.current)
			timerRef.current = null
		}
	}, [])

	// 取得明文時，啟動 5 分鐘 auto-clear timer
	// 注意：此 effect 的 cleanup 已涵蓋 unmount 場景，不需另外加一個 unmount-only effect。
	useEffect(() => {
		if (plainPassword === null) return

		timerRef.current = setTimeout(() => {
			setPlainPassword(null)
			setModalOpen(false)
			setPwdVisible(false)
			timerRef.current = null
			notification.warning({
				message: '為您的安全，新密碼已自動清除',
				description: '若尚未交給夥伴，請重新產生一次。',
				duration: 8,
			})
		}, AUTO_CLEAR_MS)

		return () => {
			if (timerRef.current !== null) {
				clearTimeout(timerRef.current)
				timerRef.current = null
			}
		}
	}, [plainPassword, notification])

	const handleRegenerate = async () => {
		try {
			const result = await mutateAsync(partnerId)

			// useCustomMutation 的 axios body 在 result.data
			// regenerate-password 是「裸 payload」: { partner_id, password }
			const payload = result?.data
			const newPassword =
				payload && typeof payload === 'object' && 'password' in payload
					? (payload as { password: unknown }).password
					: undefined

			if (typeof newPassword !== 'string' || newPassword === '') {
				notification.error({
					message: '重新產生密碼失敗',
					description: '伺服器回應格式異常，請聯絡管理員。',
				})
				return
			}

			setPlainPassword(newPassword)
			setModalOpen(true)
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '重新產生密碼失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const handleCopy = async () => {
		if (plainPassword === null) return
		try {
			// 使用 Clipboard API；HTTPS / localhost 才可用
			await navigator.clipboard.writeText(plainPassword)
			message.success('已複製新密碼')
		} catch {
			message.warning('瀏覽器拒絕剪貼簿存取，請手動選取後複製')
		}
	}

	return (
		<>
			<Popconfirm
				title="確定要重新產生密碼？"
				description={
					<>
						<div>夥伴的舊密碼將立即失效，新密碼僅會顯示一次。</div>
						<div className="tw-mt-1 tw-text-xs tw-text-orange-500">
							所有舊登入 token 也會同步失效。
						</div>
					</>
				}
				okText="確認重新產生"
				okButtonProps={{ danger: true }}
				cancelText="取消"
				onConfirm={handleRegenerate}
			>
				<Button danger loading={isLoading}>
					重新產生密碼
				</Button>
			</Popconfirm>

			<Modal
				title="新密碼（僅顯示一次）"
				open={modalOpen}
				closable={false}
				maskClosable={false}
				keyboard={false}
				footer={
					<Button type="primary" onClick={clearPassword}>
						我已記下，關閉
					</Button>
				}
				onCancel={clearPassword}
				destroyOnClose
			>
				<Alert
					type="warning"
					showIcon
					message="此密碼僅顯示一次，關閉視窗後將永久消失"
					description={
						<>
							請立即複製並交給{partnerName ? `「${partnerName}」` : '夥伴'}
							保管。
							<div className="tw-mt-1 tw-text-xs">
								為您的安全，5 分鐘後將自動清除此視窗。
							</div>
						</>
					}
					className="tw-mb-4"
				/>
				{/* LOW-2: Input.Password 預設 mask；user 點 toggle 後才顯示明文 */}
				<Space.Compact className="tw-w-full">
					<Input.Password
						value={plainPassword ?? ''}
						readOnly
						visibilityToggle={{
							visible: pwdVisible,
							onVisibleChange: setPwdVisible,
						}}
						autoComplete="off"
					/>
					<Button icon={<CopyOutlined />} onClick={handleCopy}>
						複製
					</Button>
				</Space.Compact>
			</Modal>
		</>
	)
}

export const RegeneratePasswordButton = memo(RegeneratePasswordButtonComponent)
