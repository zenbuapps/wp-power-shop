import { ArrowRightOutlined, ShopOutlined } from '@ant-design/icons'
import { Button, Card, Space, Tag, Typography } from 'antd'
import { useEnv } from 'antd-toolkit'
import { memo } from 'react'
import { useNavigate } from 'react-router'

const { Title, Paragraph } = Typography

/**
 * 一頁賣場入口（雙路徑）
 *
 * 4-A3 改造：原為「即將推出 + 自動跳新視窗到 wp-admin」placeholder，
 *           現升級為「新版 Profit Shop（推薦）」與「舊版 legacy 一頁賣場」雙入口。
 *
 * 注意：
 *   - 「前往舊版」使用 window.location.href（離開 SPA 到 wp-admin），
 *     是這個元件唯一允許的非 Refine 跳轉。其他 SPA 內導頁全部走 useNavigate。
 *   - SITE_URL 已由 env.tsx 解密注入；不直接讀 window.power_shop_data
 */
const OneShopPageComponent = () => {
	const navigate = useNavigate()
	const { SITE_URL } = useEnv()

	const legacyAdminUrl = `${SITE_URL}/wp-admin/edit.php?post_type=power-shop`

	return (
		<div className="tw-max-w-3xl tw-mx-auto tw-py-6">
			<Title level={3}>一頁賣場</Title>
			<Paragraph type="secondary">
				選擇要使用的版本：推薦使用全新的 Profit Shop 系統。
			</Paragraph>

			<Space direction="vertical" size="large" className="tw-w-full">
				<Card
					title={
						<>
							新版 — Profit Shop <Tag color="green">推薦</Tag>
						</>
					}
					extra={<ShopOutlined />}
				>
					<Paragraph>
						全新的分潤賣場系統：
						<ul className="tw-list-disc tw-pl-5 tw-mt-2 tw-mb-0">
							<li>支援多個分潤夥伴（partner）</li>
							<li>支援價格覆寫（price override）與浮報數（inflated count）</li>
							<li>內建 cart hook 防止前台價格竄改</li>
							<li>partner 自助結算報表</li>
						</ul>
					</Paragraph>
					<Button
						type="primary"
						icon={<ArrowRightOutlined />}
						onClick={() => navigate('/profit-shop')}
					>
						前往 Profit Shop
					</Button>
				</Card>

				<Card
					title={
						<>
							舊版 — 一頁賣場 <Tag>legacy</Tag>
						</>
					}
				>
					<Paragraph>
						WordPress admin 的舊版一頁賣場介面（將逐步淘汰）。
						<br />
						建議僅在尚未遷移的舊賣場上使用；新建立請改用 Profit Shop。
					</Paragraph>
					<Button
						onClick={() => {
							// 唯一允許的非 Refine 跳轉：離開 SPA 到 wp-admin
							window.location.href = legacyAdminUrl
						}}
					>
						前往舊版
					</Button>
				</Card>
			</Space>
		</div>
	)
}

export const OneShop = memo(OneShopPageComponent)
