import { Edit } from '@refinedev/antd'
import { useParsed } from '@refinedev/core'
import { App, Form, Skeleton, Switch, Tag } from 'antd'
import { memo, useEffect, useRef, useState } from 'react'

import {
	ProfitShopForm,
	isFormValidationError,
	resolveValidationFeedback,
} from '@/pages/admin/ProfitShop/components/ProfitShopForm'
import { ShopActionsButtons } from '@/pages/admin/ProfitShop/Edit/ShopActionsButtons'
import {
	useProfitShopOne,
	useProfitShopUpdate,
} from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_STATUS,
	type TOverrideItemInput,
	type TProfitShop,
	type TProfitShopInput,
	type TShopItemRow,
} from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

/**
 * 表單型別：items 為 ItemsEditor 受控的可編輯陣列
 *
 * 用 `TShopItemRow` 而非 `TOverrideItemInput`，因為表單值會多帶 `_name` / `_sku`
 * 等 UI-only 欄位；這些欄位在 handleSubmit 會被 strip 掉才送出。
 */
type TProfitShopEditFormValues = Omit<TProfitShopInput, 'items'> & {
	items?: TShopItemRow[]
}

const EditComponent = () => {
	const { id } = useParsed()
	const { notification } = App.useApp()
	const [form] = Form.useForm<TProfitShopEditFormValues>()

	const { data, isLoading, isFetching } = useProfitShopOne(id)
	const updater = useProfitShopUpdate()

	// 受控 Tab：驗證失敗時要能主動切到出錯的那一頁（見 ProfitShopForm 的 resolveValidationFeedback）
	const [activeTab, setActiveTab] = useState('basic')

	const record: TProfitShop | undefined = data?.data?.data

	/**
	 * footer 的「發佈 / 草稿」開關值
	 *
	 * 刻意**不**在 footer 另包一個 `<Form form={form}>` 來掛 `Form.Item`：
	 * rc-field-form 每次 render 都會用該 `<Form>` 的 `initialValues` 覆寫 store 的
	 * initial 值，第二個沒帶 `initialValues` 的 Form 會把 `ProfitShopForm` 設好的
	 * 預設值清成 undefined。改用 `useWatch` 讀 + `setFieldValue` 寫，
	 * 值一樣進同一個 form store，送出時 `values.status` 照樣拿得到。
	 */
	const watchedStatus = Form.useWatch('status', form)
	const status = watchedStatus ?? record?.status

	// 防止 React Query refetch（window focus / staleTime 過期）後 record reference 變更，
	// 觸發 useEffect 覆蓋使用者正在編輯中的內容。
	// 只在首次取得 record 或切換到不同 id 的 edit 頁時填入表單。
	const filledIdRef = useRef<number | null>(null)

	// 取得資料後填入表單
	useEffect(() => {
		if (!record) return
		if (filledIdRef.current === record.id) return
		filledIdRef.current = record.id

		form.setFieldsValue({
			title: record.title,
			slug: record.slug,
			status: record.status,
			mode: record.mode,
			partner_term_id: record.partner_term_id,
			rate: record.rate,

			// 巢狀契約（BUG-A 修補）：override / variations 原樣帶入表單。
			// 對 override 逐欄取值而非整包帶，是為了讓「後端曾回傳缺欄位的舊資料」
			// 也能收斂成三欄齊全的形狀，避免 ItemsEditor 讀到 undefined。
			items: record.items.map((it) => ({
				product_id: it.product_id,
				inflated_count: it.inflated_count,
				override: {
					regular_price: it.override?.regular_price ?? null,
					sale_price: it.override?.sale_price ?? null,
					signup_fee: it.override?.signup_fee ?? null,
				},
				variations: it.variations ?? {},
			})),
		})
	}, [record, form])

	const handleSubmit = async () => {
		if (!record) return
		try {
			const values = await form.validateFields()

			// items 由 ItemsEditor 受控編輯；提交時把表單值送上，
			// 後端會以 PUT 整體覆寫。settings 仍沿用後端既有資料（4-A3 才開放編輯）。
			//
			// ⚠️ 這裡是 BUG-A 的注入點：只挑後端契約定義的 4 個欄位送出，
			// 順帶 strip 掉 `_name` / `_sku` / `_enrichState` 等 UI-only 欄位。
			const items: TOverrideItemInput[] = (values.items ?? []).map((it) => ({
				product_id: it.product_id,
				inflated_count: it.inflated_count,
				override: it.override,
				variations: it.variations,
			}))

			await updater.mutateAsync(record.id, {
				title: values.title,
				slug: values.slug,
				status: values.status,
				mode: values.mode,
				partner_term_id: values.partner_term_id,
				rate: values.rate,
				items,
				settings: record.settings,
			})
			notification.success({
				message: '已更新賣場',
				description: `#${record.id} ${values.title ?? ''}`,
			})
		} catch (err) {
			/*
			 * antd `validateFields()` 失敗不是後端錯誤，但**不能靜默 return** ——
			 * 欄位分散在多個 Tab，非 active 的 pane 是 display:none，紅字提示看不到，
			 * 使用者只會覺得「按了儲存沒反應」。主動切到出錯的 Tab 並跳通知。
			 */
			if (isFormValidationError(err)) {
				const { tabKey, messages } = resolveValidationFeedback(err)
				if (tabKey) setActiveTab(tabKey)
				notification.warning({
					message: '尚有欄位未填寫',
					description: messages.length
						? messages.join('、')
						: '請檢查各分頁的必填欄位',
				})
				return
			}

			const mapped = mapProfitShopException(err)
			if (mapped.fieldErrors) {
				const fields = Object.entries(mapped.fieldErrors).map(
					([name, msg]) => ({
						name,
						errors: [msg],
					})
				)
				form.setFields(fields)
			}

			/*
			 * Refine 的 dataProvider 會把 axios error 轉成 HttpError（`{message, statusCode}`），
			 * 原始的 `response.data` 已經不在 —— 所以共用的 `mapProfitShopException`
			 * 取不到 body、拿不到 `code`，只能回「操作失敗，請稍後再試」這種通用 fallback。
			 * （實測它回報的 statusCode 也不準：後端回 404，HttpError 卻是 500，
			 *   所以不能靠 code / statusCode 判斷。）
			 *
			 * 但後端其實把原因寫進了 message —— `ItemValidator` 丟的是
			 * 「商品 ID 999777 不存在」。這裡把它撈出來用，並在辨識出「商品不存在」時
			 * 補上可行動的指引：表格裡那些列已經標了「商品已失效」Tag，直接把人指過去。
			 *
			 * ⚠️ 只在這一頁做；`profitShopExceptionMapper` 的通用翻譯其他頁面也在用，不動它。
			 */
			const rawMessage =
				typeof (err as { message?: unknown })?.message === 'string'
					? (err as { message: string }).message
					: ''

			const isMissingProduct =
				'not_found' === mapped.code ||
				/商品\s*ID\s*\d+\s*不存在/.test(rawMessage)

			if (isMissingProduct) {
				notification.error({
					message: '儲存失敗：賣場中有商品已不存在',
					description: `${rawMessage ? `${rawMessage}。` : ''}請移除表格中標示「商品已失效」的項目後再儲存。`,

					// 這類錯誤要使用者動手處理才會消失，預設 4.5 秒不夠讀完＋操作，
					// 改成不自動關閉（僅此一條，不動全域設定）
					duration: 0,
				})
				return
			}

			notification.error({
				message: '更新失敗',

				// 解析不出 code 時（Refine HttpError）優先用後端原文，別讓它退化成無資訊的通用句
				description: mapped.code
					? mapped.toastMessage
					: rawMessage || mapped.toastMessage,
			})
		}
	}

	return (
		<Edit
			title={
				record ? (
					<>
						{record.title}{' '}
						<Tag bordered={false} color="blue">
							#{record.id}
						</Tag>
					</>
				) : (
					'載入中…'
				)
			}
			isLoading={isLoading}
			saveButtonProps={{
				children: '儲存',
				loading: updater.isLoading,
				onClick: handleSubmit,
				disabled: !record,
			}}
			headerButtons={() =>
				record ? <ShopActionsButtons shop={record} /> : null
			}
			footerButtons={({ defaultButtons }) => (
				<>
					<Switch
						className="mr-4"
						checkedChildren="發佈"
						unCheckedChildren="草稿"
						checked={PROFIT_SHOP_STATUS.PUBLISH === status}
						disabled={!record || updater.isLoading}
						onChange={(checked) =>
							form.setFieldValue(
								'status',
								checked ? PROFIT_SHOP_STATUS.PUBLISH : PROFIT_SHOP_STATUS.DRAFT
							)
						}
					/>
					{defaultButtons}
				</>
			)}
			goBack={undefined}
			footerButtonProps={{}}
			recordItemId={record?.id}
			resource="profit-shop"
			canDelete={false}
		>
			{isLoading || isFetching || !record ? (
				<Skeleton active paragraph={{ rows: 8 }} />
			) : (
				<ProfitShopForm
					form={form}
					record={record}
					submitting={updater.isLoading}
					mode="edit"
					activeTab={activeTab}
					onTabChange={setActiveTab}
				/>
			)}
		</Edit>
	)
}

export const ProfitShopEdit = memo(EditComponent)
