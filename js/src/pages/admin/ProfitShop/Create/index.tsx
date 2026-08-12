import { Create } from '@refinedev/antd'
import { useGo } from '@refinedev/core'
import { App, Form, Switch } from 'antd'
import { memo, useState } from 'react'

import {
	ProfitShopForm,
	isFormValidationError,
	resolveValidationFeedback,
} from '@/pages/admin/ProfitShop/components/ProfitShopForm'
import { useProfitShopCreate } from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	type TProfitShopInput,
} from '@/pages/admin/ProfitShop/types'
import {
	mergeScheduleSettings,
	type TScheduleFormValues,
} from '@/pages/admin/ProfitShop/utils'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

/**
 * 表單型別：settings 只承載 ScheduleTab 的排程欄位（時間為 Dayjs）
 *
 * 建立時後端還沒有既有的 settings 可合併，因此 base 傳 undefined。
 */
type TProfitShopCreateFormValues = Omit<TProfitShopInput, 'settings'> & {
	settings?: TScheduleFormValues
}

const CreateComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitShopCreateFormValues>()
	const creator = useProfitShopCreate()

	// 受控 Tab：驗證失敗時要能主動切到出錯的那一頁（見 ProfitShopForm 的 resolveValidationFeedback）
	const [activeTab, setActiveTab] = useState('basic')

	// 狀態改由 Card footer 的開關控制（與 Edit 頁一致）；欄位本身註冊在 ProfitShopForm 的隱藏 Item
	const status = Form.useWatch('status', form) ?? PROFIT_SHOP_STATUS.DRAFT

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()
			const result = await creator.mutateAsync({
				...values,
				status: values.status ?? PROFIT_SHOP_STATUS.DRAFT,
				mode: values.mode ?? PROFIT_SHOP_MODE.PAGE,
				items: [],

				// 排程欄位的 Dayjs → 毫秒；建立時沒有既有 settings 可合併。
				settings: mergeScheduleSettings(undefined, values.settings),
			})
			const newId = result?.data?.data?.id
			notification.success({
				message: '已建立賣場',
				description: `#${newId} ${values.title}`,
			})
			if (newId) {
				go({
					to: { resource: 'profit-shop', action: 'edit', id: newId },
				})
			}
		} catch (err) {
			/*
			 * 表單驗證失敗**不能靜默 return** —— 欄位分散在多個 Tab，非 active 的 pane
			 * 是 display:none，紅字提示看不到，使用者只會覺得「按了建立沒反應」。
			 * 這裡主動切到出錯的 Tab 並跳通知（最常見情境：停在「分潤設定」按建立，
			 * 其實是「基本設定」的永久連結 / slug 沒填）。
			 */
			if (isFormValidationError(err)) {
				const { tabKey, messages } = resolveValidationFeedback(err)
				if (tabKey) setActiveTab(tabKey)
				notification.warning({
					message: '尚有欄位未填寫',
					description: messages.length
						? messages.join('、')
						: '請檢查各分頁的必填欄位',
				})
				return
			}

			const mapped = mapProfitShopException(err)
			if (mapped.fieldErrors) {
				const fields = Object.entries(mapped.fieldErrors).map(
					([name, msg]) => ({
						name,
						errors: [msg],
					})
				)
				// 見 Edit/index.tsx 的同一段註解：後端字串欄位名 → antd 具名 union 的橋接。
				form.setFields(fields as Parameters<typeof form.setFields>[0])
			}
			notification.error({
				message: '建立失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<Create
			title="建立分潤賣場"
			saveButtonProps={{
				children: '建立',
				loading: creator.isLoading,
				onClick: handleSubmit,
			}}
			headerButtons={() => null}
			footerButtons={({ defaultButtons }) => (
				<>
					<Switch
						className="mr-4"
						checkedChildren="發佈"
						unCheckedChildren="草稿"
						checked={PROFIT_SHOP_STATUS.PUBLISH === status}
						disabled={creator.isLoading}
						onChange={(checked) =>
							form.setFieldValue(
								'status',
								checked ? PROFIT_SHOP_STATUS.PUBLISH : PROFIT_SHOP_STATUS.DRAFT
							)
						}
					/>
					{defaultButtons}
				</>
			)}
			goBack={undefined}
			resource="profit-shop"
		>
			<ProfitShopForm
				form={form}
				submitting={creator.isLoading}
				mode="create"
				activeTab={activeTab}
				onTabChange={setActiveTab}
			/>
		</Create>
	)
}

export const ProfitShopCreate = memo(CreateComponent)
