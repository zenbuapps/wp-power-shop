import { Form } from 'antd'
import { memo } from 'react'

import {
	ItemsEditor,
	validateItemsPriceOverride,
} from '@/pages/admin/ProfitShop/components/ItemsEditor'

const { Item } = Form

type TItemsTabProps = {
	submitting?: boolean
}

/**
 * 「賣場商品」Tab：ItemsEditor（選擇商品 Modal + 已加入商品表格）
 *
 * 只在 edit 模式掛載 —— 建立賣場時 `items` 一律送 `[]`，
 * 需先有 shop id 才能編輯商品覆寫。
 */
const ItemsTabComponent = ({ submitting }: TItemsTabProps) => (
	<Item
		name="items"
		rules={[{ validator: validateItemsPriceOverride }]}
		extra="原價／特價覆寫留空代表沿用商品原價，填 0 代表免費贈品"
	>
		<ItemsEditor disabled={submitting} />
	</Item>
)

export const ItemsTab = memo(ItemsTabComponent)
