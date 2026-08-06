import { Card as AntdCard, Skeleton, Statistic, Typography } from 'antd'
import { memo } from 'react'

import type { TSettlementSummary } from '@/pages/admin/ProfitSettlement/types'

const { Text } = Typography

/**
 * 結算摘要卡（待撥款 / 已撥款 / 退款 / 取消）
 *
 * 🔴 **金額直接顯示後端給的十進位字串，不做任何算術**。
 * antd `Statistic` 的 `value` 收到字串時會原樣渲染（不會轉 float），
 * 但 `precision` / `formatter` 一旦介入就可能把它轉成 number——
 * 這裡刻意都不設，讓 bcmath 算出來的位數原封不動地呈現。
 *
 * 摘要吃的是**與列表相同**的篩選條件（分頁除外）：站長篩了「夥伴A + 8 月」，
 * 摘要就該是那個範圍的加總，而不是全站——否則他無從判斷「待撥款 98 元」
 * 指的是畫面上這幾筆還是全部。
 *
 * ⚠️ 這裡用的是 **antd 的 `Card`**（不是 `antd-toolkit` 的）。
 * `admin-list-ui.rule.md` §1 規則 2 要求的是版型骨架的那兩張卡片必須用
 * antd-toolkit 版本（它多了 `showCard` 開關供內嵌複用）；摘要卡是版型內部的
 * 純視覺元素、不會被當成篩選器嵌到別處，用原生 Card 反而少一層無用的抽象。
 */

type TSummaryCardsProps = {
	summary?: TSettlementSummary
	isLoading: boolean

	/** 幣別符號（來自 `useWoocommerce()`） */
	currencySymbol: string
}

/** 單張卡片的設定 */
type TCardConfig = {
	key: string
	title: string
	amount: keyof TSettlementSummary
	count: keyof TSettlementSummary
	className: string
}

const CARDS: TCardConfig[] = [
	{
		key: 'pending',
		title: '待撥款',
		amount: 'pending_amount',
		count: 'pending_count',
		className: 'text-amber-600',
	},
	{
		key: 'paid',
		title: '已撥款',
		amount: 'paid_amount',
		count: 'paid_count',
		className: 'text-green-600',
	},
	{
		key: 'refunded',
		title: '已退款',
		amount: 'refunded_amount',
		count: 'refunded_count',
		className: 'text-red-500',
	},
	{
		key: 'cancelled',
		title: '已取消',
		amount: 'cancelled_amount',
		count: 'cancelled_count',
		className: 'text-gray-400',
	},
]

const SummaryCardsComponent = ({
	summary,
	isLoading,
	currencySymbol,
}: TSummaryCardsProps) => {
	return (
		<div className="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
			{CARDS.map((card) => (
				<AntdCard key={card.key} size="small">
					{isLoading ? (
						<Skeleton active paragraph={{ rows: 1 }} title={false} />
					) : (
						<Statistic
							title={card.title}
							prefix={currencySymbol}
							/*
							 * 傳字串給 antd Statistic 時它會原樣輸出，不會走 float 格式化。
							 * 這正是我們要的：`profit_amount` 是 bcmath 產出的十進位字串，
							 * 任何一次 Number() 往返都可能改變小數位（分位誤差 = 少付 / 多付錢）。
							 */
							value={summary?.[card.amount] ?? '0.00'}
							valueStyle={{ fontSize: '1.5rem' }}
							className={card.className}
							suffix={
								<Text type="secondary" className="text-sm">
									／{summary?.[card.count] ?? 0} 筆
								</Text>
							}
						/>
					)}
				</AntdCard>
			))}
		</div>
	)
}

export const SummaryCards = memo(SummaryCardsComponent)
