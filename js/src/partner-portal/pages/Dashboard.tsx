/**
 * Partner Portal Dashboard
 *
 * Phase 4-B3 範圍：
 * - Partner header（partner_name + 登出按鈕）
 * - DateRangeFilter（state 在 Dashboard 階層，避免 KPI / Trend / Settlements 不同步）
 * - KpiSummary（4 張卡）
 * - TrendChart（趨勢折線圖）
 * - SettlementsTable（結算列表）
 * - 整個內容用 ErrorBoundary 包住，避免單一元件出錯炸掉整頁
 */

import { KeyOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Col, Row, Space, Typography } from 'antd'
import { memo, type ReactNode, useCallback, useState } from 'react'
import { Link } from 'react-router'

import { useAuth } from '../auth/AuthContext'
import {
	DateRangeFilter,
	getDefaultDateRange,
	type TDateRange,
} from '../components/DateRangeFilter'
import { ErrorBoundary } from '../components/ErrorBoundary'
import { ShopFilter } from '../components/ShopFilter'
import {
	clearShopIdsFromLocation,
	readShopIdsFromLocation,
} from '../utils/shopIdsParam'

import { KpiSummary } from './KpiSummary'
import { SettlementsTable } from './SettlementsTable'
import { TrendChart } from './TrendChart'

/**
 * 篩選卡片的單列：左標籤、右控制項
 *
 * 期間與賣場**必須共用同一條標籤軸**，否則會變成「一列有標籤、一列沒有」
 * 或「一個控制項滿版、一個只有 240px 靠左」，右側留下大片空白，
 * 看起來像版面沒排完。標籤寬度用 antd 24 格制固定，兩列自然對齊。
 *
 * RWD：`xs` 時標籤與控制項各佔整行（堆疊），避免窄螢幕把標籤擠成直排。
 */
const FilterRow = memo(
	({ label, children }: { label: string; children: ReactNode }) => (
		<>
			<Col xs={24} sm={5} md={4} lg={3}>
				<Typography.Text type="secondary" style={{ fontSize: 13 }}>
					{label}
				</Typography.Text>
			</Col>
			<Col xs={24} sm={19} md={20} lg={21}>
				{children}
			</Col>
		</>
	)
)
FilterRow.displayName = 'FilterRow'

/** Dashboard 元件 */
const DashboardComponent = () => {
	const { partner, logout } = useAuth()
	const [range, setRange] = useState<TDateRange>(() => getDefaultDateRange())

	/*
	 * 賣場篩選：空陣列 = 全部賣場（預設）。
	 * 與 range 一樣把 state 放在 Dashboard 這層——三塊（KPI / 趨勢 / 結算明細）
	 * 必須看同一組條件，各自持有會出現「KPI 已經換了、下面的表還是舊的」這種
	 * 沒有人會發現自己看錯數字的狀態。
	 *
	 * ─────────────────────────────────────────────────────────
	 * 🔴 初始值必須用 lazy initializer 在**第一次 render 之前**就取得
	 * ─────────────────────────────────────────────────────────
	 * `useKpi` / `useTrend` / `useSettlements` 三個 hook **都沒有 `enabled` 閘門**，
	 * 掛上去就會立刻發查詢。若改成「等 usePartnerShops 回來、或在 useEffect 裡
	 * 才 setShopIds」，render 1 會先以 `[]` 發出 3 次**未篩選**查詢，
	 * 畫面上先閃一輪「全部賣場」的偏大數字，接著才發第二輪（共 6 次請求）。
	 * 夥伴若在跳動前讀數或截圖，拿到的是錯的數字，而且事後完全查不出來
	 * ——沒有錯誤訊息、沒有 log，只有一瞬間的畫面。
	 *
	 * 同檔上方 `range` 的 `useState(() => getDefaultDateRange())` 是同一個寫法，
	 * 這裡沿用，不要改成 useRef + useEffect 延後套用。
	 *
	 * ─────────────────────────────────────────────────────────
	 * 重新掛載時會發生什麼（`clearShopIdsFromLocation` 的存在理由）
	 * ─────────────────────────────────────────────────────────
	 * Dashboard 掛在 `<Route path="/">`，而頁內有 `<Link to="/change-password">`，
	 * 所以「清空篩選 → 進修改密碼 → 返回」會讓本元件**重新 mount**、
	 * lazy initializer 再跑一次。若那時網址還留著 `?shop_ids=`，篩選就會
	 * 自己跑回來，夥伴的體感是「這個篩選器清不掉」。
	 *
	 * 定案：**保留 query（重整仍然生效），但夥伴一動到篩選器就把它從網址移除**。
	 * 首次套用生效，使用者一有動作就交出控制權。
	 */
	const [parsedFromLink] = useState(() => readShopIdsFromLocation())
	const [shopIds, setShopIds] = useState<number[]>(parsedFromLink.ids)

	/*
	 * 連結中無法辨識的賣場編號數量。只在初次掛載讀一次，之後即使網址被清掉
	 * 也不影響已經顯示出來的提示（避免提示閃一下就消失、夥伴根本沒看到）。
	 * 使用者一旦自己動過篩選器就歸零——那時他已經接手，舊連結的瑕疵不再相關。
	 */
	const [droppedShopIdCount, setDroppedShopIdCount] = useState(
		parsedFromLink.droppedCount
	)

	/**
	 * 賣場篩選變動
	 *
	 * 順序重要：**先清網址、再更新 state**（見上方「重新掛載時會發生什麼」）。
	 */
	const handleShopIdsChange = useCallback((next: number[]): void => {
		clearShopIdsFromLocation()
		setDroppedShopIdCount(0)
		setShopIds(next)
	}, [])

	/*
	 * 篩選生效時的揭露責任在 Dashboard，**不能外包給 ShopFilter**。
	 *
	 * ShopFilter 有三條提早 return（載入中 / 載入失敗 / 清單為空），三者都
	 * **不會渲染 <Select>**，但 Dashboard 的 shopIds 照樣送進三塊報表。
	 * 最惡劣的情境是 `/partner-reports/shops` 回 500：夥伴看到「賣場清單載入失敗」
	 * 加上三塊**已經被篩選過**的數字，畫面上沒有任何地方寫著「你正在看單一賣場」，
	 * 也沒有任何控制項可以清除——夥伴會直接認為自己被少算了錢。
	 *
	 * 因此只要 shopIds 非空，這條提示就必須出現，與 ShopFilter 渲染得出什麼無關。
	 *
	 * ⚠️ 必須是常駐的 <Alert>，**不可以**改用 notification（會自動消失，
	 *    夥伴滑到下面看數字時提示早就不見了）。
	 * ⚠️ 本 bundle **沒有 <AntdApp> provider**（grep AntdApp / useApp 零命中），
	 *    `App.useApp()` 會拿到空物件、呼叫當下直接 throw。真的要用 notification
	 *    一律靜態 `import { notification } from 'antd'`（見 Login.tsx）。
	 */
	const filterNotices: string[] = []
	if (shopIds.length > 0) {
		filterNotices.push(`目前只顯示 ${shopIds.length} 個指定賣場的數字。`)
	}
	if (droppedShopIdCount > 0) {
		filterNotices.push(
			`連結中有 ${droppedShopIdCount} 個無法辨識的賣場編號已忽略。`
		)
	}

	return (
		<div
			style={{
				maxWidth: 1080,
				margin: '0 auto',
				padding: 24,
			}}
		>
			<ErrorBoundary>
				<Space direction="vertical" size={16} style={{ width: '100%' }}>
					<Card>
						<div
							style={{
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								flexWrap: 'wrap',
								gap: 12,
							}}
						>
							<div>
								<Typography.Title level={3} style={{ margin: 0 }}>
									{partner ? `歡迎，${partner.partner_name}` : '歡迎'}
								</Typography.Title>
								{partner?.contact_email && (
									<Typography.Paragraph
										type="secondary"
										style={{ marginTop: 4, marginBottom: 0 }}
									>
										聯絡信箱：{partner.contact_email}
									</Typography.Paragraph>
								)}
							</div>
							<Space>
								<Link to="/change-password">
									<Button icon={<KeyOutlined />}>修改密碼</Button>
								</Link>
								<Button onClick={() => void logout()}>登出</Button>
							</Space>
						</div>
					</Card>

					<Card title="篩選條件">
						<Row gutter={[16, 12]} align="middle">
							<FilterRow label="期間">
								<DateRangeFilter value={range} onChange={setRange} />
							</FilterRow>
							<FilterRow label="賣場">
								<ShopFilter value={shopIds} onChange={handleShopIdsChange} />
							</FilterRow>
							{filterNotices.length > 0 && (
								<Col xs={24}>
									<Alert
										type="info"
										showIcon
										message={filterNotices.join('')}
										action={
											shopIds.length > 0 ? (
												<Button
													size="small"
													onClick={() => handleShopIdsChange([])}
												>
													顯示全部賣場
												</Button>
											) : undefined
										}
									/>
								</Col>
							)}
						</Row>
					</Card>

					<KpiSummary
						dateStart={range.date_start}
						dateEnd={range.date_end}
						shopIds={shopIds}
					/>

					<TrendChart
						dateStart={range.date_start}
						dateEnd={range.date_end}
						shopIds={shopIds}
					/>

					<SettlementsTable
						dateStart={range.date_start}
						dateEnd={range.date_end}
						shopIds={shopIds}
					/>
				</Space>
			</ErrorBoundary>
		</div>
	)
}

export const Dashboard = memo(DashboardComponent)
