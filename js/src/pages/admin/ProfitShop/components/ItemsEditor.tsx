import { DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import { useSelect } from '@refinedev/antd'
import {
	App,
	Button,
	Empty,
	InputNumber,
	Select,
	Space,
	Table,
	Tag,
	type TableProps,
} from 'antd'
import { memo, useState } from 'react'

import type { TOverrideItem } from '@/pages/admin/ProfitShop/types'

type TItemsEditorProps = {
	value?: TOverrideItem[] // 受 antd Form.Item 控制的目前值
	onChange?: (value: TOverrideItem[]) => void // 由 antd Form.Item 注入
	disabled?: boolean
}

/** WooCommerce 商品最小欄位（給 useSelect 用） */
type TWcProductOption = {
	id: number
	name: string
	sku?: string
}

/**
 * OverrideItem 列表編輯器
 *
 * 4-A2 範圍限制：
 *   - 不支援變體層編輯（variation_id 固定 0），4-A3 / 後續迭代再開
 *   - 不允許重複 product_id（由前端 dedupe，防呆即可，後端會做最終驗證）
 *
 * 設計：
 *   - 上方：useSelect 搜尋 WooCommerce 商品 → 選擇後加入列表
 *   - 下方：Table 列出已加入的 items，可編輯 price_override / inflated_count、移除
 *   - price_override：null = 沿用原價；輸入 0 = 免費贈品；正整數 = 覆寫單價（單位元）
 */
const ItemsEditorComponent = ({
	value,
	onChange,
	disabled,
}: TItemsEditorProps) => {
	const { notification } = App.useApp()
	const items: TOverrideItem[] = value ?? []

	// 暫存「下一個要加入」的 product_id（Select onChange）
	const [pendingProductId, setPendingProductId] = useState<number | null>(null)

	// 用 useSelect 走 wc-rest 取商品候選（與 antd-toolkit ProductSelector 同一條 endpoint）
	const { selectProps, query } = useSelect<TWcProductOption>({
		resource: 'products',
		dataProviderName: 'wc-rest',
		optionLabel: 'name',
		optionValue: 'id',
		onSearch: (keyword) => [
			{
				field: 'search',
				operator: 'eq',
				value: keyword,
			},
		],
		pagination: { pageSize: 20 },
	})

	const productOptions = query?.data?.data ?? []

	const findProduct = (productId: number) =>
		productOptions.find((p) => p.id === productId)

	const handleAdd = () => {
		if (pendingProductId === null) {
			notification.warning({ message: '請先選擇商品' })
			return
		}
		if (items.some((it) => it.product_id === pendingProductId)) {
			notification.warning({
				message: '此商品已存在於賣場',
				description: '若要調整價格 / 浮報數，請直接編輯下方表格',
			})
			return
		}

		const product = findProduct(pendingProductId)
		const next: TOverrideItem = {
			product_id: pendingProductId,
			variation_id: 0,
			price_override: null,
			inflated_count: 0,
			sku: product?.sku,
			name: product?.name,
		}
		onChange?.([...items, next])
		setPendingProductId(null)
	}

	const handleRemove = (productId: number) => {
		onChange?.(items.filter((it) => it.product_id !== productId))
	}

	const handleUpdate = (productId: number, patch: Partial<TOverrideItem>) => {
		onChange?.(
			items.map((it) =>
				it.product_id === productId ? { ...it, ...patch } : it
			)
		)
	}

	const columns: TableProps<TOverrideItem>['columns'] = [
		{
			title: '商品',
			dataIndex: 'product_id',
			width: 240,
			render: (productId: number, record) => (
				<div>
					<div className="tw-font-medium">{record.name ?? `#${productId}`}</div>
					<div className="tw-text-xs tw-text-gray-500">
						<Tag bordered={false}>#{productId}</Tag>
						{record.sku ? <code>{record.sku}</code> : null}
					</div>
				</div>
			),
		},
		{
			title: '變體',
			dataIndex: 'variation_id',
			width: 90,
			align: 'center',
			render: (vid: number) =>
				vid > 0 ? <Tag color="purple">#{vid}</Tag> : <Tag>—</Tag>,
		},
		{
			title: '價格覆寫（元）',
			dataIndex: 'price_override',
			width: 200,
			render: (po: number | null, record) => (
				<Space>
					<InputNumber
						min={0}
						value={po ?? undefined}
						placeholder="沿用原價"
						onChange={(v) =>
							handleUpdate(record.product_id, {
								price_override: typeof v === 'number' ? v : null,
							})
						}
						disabled={disabled}
						style={{ width: 130 }}
					/>
					<Button
						size="small"
						type="link"
						onClick={() =>
							handleUpdate(record.product_id, { price_override: null })
						}
						disabled={disabled || record.price_override === null}
					>
						清除
					</Button>
				</Space>
			),
		},
		{
			title: '浮報數',
			dataIndex: 'inflated_count',
			width: 130,
			render: (n: number, record) => (
				<InputNumber
					min={0}
					value={n}
					onChange={(v) =>
						handleUpdate(record.product_id, {
							inflated_count: typeof v === 'number' ? v : 0,
						})
					}
					disabled={disabled}
					style={{ width: 110 }}
				/>
			),
		},
		{
			title: '操作',
			key: 'actions',
			width: 90,
			fixed: 'right',
			render: (_: unknown, record) => (
				<Button
					type="link"
					danger
					size="small"
					icon={<DeleteOutlined />}
					onClick={() => handleRemove(record.product_id)}
					disabled={disabled}
				>
					移除
				</Button>
			),
		},
	]

	return (
		<div className="tw-flex tw-flex-col tw-gap-3">
			<Space.Compact className="tw-w-full">
				{/*
				 * 使用 useSelect 提供的 selectProps（含 onSearch / options / loading），
				 * 但用我方 value/onChange 收斂為單選 number。spread 後手動覆寫，
				 * 因 SelectProps 的型別參數會與 selectProps 推論衝突，故對外層整體 cast 為 SelectProps。
				 */}
				<Select
					{...(selectProps as Record<string, unknown>)}
					value={pendingProductId ?? undefined}
					onChange={(v) =>
						setPendingProductId(typeof v === 'number' ? v : null)
					}
					placeholder="搜尋商品名稱（輸入關鍵字 / 商品 ID）"
					showSearch
					disabled={disabled}
					style={{ flex: 1 }}
				/>
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={handleAdd}
					disabled={disabled || pendingProductId === null}
				>
					加入賣場
				</Button>
			</Space.Compact>

			<Table<TOverrideItem>
				rowKey="product_id"
				dataSource={items}
				columns={columns}
				pagination={false}
				size="small"
				scroll={{ x: 750 }}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description="尚未加入任何商品"
						/>
					),
				}}
			/>
		</div>
	)
}

export const ItemsEditor = memo(ItemsEditorComponent)
