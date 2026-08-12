import { Alert, Modal, Table, Tag, Typography } from 'antd'
import { memo, useMemo } from 'react'

import {
	SETTLEMENT_BATCH_ACTION_META,
	SETTLEMENT_STATUS_META,
	type TSettlementBatchAction,
	type TSettlementRow,
} from '@/pages/admin/ProfitSettlement/types'
import { partitionByAllowedStatus } from '@/pages/admin/ProfitSettlement/utils'

const { Text, Paragraph } = Typography

type TBatchActionModalProps = {
	action: TSettlementBatchAction | null
	rows: TSettlementRow[]
	open: boolean
	isLoading: boolean
	onCancel: () => void
	onConfirm: (orderItemIds: number[]) => void
}

/**
 * 結算批次操作的二次確認 Modal（⚠ 金流操作）
 *
 * 這三個動作都會改動「錢已經付了沒」的紀錄，其中 `mark-paid` 沒有回到 `pending`
 * 的路（`reverse-paid` 只能 paid → refunded），實質上不可逆。因此套用
 * `profit-shop.rule.md` §12.4 的不可逆操作模式：
 *
 *   `closable={false}` / `maskClosable={false}` / `keyboard={false}`
 *
 * 三者缺一，使用者就能用 ESC、點遮罩、或右上角叉叉「離開」這個視窗——
 * 那些手勢在心理上是「取消」，但如果元件把關閉一律當成確認（或反過來，
 * 把它們接到 `onCancel` 以外的地方），就會變成誤觸即撥款。全部關掉、
 * 只留兩顆明確的按鈕，是唯一不會被誤讀的設計。
 *
 * ⚠️ 刻意**不**要求輸入確認字串（對比 `ImportModal` 的 `IMPORT`）：
 * 撥款是站長每個結算週期都要做很多次的例行動作，逐次打字會讓他養成
 * 「反射性輸入」的習慣，反而降低了確認的意義；而 ImportModal 的遷入是
 * 一輩子做一次、且完全無法回頭的操作。這裡改以「明確列出將被改動的每一筆
 * 與金額合計」承載確認的重量——站長看得到自己要付多少錢給誰。
 *
 * ## 事前揭露「會被跳過的筆數」
 *
 * 送出前就依狀態把選取分成兩堆，不合資格的直接在視窗裡列出來。
 * 後端仍會獨立驗證（`SettlementRecord` 狀態機才是唯一真相），這裡只是提前告知：
 * 讓站長在**按下確認之前**就知道「我勾了 50 筆，只有 38 筆會生效」，
 * 而不是送出後才從回應的 `skipped` 發現。
 */
const BatchActionModalComponent = ({
	action,
	rows,
	open,
	isLoading,
	onCancel,
	onConfirm,
}: TBatchActionModalProps) => {
	const meta = action ? SETTLEMENT_BATCH_ACTION_META[action] : null

	const { eligible, ineligible } = useMemo(
		() => partitionByAllowedStatus(rows, meta?.allowedFrom ?? []),
		[rows, meta]
	)

	if (!meta || !action) return null

	const allowedLabels = meta.allowedFrom
		.map((s) => SETTLEMENT_STATUS_META[s].label)
		.join('、')

	return (
		<Modal
			open={open}
			title={meta.title}
			okText={`確認${meta.label}（${eligible.length} 筆）`}
			cancelText="取消"
			okButtonProps={{
				danger: meta.danger,
				disabled: eligible.length === 0,
				loading: isLoading,
			}}
			cancelButtonProps={{ disabled: isLoading }}
			onOk={() => onConfirm(eligible.map((r) => r.order_item_id))}
			onCancel={onCancel}
			width={720}
			/*
			 * ⚠ 三個 false 是這個 Modal 的重點，不是樣式偏好：
			 * 關掉叉叉 / 遮罩點擊 / ESC，強制使用者在「確認」與「取消」之間明確表態。
			 * 見本元件 docblock。
			 */
			closable={false}
			maskClosable={false}
			keyboard={false}
			destroyOnHidden
		>
			<Paragraph>
				即將把 <Text strong>{eligible.length}</Text> 筆結算紀錄從「
				{allowedLabels}」改為「
				<Text strong>{SETTLEMENT_STATUS_META[meta.to].label}</Text>」。
			</Paragraph>

			{eligible.length === 0 && (
				<Alert
					type="warning"
					showIcon
					className="mb-4"
					message="選取的項目中沒有任何一筆符合此操作"
					description={`「${meta.label}」只接受狀態為「${allowedLabels}」的紀錄。`}
				/>
			)}

			{ineligible.length > 0 && (
				<Alert
					type="info"
					showIcon
					className="mb-4"
					message={`其中 ${ineligible.length} 筆狀態不符，將不會被改動`}
					description="這些紀錄會原封不動地留在原本的狀態，不需要另外處理。"
				/>
			)}

			{eligible.length > 0 && (
				<Table<TSettlementRow>
					size="small"
					rowKey="order_item_id"
					dataSource={eligible}
					pagination={
						eligible.length > 10 ? { pageSize: 10, size: 'small' } : false
					}
					scroll={{ y: 260 }}
					columns={[
						{
							title: '訂單',
							dataIndex: 'order_id',
							width: 90,
							render: (orderId: number) => `#${orderId}`,
						},
						{
							title: '商品',
							dataIndex: 'product_name',
							ellipsis: true,
						},
						{
							title: '夥伴',
							dataIndex: 'partner_name',
							width: 140,
							ellipsis: true,
						},
						{
							title: '狀態',
							dataIndex: 'status',
							width: 90,
							render: (status: TSettlementRow['status']) => (
								<Tag color={SETTLEMENT_STATUS_META[status]?.color}>
									{SETTLEMENT_STATUS_META[status]?.label ?? status}
								</Tag>
							),
						},
						{
							title: '應分潤',
							dataIndex: 'profit_amount',
							width: 110,
							align: 'right',

							// 金額是十進位字串，原樣顯示；不要 Number() 再 toFixed
							render: (amount: string) => <Text strong>{amount}</Text>,
						},
					]}
				/>
			)}
		</Modal>
	)
}

export const BatchActionModal = memo(BatchActionModalComponent)
