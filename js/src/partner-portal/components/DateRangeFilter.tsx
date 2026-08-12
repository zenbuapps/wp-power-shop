/**
 * Partner Portal 期間選擇器
 *
 * 支援預設區間切換（本月 / 上月 / 近 7 天 / 近 30 天 / 自訂）。
 * - preset 切換立即觸發 onChange，不需確認按鈕
 * - 切到「自訂」時顯示 RangePicker 讓使用者挑選
 * - 對外 value/onChange 統一使用 unix timestamp（秒）
 *
 * 設計取捨：
 * - 用 antd Segmented 而非 Radio Group：視覺密度較高，行動裝置可換行
 * - 不引入新套件；dayjs 是專案既有依賴
 */

import { DatePicker, Segmented, Space } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import { memo, useMemo } from 'react'

const { RangePicker } = DatePicker

/** 期間範圍（unix timestamp 秒） */
export type TDateRange = {
	date_start: number
	date_end: number
}

/** 預設期間 key */
type TPresetKey = 'this_month' | 'last_month' | 'last_7' | 'last_30' | 'custom'

/** DateRangeFilter props */
type TDateRangeFilterProps = {
	value: TDateRange
	onChange: (range: TDateRange) => void
}

/** 取得本月範圍 */
const getThisMonth = (): TDateRange => ({
	date_start: dayjs().startOf('month').unix(),
	date_end: dayjs().endOf('month').unix(),
})

/** 取得上月範圍 */
const getLastMonth = (): TDateRange => {
	const lastMonth = dayjs().subtract(1, 'month')
	return {
		date_start: lastMonth.startOf('month').unix(),
		date_end: lastMonth.endOf('month').unix(),
	}
}

/** 取得近 N 天範圍（含今日） */
const getLastNDays = (n: number): TDateRange => ({
	date_start: dayjs()
		.subtract(n - 1, 'day')
		.startOf('day')
		.unix(),
	date_end: dayjs().endOf('day').unix(),
})

/**
 * 將目前 value 反推為對應的 preset key
 *
 * 用於初次 render 時判斷預設選項；若 value 不符合任何預設，回 'custom'
 */
const detectPresetKey = (value: TDateRange): TPresetKey => {
	const candidates: { key: TPresetKey; range: TDateRange }[] = [
		{ key: 'this_month', range: getThisMonth() },
		{ key: 'last_month', range: getLastMonth() },
		{ key: 'last_7', range: getLastNDays(7) },
		{ key: 'last_30', range: getLastNDays(30) },
	]
	for (const { key, range } of candidates) {
		if (
			range.date_start === value.date_start &&
			range.date_end === value.date_end
		) {
			return key
		}
	}
	return 'custom'
}

/** Partner 期間選擇器元件 */
const DateRangeFilterComponent: React.FC<TDateRangeFilterProps> = ({
	value,
	onChange,
}) => {
	const presetKey = useMemo(() => detectPresetKey(value), [value])

	const handlePresetChange = (key: string | number): void => {
		const k = key as TPresetKey
		if (k === 'this_month') {
			onChange(getThisMonth())
		} else if (k === 'last_month') {
			onChange(getLastMonth())
		} else if (k === 'last_7') {
			onChange(getLastNDays(7))
		} else if (k === 'last_30') {
			onChange(getLastNDays(30))
		}

		// 'custom' / default: 不主動改 value，等使用者用 RangePicker 選日期
	}

	const handleRangePickerChange = (
		dates: [Dayjs | null, Dayjs | null] | null
	): void => {
		if (!dates || !dates[0] || !dates[1]) return
		onChange({
			date_start: dates[0].startOf('day').unix(),
			date_end: dates[1].endOf('day').unix(),
		})
	}

	return (
		<Space direction="vertical" size={8} style={{ width: '100%' }}>
			<Segmented
				value={presetKey}
				onChange={handlePresetChange}
				options={[
					{ label: '本月', value: 'this_month' },
					{ label: '上月', value: 'last_month' },
					{ label: '近 7 天', value: 'last_7' },
					{ label: '近 30 天', value: 'last_30' },
					{ label: '自訂', value: 'custom' },
				]}
				block
			/>
			{presetKey === 'custom' && (
				<RangePicker
					style={{ width: '100%' }}
					value={[
						dayjs(value.date_start * 1000),
						dayjs(value.date_end * 1000),
					]}
					onChange={handleRangePickerChange}
					allowClear={false}
				/>
			)}
		</Space>
	)
}

export const DateRangeFilter = memo(DateRangeFilterComponent)

/** 取得元件預設初始值（本月） */
export const getDefaultDateRange = (): TDateRange => getThisMonth()
