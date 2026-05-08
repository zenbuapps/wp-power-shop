/**
 * Partner Portal 趨勢圖
 *
 * 以 echarts 折線圖呈現分潤趨勢：
 * - X 軸：date (string)
 * - Y 軸：profit（後端字串，繪圖時轉 number）
 * - 切換 interval：day / week / month（antd Segmented）
 *
 * 狀態：
 * - loading：Skeleton.Image
 * - error：Alert + 重試按鈕
 * - empty：series 全 0 → 友善提示「目前期間無波動」
 *
 * 響應式：
 * - 圖表容器寬度自適應，視窗 resize 時重繪
 * - mobile（<= 576）高度自動縮小
 *
 * 圖表生命週期（Phase 4-B3 reviewer C-1 修正）：
 * - 使用 callback ref：DOM mount 即觸發 echarts.init，unmount 觸發 dispose
 * - chart 容器 div 永遠 mount（用 display 切換可見性），確保 callback ref 一直生效
 * - setOption effect 對 data 變動 + chart instance 存在性都做檢查，避免 race
 */

import {
	Alert,
	Button,
	Card,
	Empty,
	Segmented,
	Skeleton,
	Space,
	Typography,
} from 'antd'
import { init as echartsInit, type ECharts, type EChartsOption } from 'echarts'
import { debounce } from 'lodash-es'
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { type TTrendInterval, type TTrendPoint } from '../api/reports'
import { useTrend } from '../hooks/useTrend'
import { formatAmount } from '../utils/format'
import { mapPartnerException } from '../utils/partnerExceptionMapper'

/** TrendChart props */
type TTrendChartProps = {
	dateStart: number
	dateEnd: number
}

/** Partner Portal 趨勢圖元件 */
const TrendChartComponent: React.FC<TTrendChartProps> = ({
	dateStart,
	dateEnd,
}) => {
	// reviewer m-1：避免 shadow 全域 setInterval
	const [intervalValue, setIntervalValue] = useState<TTrendInterval>('day')

	const { data, isLoading, isError, error, refetch, isFetching } = useTrend({
		date_start: dateStart,
		date_end: dateEnd,
		interval: intervalValue,
	})

	const chartInstanceRef = useRef<ECharts | null>(null)

	// callback ref：DOM mount 觸發 init，unmount 觸發 dispose
	// 解決 reviewer C-1：原 useEffect(deps=[]) 在初次 mount 時 chartRef 為 null（被 Skeleton 取代），
	// 後續 isLoading 轉 false 時不會再跑，導致 echarts instance 永遠不會建立。
	const setChartContainer = useCallback((node: HTMLDivElement | null): void => {
		if (node && !chartInstanceRef.current) {
			chartInstanceRef.current = echartsInit(node)
		} else if (!node && chartInstanceRef.current) {
			chartInstanceRef.current.dispose()
			chartInstanceRef.current = null
		}
	}, [])

	// 視窗 resize 時觸發 echarts.resize（debounced）
	useEffect(() => {
		const handler = debounce(() => {
			chartInstanceRef.current?.resize()
		}, 200)
		window.addEventListener('resize', handler)
		return () => {
			window.removeEventListener('resize', handler)
			handler.cancel?.()
		}
	}, [])

	// BUG-2 防禦縱深：即使 useTrend 已 normalize 為陣列，
	// 元件端再加 Array.isArray guard，避免 hook 簽名變動時 component 立刻崩。
	const points = useMemo<TTrendPoint[]>(
		() => (Array.isArray(data) ? data : []),
		[data]
	)

	// 計算 series 是否全為 0（empty state 判斷）
	const isEmpty = useMemo(() => {
		if (points.length === 0) return true
		return points.every((point) => parseFloat(point.profit ?? '0') === 0)
	}, [points])

	// 推送資料到 echarts
	useEffect(() => {
		const chart = chartInstanceRef.current
		if (!chart) return

		const xAxisData = points.map((point) => point.date)
		const seriesData = points.map((point) => {
			const num = parseFloat(point.profit ?? '0')
			return Number.isNaN(num) ? 0 : num
		})

		const option: EChartsOption = {
			tooltip: {
				trigger: 'axis',
				valueFormatter: (value) =>
					`NT$ ${formatAmount(typeof value === 'number' ? value : 0)}`,
			},
			grid: {
				top: 24,
				left: 16,
				right: 24,
				bottom: 24,
				containLabel: true,
			},
			xAxis: {
				type: 'category',
				data: xAxisData,
				axisLabel: {
					rotate: points.length > 14 ? 45 : 0,
				},
			},
			yAxis: {
				type: 'value',
				axisLabel: {
					formatter: (value: number) => formatAmount(value),
				},
			},
			series: [
				{
					name: '分潤金額',
					type: 'line',
					smooth: true,
					data: seriesData,
					itemStyle: { color: '#1677ff' },
					areaStyle: { opacity: 0.15 },
				},
			],
		}

		chart.setOption(option, true)
	}, [points])

	const handleIntervalChange = (value: string | number): void => {
		setIntervalValue(value as TTrendInterval)
	}

	if (isError) {
		return (
			<Card title="分潤趨勢">
				<Alert
					type="error"
					showIcon
					message="載入趨勢失敗"
					description={mapPartnerException(error)}
					action={
						<Button
							size="small"
							onClick={() => void refetch()}
							loading={isFetching}
						>
							重試
						</Button>
					}
				/>
			</Card>
		)
	}

	return (
		<Card title="分潤趨勢">
			<Space direction="vertical" size={12} style={{ width: '100%' }}>
				<Segmented
					value={intervalValue}
					onChange={handleIntervalChange}
					options={[
						{ label: '日', value: 'day' },
						{ label: '週', value: 'week' },
						{ label: '月', value: 'month' },
					]}
				/>

				{isLoading && (
					<Skeleton.Node active style={{ width: '100%', height: 320 }}>
						<div />
					</Skeleton.Node>
				)}

				{!isLoading && isEmpty && (
					<Empty
						image={Empty.PRESENTED_IMAGE_SIMPLE}
						description={
							<Typography.Text type="secondary">目前期間無波動</Typography.Text>
						}
					/>
				)}

				{/*
				 * 圖表容器：永遠 render（用 display 切換可見性）
				 * 確保 callback ref 在 DOM mount 時觸發 echarts.init
				 */}
				<div
					ref={setChartContainer}
					style={{
						width: '100%',
						height: 320,
						minHeight: 240,
						display: !isLoading && !isEmpty ? 'block' : 'none',
					}}
				/>
			</Space>
		</Card>
	)
}

export const TrendChart = memo(TrendChartComponent)
