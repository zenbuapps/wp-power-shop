import { Alert, App, Button, Form, Input, InputNumber, Modal } from 'antd'
import { memo, useEffect, useState } from 'react'
import { useNavigate } from 'react-router'

import { useLegacyShopImport } from '@/pages/admin/ProfitMigration/hooks'
import type { TLegacyShop } from '@/pages/admin/ProfitMigration/types'
import { PartnerSelector } from '@/pages/admin/ProfitPartner/components/PartnerSelector'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Item } = Form

/** 二次確認需輸入的字串（必須完整大寫匹配，避免「import」誤觸） */
export const CONFIRM_PHRASE = 'IMPORT'

/** 分潤比例預設值（legacy 沒有分潤概念，站長必須自己決定） */
export const DEFAULT_RATE = 0

/**
 * 四道閘門的匯總判定
 *
 * 這是不可逆操作的最後一關，**四個條件缺一不可**：
 *   1. `legacyShop` 存在——Modal 可能在資料還沒到位時被打開
 *   2. `importable`——列表已經 disable 了不可匯入的列，這裡**再驗一次**，
 *      因為列表資料可能是快取的（站長開著頁面時，另一個人剛好匯入了同一個賣場）
 *   3. `partnerId`——legacy 一頁商店沒有 partner 概念，遷入後必須掛在某個夥伴下，
 *      漏掉的話會產生一個沒有分潤對象的賣場
 *   4. `confirmText === 'IMPORT'`——**完整大寫**，避免誤觸
 *   5. 非飛行中——重複送出會匯入兩份
 */
export const canSubmitImport = ({
	legacyShop,
	partnerId,
	confirmText,
	isLoading,
}: {
	legacyShop: { importable: boolean } | null
	partnerId: number | null
	confirmText: string
	isLoading: boolean
}): boolean =>
	!!legacyShop &&
	legacyShop.importable &&
	partnerId !== null &&
	confirmText === CONFIRM_PHRASE &&
	!isLoading

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
 *   5. modal 關閉時清空 partnerId / confirmText / rate（避免下次開啟時殘留）
 *   6. 被跳過的商品（legacy 引用但已刪除）以**不自動消失**的警告顯性告知——
 *      匯入不可逆，靜默丟資料是最糟的處理方式
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
	const [rate, setRate] = useState<number>(DEFAULT_RATE)

	// modal 關閉時清空輸入（避免下次開啟殘留）
	useEffect(() => {
		if (!open) {
			setPartnerId(null)
			setConfirmText('')
			setRate(DEFAULT_RATE)
		}
	}, [open])

	const canSubmit = canSubmitImport({
		legacyShop,
		partnerId,
		confirmText,
		isLoading,
	})

	const handleSubmit = async () => {
		if (!legacyShop || partnerId === null) return
		try {
			const result = await mutateAsync({
				legacy_id: legacyShop.legacy_id,
				partner_term_id: partnerId,
				rate,
			})

			// 後端裹 {code, data}：result.data 是 axios body，再 .data 才是 ProfitShopOutput。
			// ⚠️ 欄位是 `id`，不是 `shop_id`——寫錯的話拿到 undefined，成功後不會導頁。
			const shopId = result?.data?.data?.id
			const skipped = result?.data?.data?.skipped_product_ids ?? []

			notification.success({
				message: '遷入完成',
				description: shopId
					? `已建立分潤賣場 #${shopId}，即將跳轉至編輯頁`
					: '已建立分潤賣場',
			})

			// 匯入不可逆，被丟掉的商品必須讓站長看到，且不能自動消失。
			if (skipped.length > 0) {
				notification.warning({
					message: `有 ${skipped.length} 個商品未被帶入`,
					description: `商品 ${skipped.join('、')} 已不存在（可能已被刪除），因此未加入新賣場。請確認是否需要手動補上替代商品。`,
					duration: 0,
				})
			}

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
						遷入後會<strong>新建</strong>一個分潤賣場，並帶入舊版的{' '}
						<strong>{legacyShop?.product_count ?? 0}</strong> 個商品與其價格設定。
						<div className="mt-1 text-xs">
							新賣場狀態為「草稿」，不會立即對外開放；舊版賣場資料保留不動。
						</div>
					</>
				}
				className="mb-4"
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
					label="分潤比例（%）"
					extra="舊版一頁賣場沒有分潤概念，請指定新賣場的分潤比例；之後仍可在賣場編輯頁調整"
				>
					<InputNumber
						value={rate}
						onChange={(v) => setRate(typeof v === 'number' ? v : DEFAULT_RATE)}
						min={0}
						max={100}
						precision={0}
						addonAfter="%"
						className="w-full"
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
