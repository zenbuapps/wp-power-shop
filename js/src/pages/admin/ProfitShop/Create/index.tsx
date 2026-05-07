import { Create } from '@refinedev/antd'
import { useGo } from '@refinedev/core'
import { App, Form } from 'antd'
import { memo } from 'react'

import { ProfitShopForm } from '@/pages/admin/ProfitShop/components/ProfitShopForm'
import { useProfitShopCreate } from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	type TProfitShopInput,
} from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const CreateComponent = () => {
	const go = useGo()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitShopInput>()
	const creator = useProfitShopCreate()

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()
			const result = await creator.mutateAsync({
				...values,
				status: values.status ?? PROFIT_SHOP_STATUS.DRAFT,
				mode: values.mode ?? PROFIT_SHOP_MODE.PAGE,
				items: [],
				settings: {},
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
			goBack={undefined}
			resource="profit-shop"
		>
			<ProfitShopForm form={form} submitting={creator.isLoading} />
		</Create>
	)
}

export const ProfitShopCreate = memo(CreateComponent)
