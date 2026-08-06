import { EditOutlined } from '@ant-design/icons'
import { Edit, useForm } from '@refinedev/antd'
import { useParsed } from '@refinedev/core'
import { Form, Tag, Button, Space } from 'antd'
import { notificationProps } from 'antd-toolkit/refine'
import { ORDER_STATUS } from 'antd-toolkit/wp'
import { memo, useMemo, useState } from 'react'

import { TOrderRecord, TOrderBaseRecord } from '@/pages/admin/Orders/List/types'

import { Detail } from './Detail'
import { IsEditingContext, RecordContext } from './hooks'
import { ProfitPanel } from './ProfitPanel'

const EditComponent = () => {
	const { id } = useParsed()
	const [isEditing, setIsEditing] = useState(false)

	// 初始化資料
	const { formProps, saveButtonProps, query, mutation } = useForm<TOrderRecord>(
		{
			action: 'edit',
			resource: 'orders',
			id,
			redirect: false,
			...notificationProps,
		}
	)

	const record: TOrderBaseRecord | undefined = useMemo(
		() => query?.data?.data,
		[query?.isFetching]
	)

	const status = ORDER_STATUS.find(
		(order_status) => order_status.value === record?.status
	)

	return (
		<IsEditingContext.Provider value={isEditing}>
			<RecordContext.Provider value={record}>
				<div className="sticky-card-actions sticky-tabs-nav">
					<Edit
						resource="posts"
						title={
							<>
								訂單 #{record?.id}{' '}
								<Tag color={status?.color} bordered={false}>
									{status?.label}
								</Tag>
							</>
						}
						headerButtons={() => null}
						saveButtonProps={{
							...saveButtonProps,
							children: '儲存',
							icon: null,
							loading: mutation?.isLoading,
						}}
						isLoading={query?.isLoading}
						footerButtons={({ defaultButtons }) =>
							true ? null : (
								<>
									{!isEditing && (
										<Button
											type="default"
											onClick={() => setIsEditing(true)}
											icon={<EditOutlined />}
										>
											編輯訂單
										</Button>
									)}

									{isEditing && (
										<Space.Compact>
											<Button
												type="default"
												onClick={() => setIsEditing(false)}
											>
												取消
											</Button>
											{defaultButtons}
										</Space.Compact>
									)}
								</>
							)
						}
					>
						<Form {...formProps} layout="vertical">
							<div className="flex justify-end">
								<Button type="default" target="_blank" href={record?.edit_url}>
									前往傳統訂單介面
								</Button>
							</div>
							<Detail />
						</Form>

						{/*
						 * 分潤資訊面板放在 <Form> **之外**：它是唯讀的凍結紀錄，
						 * 不參與訂單表單的送出。放進 Form 內雖然也能運作，
						 * 但會讓人誤以為這些欄位可以編輯。
						 * 沒有分潤品項的訂單，這個元件回傳 null、完全不佔版面。
						 */}
						<ProfitPanel />
					</Edit>
				</div>
			</RecordContext.Provider>
		</IsEditingContext.Provider>
	)
}

export const OrdersEdit = memo(EditComponent)
