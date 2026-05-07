import { Select, Spin } from 'antd'
import { memo, useMemo } from 'react'

import { useProfitPartnerList } from '@/pages/admin/ProfitPartner/hooks'
import type { TProfitPartner } from '@/pages/admin/ProfitPartner/types'

/**
 * 後端 list endpoint 目前未做 server-side pagination，
 * 一次回傳全部 partner term。當總量逼近此門檻時，給出明顯的提示，
 * 提醒使用者用搜尋過濾、或推動後端補 pagination。
 */
const PARTNER_LIST_WARN_THRESHOLD = 100

type TPartnerSelectorProps = {
	value?: number // 受 antd Form.Item 控制的目前值（partner_term_id）
	onChange?: (value: number | null) => void // 由 antd Form.Item 注入的 onChange（null = 清除）
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

	const isOverThreshold = partners.length >= PARTNER_LIST_WARN_THRESHOLD

	return (
		<Select<number>
			showSearch
			allowClear
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
			onChange={(v) => onChange?.(typeof v === 'number' ? v : null)}
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
			dropdownRender={(menu) => (
				<>
					{menu}
					{isOverThreshold && (
						<div
							className="tw-text-xs tw-text-orange-500 tw-px-3 tw-py-2 tw-border-t tw-border-gray-200"
							role="note"
						>
							結果可能超過 {PARTNER_LIST_WARN_THRESHOLD}{' '}
							筆，請使用上方搜尋過濾。
						</div>
					)}
				</>
			)}
			className="tw-w-full"
		/>
	)
}

export const PartnerSelector = memo(PartnerSelectorComponent)
