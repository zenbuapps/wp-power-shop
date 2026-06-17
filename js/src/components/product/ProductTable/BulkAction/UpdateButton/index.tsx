import { ArrowsAltOutlined } from '@ant-design/icons'
import { useModal } from '@refinedev/antd'
import { useUpdateMany } from '@refinedev/core'
import { Button, Space, Select, Form } from 'antd'
import { notificationProps } from 'antd-toolkit/refine'
import { PRODUCT_STATUS, PRODUCT_CATALOG_VISIBILITIES } from 'antd-toolkit/wp'
import { useAtomValue } from 'jotai'
import { useState } from 'react'

import { selectedProductsAtom } from '@/components/product/ProductTable/atom'
import ModalForm from '@/components/product/ProductTable/BulkAction/UpdateButton/ModalForm'

const { Item } = Form

type TSimpleFormValues = {
	catalog_visibility?: (typeof PRODUCT_CATALOG_VISIBILITIES)[number]['value']
	status?: (typeof PRODUCT_STATUS)[number]['value']
}

const UpdateButton = () => {
	const [form] = Form.useForm<TSimpleFormValues>()
	const products = useAtomValue(selectedProductsAtom)
	const ids = products.map((product) => product.id)
	const [canUpdate, setCanUpdate] = useState(false)
	const { show, modalProps, close } = useModal()

	const { mutate, isLoading } = useUpdateMany({
		resource: 'products',
	})

	const handleUpdate = () => {
		const values = form.getFieldsValue()
		mutate({
			ids,
			values,
			...notificationProps,
		})
	}

	// 用來判斷是否可以按下修改按鈕
	const handleValuesChange = (
		changedValues: TSimpleFormValues,
		allValues: TSimpleFormValues
	) => {
		const keys = Object.keys(allValues).filter(
			(key) => !!allValues[key as keyof TSimpleFormValues]
		)
		setCanUpdate(keys.length > 0)
	}

	return (
		<>
			<Form layout="vertical" form={form} onValuesChange={handleValuesChange}>
				<div className="flex gap-x-2">
					<Space.Compact block>
						<Item
							name={['catalog_visibility']}
							label="商品可見度"
							className="mb-0"
						>
							<Select
								className="w-48"
								options={PRODUCT_CATALOG_VISIBILITIES}
								allowClear
							/>
						</Item>
						<Item name={['status']} label="發佈狀態" className="mb-0">
							<Select className="w-32" options={PRODUCT_STATUS} allowClear />
						</Item>
						<Item label=" " className="mb-0">
							<Button
								color="primary"
								variant="solid"
								loading={isLoading}
								onClick={handleUpdate}
								disabled={!canUpdate}
							>
								快速修改
							</Button>
						</Item>
					</Space.Compact>
					<Item label=" " className="mb-0">
						<Button
							color="primary"
							variant="filled"
							onClick={show}
							icon={<ArrowsAltOutlined />}
						>
							批量修改
						</Button>
					</Item>
				</div>
			</Form>
			<ModalForm modalProps={modalProps} close={close} />
		</>
	)
}

export default UpdateButton
