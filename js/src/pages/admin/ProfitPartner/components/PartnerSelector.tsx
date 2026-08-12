import { PictureOutlined } from '@ant-design/icons'
import { Select, Spin } from 'antd'
import { defaultImage } from 'antd-toolkit'
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

/** 選項內的夥伴圖片（圓形，與列表 / 編輯頁一致）；無圖或圖片失效時退回灰色佔位 icon */
const PartnerAvatar = ({ url, name }: { url: string | null; name: string }) =>
	url ? (
		<img
			src={url}
			alt={name}
			className="w-6 h-6 rounded-full object-cover shrink-0"
			onError={(e) => {
				e.currentTarget.src = defaultImage
			}}
		/>
	) : (
		<span
			className="w-6 h-6 rounded-full bg-gray-100 text-gray-300 flex items-center justify-center shrink-0"
			aria-hidden="true"
		>
			<PictureOutlined />
		</span>
	)

/**
 * 分潤夥伴下拉選單（給 ProfitShop Edit/Create 選 partner_term_id 用）
 *
 * - 直接 useProfitPartnerList 取全部 partners（後端目前無 pagination，總量小）
 * - showSearch + 客製 filterOption：依 name + slug 做模糊搜尋
 * - 選項與已選值都顯示夥伴圖片 + `name（slug）`，避免相似名稱誤選
 *
 * ⚠️ `label` 是 ReactNode（帶圖片），antd 內建的 `optionFilterProp="label"`
 * 對 ReactNode 無法比對字串，因此**必須**保留自訂 `filterOption`
 * 走 option 上額外掛的純字串 `name` / `slug` 欄位——移除它會讓搜尋整個失效。
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
				label: (
					<span className="flex items-center gap-2">
						<PartnerAvatar url={p.image_url ?? null} name={p.name} />
						<span className="truncate">
							{p.name}
							<span className="text-gray-400">（{p.slug}）</span>
						</span>
					</span>
				),
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
					<div className="text-center py-2">
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
							className="text-xs text-orange-500 px-3 py-2 border-t border-gray-200"
							role="note"
						>
							結果可能超過 {PARTNER_LIST_WARN_THRESHOLD}{' '}
							筆，請使用上方搜尋過濾。
						</div>
					)}
				</>
			)}
			className="w-full"
		/>
	)
}

export const PartnerSelector = memo(PartnerSelectorComponent)
