/**
 * Partner Portal 趨勢圖
 *
 * 以 echarts 折線圖呈現分潤趨勢：
 * - X 軸：date (string)
 * - Y 軸：profit（後端字串，繪圖時轉 number）
 * - 切換 interval：day / week / month（antd Segmented）
 *
 * 狀態：
 * - loading：`<Spin>` 遮罩疊在圖表區（見下方「為何不用 Skeleton」）
 * - error：Alert + 重試按鈕
 * - empty：series 全 0 → 友善提示「目前期間無波動」（載入中不顯示，避免閃錯訊息）
 *
 * ═══ 🔴 為何 loading 不用 Skeleton.Node ═══
 *
 * 這裡原本是 `<Skeleton.Node active style={{ width: '100%', height: 320 }}><div /></Skeleton.Node>`，
 * 實測（2026-08）切換 interval 時 **Skeleton 確實有 render，但 `clientWidth` 是 0**
 * （高 320、寬 0 ⇒ 肉眼完全看不見），使用者看到的是一整片空白，
 * 誤以為系統壞掉——而且**沒有任何錯誤訊息**。
 *
 * 成因是 antd 的結構：`.ant-skeleton-element` 外層是 `display: inline-block`（shrink-to-fit），
 * 而 `style` 被套在**內層** `.ant-skeleton-image` 上 ⇒ 內層的 `width: 100%` 指向一個
 * 寬度由內容決定的父層，形成循環依賴而解析為 0；children 又是空 `<div />`，撐不出任何內在寬度。
 * （與本檔 echarts 那個 bug 同一類：寬度依賴內容、內容依賴寬度。）
 *
 * 改用 `<Spin>` wrapper 一次解掉三件事：不再有寬度塌陷的坑、載入指示明確、
 * 配合 `useTrend` 的 `keepPreviousData` 讓舊圖表留在原地不跳動。
 *
 * 響應式（2026-08 修正，見下方「為何一定要 ResizeObserver」）：
 * - 以 ResizeObserver 觀察容器尺寸，任何造成容器變寬 / 變窄的原因都會重繪
 * - 這是視窗 resize listener 的嚴格超集，因此不再另外掛 window resize
 *
 * 圖表生命週期（Phase 4-B3 reviewer C-1 修正）：
 * - 使用 callback ref：DOM mount 即觸發，unmount 觸發 dispose
 * - chart 容器 div 永遠 mount（用 display 切換可見性），確保 callback ref 一直生效
 * - setOption effect 對 data 變動 + chart instance 存在性都做檢查，避免 race
 *
 * ═══ 🔴 為何一定要 ResizeObserver，而不是只掛 window resize ═══
 *
 * 容器是 `display: none`（loading / empty 狀態）時 `clientWidth` **恆為 0**，
 * 而 `echarts.init()` 就是在那個瞬間被 callback ref 觸發的（容器永遠 mount 是
 * reviewer C-1 的刻意設計）。echarts 量不到尺寸時會 fallback 到約 100px 的預設寬度，
 * 接著資料載入完成、`display` 變回 `block`——**這個變化不會觸發 window resize 事件**，
 * echarts 也沒有內建的容器尺寸監聽 ⇒ 圖表永遠卡在 ~100px 寬，
 * 而且**沒有任何錯誤訊息**（實測：夥伴報表的分潤趨勢圖只有一根細長條，
 * X 軸標籤被擠到只剩一個）。
 *
 * 因此有兩道處理，缺一不可：
 *   1. `ensure_chart()`：容器尺寸為 0 時**不 init**，等有尺寸再建立
 *      （少了這道，第一次 init 就會拿到 fallback 尺寸並印出 echarts warning）
 *   2. ResizeObserver：`display: none → block`、視窗縮放、側欄展開、字體 reflow
 *      全部涵蓋（少了這道，第 1 道會讓圖表永遠不被建立）
 *
 * ⚠️ ResizeObserver callback 是非同步的，可能在 dispose 之後才觸發，
 *    因此一律透過 `chartInstanceRef.current?.` 存取，不可閉包捕獲 chart 變數。
 */

import { QuestionCircleOutlined } from '@ant-design/icons'
import {
	Alert,
	Button,
	Card,
	Empty,
	Segmented,
	Space,
	Spin,
	Tooltip,
	Typography,
} from 'antd'
import { init as echartsInit, type ECharts, type EChartsOption } from 'echarts'
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { type TTrendInterval, type TTrendPoint } from '../api/reports'
import { useTrend } from '../hooks/useTrend'
import { formatAmount } from '../utils/format'
import { mapPartnerException } from '../utils/partnerExceptionMapper'

/** TrendChart props */
type TTrendChartProps = {
	dateStart: number
	dateEnd: number

	/** 賣場篩選；空陣列 = 全部賣場 */
	shopIds?: number[]
}

/** Partner Portal 趨勢圖元件 */
const TrendChartComponent: React.FC<TTrendChartProps> = ({
	dateStart,
	dateEnd,
	shopIds,
}) => {
	// reviewer m-1：避免 shadow 全域 setInterval
	const [intervalValue, setIntervalValue] = useState<TTrendInterval>('day')

	// isFetching 一詞涵蓋首次載入與切換 interval 兩種情境，因此不需要 isLoading
	const { data, isError, error, refetch, isFetching } = useTrend({
		date_start: dateStart,
		date_end: dateEnd,
		interval: intervalValue,
		shop_ids: shopIds,
	})

	const chartInstanceRef = useRef<ECharts | null>(null)
	const resizeObserverRef = useRef<ResizeObserver | null>(null)

	/**
	 * 最新的 echarts option
	 *
	 * chart 可能晚於資料才被建立（容器 display: none 時不 init，見檔頭說明），
	 * 屆時推資料的 effect 早就跑完了。用 ref 保存最新 option，
	 * 讓 `ensureChart()` 一建立成功就能立刻補推，不必等下一次資料變動。
	 */
	const optionRef = useRef<EChartsOption | null>(null)

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

	/*
	 * 「目前期間無波動」只在**確定沒有結果**時顯示。
	 * 載入中一律不顯示：首次載入時 points 必為空，若不擋就會先閃一下
	 * 「無波動」再換成圖表，看起來像資料出錯又自己好了。
	 */
	const showEmpty = !isFetching && isEmpty

	// echarts option（獨立於「有沒有 chart instance」計算，供 ensureChart 補推用）
	const chartOption = useMemo<EChartsOption>(() => {
		const xAxisData = points.map((point) => point.date)
		const seriesData = points.map((point) => {
			const num = parseFloat(point.profit ?? '0')
			return Number.isNaN(num) ? 0 : num
		})

		return {
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
	}, [points])

	/**
	 * 容器有實際尺寸時才建立 echarts instance，並立刻補推最新 option
	 *
	 * 🔴 尺寸為 0 時**必須**回傳 null 而不是硬 init：那會讓 echarts 退回約 100px 的
	 * fallback 寬度並卡在那個尺寸（完整推導見檔頭）。呼叫端是 ResizeObserver，
	 * 容器一有寬度就會再進來一次，因此不會漏建。
	 */
	const ensureChart = useCallback((node: HTMLDivElement): ECharts | null => {
		if (chartInstanceRef.current) return chartInstanceRef.current
		if (node.clientWidth <= 0 || node.clientHeight <= 0) return null

		const chart = echartsInit(node)
		chartInstanceRef.current = chart

		if (optionRef.current) {
			chart.setOption(optionRef.current, true)
		}

		return chart
	}, [])

	// 推送資料到 echarts（chart 尚未建立時先存進 ref，由 ensureChart 補推）
	useEffect(() => {
		optionRef.current = chartOption
		chartInstanceRef.current?.setOption(chartOption, true)
	}, [chartOption])

	/**
	 * callback ref：DOM mount 時掛 ResizeObserver，unmount 時 dispose
	 *
	 * 解決 reviewer C-1：原本是 `useEffect(deps=[])`，而初次 mount 時容器根本不在
	 * DOM 裡（loading 狀態下被別的節點取代），該 effect 又不會再跑第二次，
	 * 導致 echarts instance 永遠不會建立。改用 callback ref 之後，
	 * 容器何時進 DOM 就何時觸發，與 loading 狀態的實作細節解耦。
	 */
	const setChartContainer = useCallback(
		(node: HTMLDivElement | null): void => {
			if (node) {
				// 容器此刻可能還是 display: none（尺寸 0），ensureChart 會自行判斷
				ensureChart(node)

				const observer = new ResizeObserver((entries) => {
					const rect = entries[0]?.contentRect
					if (!rect || rect.width <= 0 || rect.height <= 0) return

					// 尺寸從 0 變成有值時，這裡才是真正建立 chart 的時機
					if (!ensureChart(node)) return

					// ⚠️ 一律走 ref：本 callback 是非同步的，dispose 後仍可能被觸發
					chartInstanceRef.current?.resize()
				})
				observer.observe(node)
				resizeObserverRef.current = observer
				return
			}

			resizeObserverRef.current?.disconnect()
			resizeObserverRef.current = null
			chartInstanceRef.current?.dispose()
			chartInstanceRef.current = null
		},
		[ensureChart]
	)

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
				{/*
				 * 刻度切換靠右：左側留白給圖表的 Y 軸標籤，視覺上不打架。
				 * 標籤「顯示刻度」與 Tooltip 並存是刻意的——單獨一組「日 / 週 / 月」
				 * 看不出它切換的是什麼，而 Tooltip 要 hover 才看得到，
				 * 觸控裝置上更是完全沒有 hover 可用。
				 */}
				<div
					style={{
						display: 'flex',
						justifyContent: 'flex-end',
						alignItems: 'center',
						gap: 8,
						flexWrap: 'wrap',
					}}
				>
					<Typography.Text type="secondary" style={{ fontSize: 13 }}>
						顯示刻度
					</Typography.Text>

					<Tooltip
						title={
							<span>
								調整圖表的時間粒度：
								<br />
								「日」每天一個點，適合看短期波動
								<br />
								「週」每週彙總，適合看一至三個月的走勢
								<br />
								「月」每月彙總，適合看長期趨勢
							</span>
						}
					>
						<QuestionCircleOutlined
							style={{ color: 'rgba(0, 0, 0, 0.45)', cursor: 'help' }}
						/>
					</Tooltip>

					<Segmented
						value={intervalValue}
						onChange={handleIntervalChange}
						options={[
							{ label: '日', value: 'day' },
							{ label: '週', value: 'week' },
							{ label: '月', value: 'month' },
						]}
						disabled={isFetching}
					/>
				</div>

				<Spin spinning={isFetching} tip="載入中…">
					{/*
					 * minHeight 佔位：首次載入時圖表容器是 display: none（不佔高度），
					 * 少了它 Spin 會塌成一條線、轉圈圖示無處可放。
					 */}
					<div style={{ minHeight: 320 }}>
						{showEmpty && (
							<Empty
								image={Empty.PRESENTED_IMAGE_SIMPLE}
								description={
									<Typography.Text type="secondary">
										目前期間無波動
									</Typography.Text>
								}
								style={{ margin: 0, paddingTop: 96 }}
							/>
						)}

						{/*
						 * 圖表容器：永遠 render（用 display 切換可見性）
						 * 確保 callback ref 在 DOM mount 時觸發（echarts 實際建立時機見檔頭）
						 *
						 * display 條件**只看 isEmpty、不看 isFetching**：配合 keepPreviousData，
						 * 切換 interval 期間舊圖表要留在原地，由上層 Spin 表達「正在更新」。
						 */}
						<div
							ref={setChartContainer}
							style={{
								width: '100%',
								height: 320,
								minHeight: 240,
								display: isEmpty ? 'none' : 'block',
							}}
						/>
					</div>
				</Spin>
			</Space>
		</Card>
	)
}

export const TrendChart = memo(TrendChartComponent)
