import {
	Alert,
	Checkbox,
	DatePicker,
	Form,
	Input,
	type FormRule,
} from 'antd'
import dayjs from 'dayjs'
import { memo } from 'react'

import {
	PROFIT_SHOP_DISPLAY_PART,
	PROFIT_SHOP_SCHEDULE_TEXT_MAX,
	type TProfitShopDisplayPart,
} from '@/pages/admin/ProfitShop/types'

const { Item } = Form

/**
 * 三個**互相獨立**的呈現區塊
 *
 * 全部不勾＝該狀態下什麼都不顯示（只剩賣場標題與站長自訂內容）。
 * 順序由「輔助資訊」到「主要內容」，與前台由上而下的實際排版一致。
 */
const DISPLAY_OPTIONS: {
	value: TProfitShopDisplayPart
	label: string
	hint: string
}[] = [
	{
		value: PROFIT_SHOP_DISPLAY_PART.COUNTDOWN,
		label: '顯示倒數計時',
		hint: '天 / 時 / 分 / 秒四格倒數盤',
	},
	{
		value: PROFIT_SHOP_DISPLAY_PART.TEXT,
		label: '顯示自訂文字',
		hint: '需在下方填寫內容，留空則不會顯示',
	},
	{
		value: PROFIT_SHOP_DISPLAY_PART.PRODUCTS,
		label: '正常顯示商品，但不能購買',
		hint: '商品照常曝光（利於 SEO 與預熱），按鈕停用',
	},
]

/** 兩組（尚未開賣 / 已結束）共用的日期選擇器設定 */
const DATE_PICKER_PROPS = {
	showTime: { format: 'HH:mm' },
	format: 'YYYY-MM-DD HH:mm',
	className: 'w-full max-w-xs',
	allowClear: true,
} as const

/** 自訂文字的共用規則 */
const TEXT_RULES: FormRule[] = [
	{
		max: PROFIT_SHOP_SCHEDULE_TEXT_MAX,
		message: `長度不可超過 ${PROFIT_SHOP_SCHEDULE_TEXT_MAX} 字元`,
	},
]

type TDisplaySectionProps = {
	/** 欄位前綴：before（尚未開賣）/ after（已結束） */
	scope: 'before' | 'after'
	title: string
	description: string
	textPlaceholder: string
	/** 這個狀態下的倒數盤會顯示什麼（兩者語意不同，文案不可共用） */
	countdownHint: string
}

/**
 * 單一狀態（尚未開賣 / 已結束）的呈現設定區塊
 *
 * 自訂文字欄位只在勾了「顯示自訂文字」時出現 —— 沒勾的話那段文字不會被印出來，
 * 留著輸入框會讓站長以為填了會有效果。已填的內容不會因為取消勾選而遺失
 * （值仍在 form store 裡，送出時照常寫回 `settings`），只是前台不顯示。
 */
const DisplaySection = ({
	scope,
	title,
	description,
	textPlaceholder,
	countdownHint,
}: TDisplaySectionProps) => {
	const displayField = `${scope}Display`
	const textField = `${scope}Text`

	const parts = Form.useWatch<TProfitShopDisplayPart[] | undefined>([
		'settings',
		displayField,
	])

	const showCountdown = !!parts?.includes(PROFIT_SHOP_DISPLAY_PART.COUNTDOWN)
	const showText = !!parts?.includes(PROFIT_SHOP_DISPLAY_PART.TEXT)
	const showNothing = Array.isArray(parts) && 0 === parts.length

	return (
		// 上緣橫線把「時間欄位」與「該狀態的呈現設定」分開。
		// ⚠️ 用 `border-t` 而非 `border-b`：powerhouse 的 CSS 有產出前者、沒有後者（Pitfall #19）。
		<div className="mt-4 border-t pt-4">
			<h4 className="mb-1 font-medium">{title}</h4>
			<p className="mb-3 text-sm text-gray-500">{description}</p>

			<Item name={['settings', displayField]} className="mb-3">
				<Checkbox.Group>
					<div className="flex flex-col gap-2">
						{DISPLAY_OPTIONS.map((opt) => (
							<Checkbox key={opt.value} value={opt.value}>
								{opt.label}
								<span className="ml-2 text-sm text-gray-400">{opt.hint}</span>
							</Checkbox>
						))}
					</div>
				</Checkbox.Group>
			</Item>

			{showNothing && (
				<Alert
					type="info"
					showIcon
					className="mb-3"
					message="此狀態下前台不會顯示任何內容"
					description="訪客只會看到賣場標題與您自行編輯的頁面內容。"
				/>
			)}

			{showText && (
				<Item
					name={['settings', textField]}
					label="自訂文字"
					rules={TEXT_RULES}
					extra={
						showCountdown
							? `顯示在倒數盤下方。${countdownHint}`
							: '顯示在商品列表上方。'
					}
				>
					<Input
						placeholder={textPlaceholder}
						maxLength={PROFIT_SHOP_SCHEDULE_TEXT_MAX}
						showCount
					/>
				</Item>
			)}
		</div>
	)
}

/**
 * 「開啟 / 關閉時間」Tab：販售期間與期間外的前台呈現
 *
 * 資料落在 `settings` 這個自由欄位底下（`settings.startTime` / `settings.endTime` /
 * `settings.beforeDisplay` …），送出時**必須**與後端既有的 `settings` 合併，
 * 見 `utils.ts` 的 `mergeScheduleSettings()`。
 *
 * ⚠️ 時間以**瀏覽器所在時區**呈現，儲存為毫秒 epoch；後端以伺服器時間比對。
 * 站長的電腦時區與 WordPress 站台時區不一致時，看到的時間會與實際開賣時間有落差。
 */
const ScheduleTabComponent = () => {
	/** 兩個時間都空 = 永久開放，這時整個「期間外呈現」設定不會有機會生效 */
	const startTime = Form.useWatch<dayjs.Dayjs | null | undefined>([
		'settings',
		'startTime',
	])
	const endTime = Form.useWatch<dayjs.Dayjs | null | undefined>([
		'settings',
		'endTime',
	])
	const hasSchedule = !!startTime || !!endTime

	return (
		<>
			{/*
			 * 單一提示：內容依「有沒有設定時間」切換，不要拆成兩個 Alert ——
			 * 兩個提示講的是同一件事的兩面（排程怎麼運作 / 目前沒排程），
			 * 分開放會讓沒設定時間時畫面上同時出現兩塊藍黃色塊。
			 */}
			<Alert
				type={hasSchedule ? 'info' : 'warning'}
				showIcon
				className="mb-6"
				message={
					hasSchedule ? '賣場販售期間' : '尚未設定任何時間，賣場目前永久開放'
				}
				description={
					<>
						{!hasSchedule && (
							<>
								兩個時間都留空時，賣場不會進入「尚未開賣」或「已結束販售」狀態，下方的呈現設定不會有機會生效。
								<br />
							</>
						)}
						只設開啟時間：到達後永久開放。只設關閉時間：立即開放，到期後關閉。
						<br />
						時間以您電腦所在時區顯示，實際判斷以網站伺服器時間為準。
					</>
				}
			/>

			{/* 左欄 = 開賣前的一切；右欄 = 結束後的一切。兩欄各自「時間 → 該狀態的呈現設定」由上而下讀 */}
			<div className="grid gap-x-6 md:grid-cols-2">
				<div>
					<Item
						name={['settings', 'startTime']}
						label="開啟時間"
						extra="此時間之前，賣場依下方設定呈現"
					>
						<DatePicker
							{...DATE_PICKER_PROPS}
							placeholder="未設定（立即開放）"
						/>
					</Item>

					<DisplaySection
						scope="before"
						title="尚未開賣時"
						description="開啟時間還沒到的期間，前台要顯示哪些內容（可複選；全部不勾＝什麼都不顯示）。"
						textPlaceholder="例：本檔活動 8/10 中午 12:00 準時開賣"
						countdownHint="倒數盤會倒數到上方設定的「開啟時間」。"
					/>
				</div>

				<div>
					<Item
						name={['settings', 'endTime']}
						label="關閉時間"
						extra="此時間之後，賣場依下方設定呈現"
						dependencies={[['settings', 'startTime']]}
						rules={[
							({ getFieldValue }) => ({
								validator(_rule, value) {
									const start = getFieldValue(['settings', 'startTime'])
									if (!value || !start) return Promise.resolve()
									if (dayjs(value).valueOf() > dayjs(start).valueOf()) {
										return Promise.resolve()
									}
									return Promise.reject(
										new Error('關閉時間必須晚於開啟時間，否則賣場永遠不會開賣')
									)
								},
							}),
						]}
					>
						<DatePicker
							{...DATE_PICKER_PROPS}
							placeholder="未設定（永不關閉）"
						/>
					</Item>

					<DisplaySection
						scope="after"
						title="已結束販售時"
						description="超過關閉時間之後，前台要顯示哪些內容（可複選；全部不勾＝什麼都不顯示）。"
						textPlaceholder="例：本檔活動已結束，感謝您的支持"
						countdownHint="賣場已結束，倒數盤會顯示歸零的 00:00:00:00（純視覺一致用）。"
					/>
				</div>
			</div>
		</>
	)
}

export const ScheduleTab = memo(ScheduleTabComponent)
