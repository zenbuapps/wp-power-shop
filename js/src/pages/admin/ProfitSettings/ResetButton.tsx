import { useApiUrl, useCustomMutation, useInvalidate } from '@refinedev/core'
import { Alert, App, Button, Form, Input, Modal } from 'antd'
import { memo, useState } from 'react'

import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Item } = Form

/** 二次確認需輸入的字串（必須完整大寫匹配） */
const CONFIRM_PHRASE = 'RESET'

/**
 * 還原 Profit Settings 預設值（HIGH-RISK，不可逆）
 *
 * 安全紀律：
 *   - 文字輸入「RESET」（**完整大寫**）二次確認
 *   - 還原後立即 invalidate 'profit-settings'，讓父元件 useCustom 重新抓並 setFieldsValue
 *   - 失敗不關閉 modal（保留輸入內容）
 *   - mutation 寫在這個元件內部、不分享 hooks（單一用途，避免被誤用）
 */
const ResetButtonComponent = () => {
	const { notification } = App.useApp()
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const { mutateAsync, isLoading } = useCustomMutation()

	const [open, setOpen] = useState(false)
	const [confirmText, setConfirmText] = useState('')

	const closeModal = () => {
		setOpen(false)
		setConfirmText('')
	}

	const canSubmit = confirmText === CONFIRM_PHRASE && !isLoading

	const handleReset = async () => {
		try {
			await mutateAsync({
				url: `${apiUrl}/profit-settings/reset`,
				method: 'post',
				values: {},
				dataProviderName: 'power-shop',
			})

			notification.success({
				message: '已還原預設值',
				description: 'Profit Shop 全域設定已回復為系統預設',
			})

			invalidate({
				resource: 'profit-settings',
				invalidates: ['all'],
				dataProviderName: 'power-shop',
			})

			closeModal()
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '還原失敗',
				description: mapped.toastMessage,
			})

			// 不關閉 modal
		}
	}

	return (
		<>
			<Button danger onClick={() => setOpen(true)}>
				還原預設值
			</Button>
			<Modal
				title="還原預設值"
				open={open}
				onCancel={closeModal}
				maskClosable={false}
				keyboard={false}
				destroyOnClose
				footer={[
					<Button key="cancel" onClick={closeModal} disabled={isLoading}>
						取消
					</Button>,
					<Button
						key="submit"
						type="primary"
						danger
						disabled={!canSubmit}
						loading={isLoading}
						onClick={handleReset}
					>
						確認還原
					</Button>,
				]}
			>
				<Alert
					type="error"
					showIcon
					message="此操作不可逆"
					description="所有設定將還原為系統預設值，目前的自訂值會被覆寫。"
					className="tw-mb-4"
				/>
				<Form layout="vertical" disabled={isLoading}>
					<Item
						label={`輸入 ${CONFIRM_PHRASE} 確認`}
						required
						help={`必須完整輸入大寫 ${CONFIRM_PHRASE}`}
					>
						<Input
							value={confirmText}
							onChange={(e) => setConfirmText(e.target.value)}
							placeholder={CONFIRM_PHRASE}
							autoComplete="off"
						/>
					</Item>
				</Form>
			</Modal>
		</>
	)
}

export const ResetButton = memo(ResetButtonComponent)
