import { Form, Input, Tabs, type FormInstance, type TabsProps } from 'antd'
import { memo } from 'react'

import {
	AnalyticsTab,
	BasicTab,
	ItemsTab,
	ProfitTab,
	ScheduleTab,
} from '@/pages/admin/ProfitShop/components/tabs'
import {
	PROFIT_SHOP_DEFAULT_DISPLAY_PARTS,
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_STATUS,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'

type TProfitShopFormProps = {
	form: FormInstance // 受 antd Form 控制；由父層 useForm 傳入
	record?: TProfitShop // 編輯模式：傳入目前 record，用於 currentSlug + items 預設值
	submitting?: boolean // 建立 / 更新中（用於 disabled）
	mode: 'create' | 'edit' // 4-A2：Create 模式不開放 ItemsEditor（建立時 items: []）
	activeTab?: string // 受控 Tab（驗證失敗時父層切到出錯的那一頁）
	onTabChange?: (key: string) => void
}

/**
 * Tab key ↔ 欄位對映的單一真相（Tabs 定義也在本檔，兩者必須同步）
 *
 * ⚠️ 鍵是欄位路徑的**第一段**（見 `resolveValidationFeedback` 的 `f.name?.[0]`）。
 * 「開啟 / 關閉時間」的欄位是巢狀的 `['settings', 'endTime']`，第一段是 `settings`，
 * 所以這裡登記的是 `settings` 而不是個別欄位名。
 */
const FIELD_TO_TAB: Record<string, string> = {
	title: 'basic',
	slug: 'basic',
	mode: 'basic',
	status: 'basic',
	settings: 'schedule',
	partner_term_id: 'profit',
	rate: 'profit',
	items: 'items',
}

/** antd `validateFields()` reject 時的 error 形狀 */
type TValidateError = {
	errorFields?: { name: (string | number)[]; errors: string[] }[]
}

/**
 * 這個 error 是不是 antd 的表單驗證失敗（而非後端錯誤）
 */
export const isFormValidationError = (err: unknown): err is TValidateError =>
	!!err && 'object' === typeof err && 'errorFields' in err

/**
 * 把驗證錯誤換算成「該切到哪個 Tab」+「要提示什麼訊息」
 *
 * ⚠️ 這是「按了建立卻沒反應」的解方 —— 本表單有 2～4 個 Tab，`forceRender` 讓所有
 * 欄位都會註冊並參與驗證，但**非 active 的 Tab pane 是 `display:none`**，
 * 紅字錯誤提示使用者根本看不到。若父層又只是 `return` 掉驗證失敗，
 * 畫面就完全沒有任何回饋（例：停在「分潤設定」按建立，其實是「基本設定」的
 * slug 沒填）。所以驗證失敗時必須主動切頁 + 跳通知。
 */
export const resolveValidationFeedback = (
	err: TValidateError
): { tabKey?: string; messages: string[] } => {
	const fields = err.errorFields ?? []
	const messages = fields.flatMap((f) => f.errors ?? []).filter(Boolean)

	// 取第一個對得上 Tab 的欄位；對不上（未來新增欄位忘了登記）就不切頁，只跳通知
	const tabKey = fields
		.map((f) => FIELD_TO_TAB[String(f.name?.[0] ?? '')])
		.find(Boolean)

	return { tabKey, messages }
}

/**
 * Profit Shop 共用表單欄位區塊
 *
 * Edit / Create 兩頁共用；不負責 submit，由父層處理 onFinish。
 *
 * 版型：對齊 `#/products/edit/:id`，以 Tabs 分頁組織欄位，
 * 「發佈 / 草稿」開關則移到 Card footer（由父層 `footerButtons` 渲染）。
 *
 * ⚠️ 所有 Tab 一律 `forceRender` —— antd Tabs 預設只在點開時才 mount 該 pane，
 * 未 mount 的 `Form.Item` 不會註冊到 form store，`validateFields()` 會**跳過**
 * 那些必填欄位（例：使用者沒點開「分潤設定」就按儲存，`partner_term_id`
 * 的 required 規則不會觸發，反而讓不完整的資料送到後端）。
 */
const ProfitShopFormComponent = ({
	form,
	record,
	submitting,
	mode,
	activeTab,
	onTabChange,
}: TProfitShopFormProps) => {
	const isEdit = 'edit' === mode

	const items: TabsProps['items'] = [
		{
			key: 'basic',
			label: '基本設定',
			forceRender: true,
			children: <BasicTab currentSlug={record?.slug} />,
		},
		{
			key: 'schedule',
			label: '開啟 / 關閉時間',
			forceRender: true,
			children: <ScheduleTab />,
		},
		{
			key: 'profit',
			label: '分潤設定',
			forceRender: true,
			children: <ProfitTab />,
		},

		// 建立賣場時 items 一律送 []，需先有 shop id 才能編輯商品覆寫
		...(isEdit
			? [
					{
						key: 'items',
						label: '賣場商品',
						forceRender: true,
						children: <ItemsTab submitting={submitting} />,
					},
					{
						key: 'analytics',
						label: '銷售數據',
						children: <AnalyticsTab />,
					},
				]
			: []),
	]

	return (
		<Form
			form={form}
			layout="vertical"
			disabled={submitting}
			initialValues={{
				status: PROFIT_SHOP_STATUS.DRAFT,
				mode: PROFIT_SHOP_MODE.PAGE,
				rate: 10,

				/*
				 * 排程呈現預設只勾「正常顯示商品但不能購買」，與後端
				 * `ProfitShopFrontData::DEFAULT_DISPLAY_PARTS` 一致 —— 兩邊都預設本功能
				 * 上線前的既有行為，既有賣場的前台才會零變化。
				 * Edit 頁的 `setFieldsValue({ settings })` 會覆蓋這組預設值。
				 *
				 * ⚠️ 每個 Form 各給一份新陣列（`[...]`），不要共用同一個常數參考 ——
				 * antd 的 initialValues 是「一份會被 form store 持有的物件」，
				 * 共用參考會讓兩個欄位在被就地修改時互相污染。
				 */
				settings: {
					beforeDisplay: [...PROFIT_SHOP_DEFAULT_DISPLAY_PARTS],
					afterDisplay: [...PROFIT_SHOP_DEFAULT_DISPLAY_PARTS],
				},
			}}
		>
			{/*
			 * 狀態（發佈 / 草稿）由 Card footer 的 Switch 操作，但欄位本身必須在這裡註冊：
			 * rc-field-form 的 `validateFields()` 只回傳**已註冊 Field** 的值，
			 * 沒有這個隱藏 Item 的話 `values.status` 會是 undefined，
			 * 使用者切了開關卻存不進去。
			 */}
			<Form.Item name="status" hidden>
				<Input />
			</Form.Item>

			<Tabs items={items} activeKey={activeTab} onChange={onTabChange} />
		</Form>
	)
}

export const ProfitShopForm = memo(ProfitShopFormComponent)
