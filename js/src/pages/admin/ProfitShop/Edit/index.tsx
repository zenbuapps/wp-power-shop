import { Edit } from '@refinedev/antd'
import { useParsed } from '@refinedev/core'
import { App, Form, Skeleton, Tag } from 'antd'
import { memo, useEffect, useRef } from 'react'

import { ProfitShopForm } from '@/pages/admin/ProfitShop/components/ProfitShopForm'
import {
	useProfitShopOne,
	useProfitShopUpdate,
} from '@/pages/admin/ProfitShop/hooks'
import {
	type TProfitShop,
	type TProfitShopInput,
} from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const EditComponent = () => {
	const { id } = useParsed()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitShopInput>()

	const { data, isLoading, isFetching } = useProfitShopOne(id)
	const updater = useProfitShopUpdate()

	const record: TProfitShop | undefined = data?.data?.data

	// 防止 React Query refetch（window focus / staleTime 過期）後 record reference 變更，
	// 觸發 useEffect 覆蓋使用者正在編輯中的內容。
	// 只在首次取得 record 或切換到不同 id 的 edit 頁時填入表單。
	const filledIdRef = useRef<number | null>(null)

	// 取得資料後填入表單
	useEffect(() => {
		if (!record) return
		if (filledIdRef.current === record.id) return
		filledIdRef.current = record.id

		form.setFieldsValue({
			title: record.title,
			slug: record.slug,
			status: record.status,
			mode: record.mode,
			partner_term_id: record.partner_term_id,
			rate: record.rate,
		})
	}, [record, form])

	const handleSubmit = async () => {
		if (!record) return
		try {
			const values = await form.validateFields()
			await updater.mutateAsync(record.id, {
				...values,

				// items / settings 在 4-A1 不開放編輯，沿用後端既有資料即可
				items: record.items.map((it) => ({
					product_id: it.product_id,
					variation_id: it.variation_id,
					price_override: it.price_override,
					inflated_count: it.inflated_count,
				})),
				settings: record.settings,
			})
			notification.success({
				message: '已更新賣場',
				description: `#${record.id} ${values.title ?? ''}`,
			})
		} catch (err) {
			// antd Form.validateFields 失敗時 err 會帶 errorFields，不視為後端錯誤
			if (err && typeof err === 'object' && 'errorFields' in err) return
			const mapped = mapProfitShopException(err)
			if (mapped.fieldErrors) {
				const fields = Object.entries(mapped.fieldErrors).map(
					([name, msg]) => ({
						name,
						errors: [msg],
					})
				)
				form.setFields(fields)
			}
			notification.error({
				message: '更新失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<Edit
			title={
				record ? (
					<>
						{record.title}{' '}
						<Tag bordered={false} color="blue">
							#{record.id}
						</Tag>
					</>
				) : (
					'載入中…'
				)
			}
			isLoading={isLoading}
			saveButtonProps={{
				children: '儲存',
				loading: updater.isLoading,
				onClick: handleSubmit,
				disabled: !record,
			}}
			headerButtons={() => null}
			goBack={undefined}
			footerButtonProps={{}}
			recordItemId={record?.id}
			resource="profit-shop"
			canDelete={false}
		>
			{isLoading || isFetching || !record ? (
				<Skeleton active paragraph={{ rows: 8 }} />
			) : (
				<ProfitShopForm
					form={form}
					record={record}
					submitting={updater.isLoading}
				/>
			)}
		</Edit>
	)
}

export const ProfitShopEdit = memo(EditComponent)
