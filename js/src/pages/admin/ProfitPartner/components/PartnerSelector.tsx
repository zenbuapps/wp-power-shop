import { Select, Spin } from 'antd'
import { memo, useMemo } from 'react'

import { useProfitPartnerList } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'

type TPartnerSelectorProps = {
	value?: number // 受 antd Form.Item 控制的目前值（partner_term_id）
	onChange?: (value: number) => void // 由 antd Form.Item 注入的 onChange
	disabled?: boolean
	placeholder?: string
}

/**
 * 分潤夥伴下拉選單（給 ProfitShop Edit/Create 選 partner_term_id 用）
 *
 * - 直接 useProfitPartnerList 取全部 partners（後端目前無 pagination，總量小）
 * - showSearch + 客製 filterOption：依 name + slug 做模糊搜尋
 * - 顯示 `name (slug)` 雙重識別，避免相似名稱誤選
 */
const PartnerSelectorComponent = ({
	value,
	onChange,
	disabled,
	placeholder = '請選擇分潤夥伴',
}: TPartnerSelectorProps) => {
	const { data, isLoading, isFetching } = useProfitPartnerList()

	const partners: TProfitPartner[] = useMemo(() => {
		const list = data?.data?.data
		return Array.isArray(list) ? list : []
	}, [data])

	const options = useMemo(
		() =>
			partners.map((p) => ({
				label: `${p.name}（${p.slug}）`,
				value: p.id,
				name: p.name,
				slug: p.slug,
			})),
		[partners]
	)

	return (
		<Select<number>
			showSearch
			placeholder={placeholder}
			loading={isLoading || isFetching}
			notFoundContent={
				isLoading ? (
					<div className="tw-text-center tw-py-2">
						<Spin size="small" />
					</div>
				) : (
					'尚未建立任何夥伴'
				)
			}
			options={options}
			value={value}
			onChange={(v) => onChange?.(v)}
			disabled={disabled}
			optionFilterProp="label"
			filterOption={(input, option) => {
				if (!option) return false
				const keyword = input.toLowerCase()
				return (
					(option.name ?? '').toLowerCase().includes(keyword) ||
					(option.slug ?? '').toLowerCase().includes(keyword)
				)
			}}
			className="tw-w-full"
		/>
	)
}

export const PartnerSelector = memo(PartnerSelectorComponent)
