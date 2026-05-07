import { Alert, App, Button, Form, Input, Modal } from 'antd'
import { memo, useEffect, useState } from 'react'
import { useNavigate } from 'react-router'

import { useLegacyShopImport } from '@/pages/admin/ProfitMigration/hooks'
import type { TLegacyShop } from '@/pages/admin/ProfitMigration/types'
import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Item } = Form

/** 二次確認需輸入的字串（必須完整大寫匹配，避免「import」誤觸） */
const CONFIRM_PHRASE = 'IMPORT'

type TImportModalProps = {
	legacyShop: TLegacyShop | null
	open: boolean
	onClose: () => void
}

/**
 * 不可逆遷入確認 Modal（HIGH-RISK）
 *
 * 安全紀律：
 *   1. PartnerSelector 必填：legacy 沒有 partner，遷入後必須掛在某個 partner 下
 *   2. 文字輸入「IMPORT」（**完整大寫**）二次確認，避免誤觸
 *   3. 失敗時**不關閉** modal（保留輸入內容，讓使用者修正後重試）
 *   4. 成功後 navigate 到新賣場 edit 頁，方便立即檢查 / 補設定
 *   5. modal 關閉時清空 partnerId / confirmText（避免下次開啟時殘留）
 */
const ImportModalComponent = ({
	legacyShop,
	open,
	onClose,
}: TImportModalProps) => {
	const { notification } = App.useApp()
	const navigate = useNavigate()
	const { mutateAsync, isLoading } = useLegacyShopImport()

	const [partnerId, setPartnerId] = useState<number | null>(null)
	const [confirmText, setConfirmText] = useState('')

	// modal 關閉時清空輸入（避免下次開啟殘留）
	useEffect(() => {
		if (!open) {
			setPartnerId(null)
			setConfirmText('')
		}
	}, [open])

	const canSubmit =
		!!legacyShop &&
		legacyShop.importable &&
		partnerId !== null &&
		confirmText === CONFIRM_PHRASE &&
		!isLoading

	const handleSubmit = async () => {
		if (!legacyShop || partnerId === null) return
		try {
			const result = await mutateAsync({
				legacy_id: legacyShop.legacy_id,
				partner_term_id: partnerId,
			})

			// 後端裹 {code, data}：result.data 是 axios body，再 .data 才是 ProfitShopOutput
			const shopId = result?.data?.data?.shop_id

			notification.success({
				message: '遷入完成',
				description: shopId
					? `已建立分潤賣場 #${shopId}，即將跳轉至編輯頁`
					: '已建立分潤賣場',
			})

			onClose()

			if (shopId) {
				navigate(`/profit-shop/edit/${shopId}`)
			}
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '遷入失敗',
				description: mapped.toastMessage,
			})

			// 不關閉 modal — 保留 partnerId / confirmText，讓使用者修正後重試
		}
	}

	return (
		<Modal
			title={legacyShop ? `遷入「${legacyShop.title}」` : '遷入舊版一頁賣場'}
			open={open}
			onCancel={onClose}
			maskClosable={false}
			keyboard={false}
			destroyOnClose
			footer={[
				<Button key="cancel" onClick={onClose} disabled={isLoading}>
					取消
				</Button>,
				<Button
					key="submit"
					type="primary"
					danger
					disabled={!canSubmit}
					loading={isLoading}
					onClick={handleSubmit}
				>
					確認遷入
				</Button>,
			]}
		>
			<Alert
				type="warning"
				showIcon
				message="此操作不可逆"
				description={
					<>
						遷入後 legacy 一頁賣場資料將轉換為 Profit Shop。
						<div className="tw-mt-1 tw-text-xs">
							原資料雖會保留，但若需手動回退，需聯絡管理員處理。
						</div>
					</>
				}
				className="tw-mb-4"
			/>

			<Form layout="vertical" disabled={isLoading}>
				<Item
					label="選擇分潤夥伴"
					required
					extra="新建立的 Profit Shop 必須掛在某個 partner 下"
				>
					<PartnerSelector
						value={partnerId ?? undefined}
						onChange={(v) => setPartnerId(v ?? null)}
						placeholder="請選擇要將此賣場掛在哪位夥伴下"
					/>
				</Item>

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
	)
}

export const ImportModal = memo(ImportModalComponent)
