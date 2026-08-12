import { Alert, Card, Col, Row, Statistic } from 'antd'
import { memo } from 'react'

/**
 * 「銷售數據」Tab（placeholder）
 *
 * ⚠️ 尚未接後端。現有的 `partner-reports/{kpi,trend,settlements}` 三個 endpoint
 * 是 `partner_token` 認證、且 `partner_term_id` 鎖死在 token 內
 * （見 `.claude/rules/profit-shop.rule.md` §7），admin nonce **不能**呼叫，
 * 也無法指定要看哪個賣場。要在後台顯示單一賣場數據，需另外開 admin 專用 endpoint
 * （例如 `GET /profit-shops/{id}/stats`，permission = 預設 `manage_woocommerce`）。
 *
 * 因此這裡先只擺版位，不做假資料、不打任何 API。
 */
const AnalyticsTabComponent = () => (
	<>
		<Alert
			type="info"
			showIcon
			className="mb-4"
			message="銷售數據尚未開放"
			description="後端目前只有夥伴端（partner token）的報表 API，尚未提供商家後台依賣場查詢的端點。接上之後此頁會顯示本賣場的銷售與結算統計。"
		/>

		<Row gutter={16}>
			<Col xs={24} sm={8}>
				<Card>
					<Statistic title="總銷售" value="—" />
				</Card>
			</Col>
			<Col xs={24} sm={8}>
				<Card>
					<Statistic title="待結算" value="—" />
				</Card>
			</Col>
			<Col xs={24} sm={8}>
				<Card>
					<Statistic title="已結算" value="—" />
				</Card>
			</Col>
		</Row>
	</>
)

export const AnalyticsTab = memo(AnalyticsTabComponent)
