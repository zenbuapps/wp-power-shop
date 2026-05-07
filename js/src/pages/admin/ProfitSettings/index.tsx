import { Edit } from '@refinedev/antd'
import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
} from '@refinedev/core'
import { App, Form, Input, InputNumber, Skeleton } from 'antd'
import { memo, useEffect, useRef } from 'react'

import { ResetButton } from '@/pages/admin/ProfitSettings/ResetButton'
import type { TWrappedResponse } from '@/pages/admin/ProfitShop/hooks'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

const { Item } = Form

/**
 * Profit Shop 全域設定 DTO（對齊 specs/api/api.yml SettingsDto）
 *
 * - rewrite_slug / report_slug 須符合 ^[a-z0-9][a-z0-9-]{0,29}$（後端 sanitize_title 正規化）
 * - default_rate 範圍 0-100，超出時後端 clamp
 * - page_template 為 string（'default' / 'fullwidth' / 'sidebar' 等，由主題提供）
 */
type TProfitSettings = {
	rewrite_slug: string
	report_slug: string
	default_rate: number
	page_template: string
}

const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,29}$/

/**
 * Profit Settings 編輯頁（List/Edit 整合單頁）
 *
 * 設計：
 *   - useCustom GET /profit-settings 取目前值，filledRef 守門避免 refetch 蓋掉編輯中內容
 *   - submit → useCustomMutation PUT /profit-settings
 *   - ResetButton 在右上角，還原後 invalidate 觸發本頁 refetch + 重新填表
 *
 * 安全紀律：
 *   - reset 操作獨立在 ResetButton（文字輸入 RESET 二次確認）
 *   - PUT 失敗時透過 mapProfitShopException 對 slug 欄位做 setFields
 */
const ProfitSettingsComponent = () => {
	const { notification } = App.useApp()
	const apiUrl = useApiUrl('power-shop')
	const invalidate = useInvalidate()
	const [form] = Form.useForm<TProfitSettings>()

	const { data, isLoading, isFetching } = useCustom<
		TWrappedResponse<TProfitSettings>
	>({
		url: `${apiUrl}/profit-settings`,
		method: 'get',
		queryOptions: {
			refetchOnWindowFocus: false,
		},
		dataProviderName: 'power-shop',
	})

	const settings = data?.data?.data

	// 防 refetch 蓋掉編輯中內容（同 ProfitShop / ProfitPartner Edit pattern）：
	// 首次拿到 settings 時填表並標記 filled；後續 refetch（含 reset 後 invalidate）
	// 由 ResetButton onSuccess 透過 resetSettingsForm 強制重填。
	const filledRef = useRef(false)
	useEffect(() => {
		if (!settings || filledRef.current) return
		filledRef.current = true
		form.setFieldsValue(settings)
	}, [settings, form])

	/**
	 * reset 完成後，由 ResetButton 透過 invalidate 觸發 refetch；
	 * 接著由此 callback 強制重填表單（filledRef 設回 false）。
	 * 透過 ref 暴露給 ResetButton 雖可行，但耦合過深；
	 * 改用「ResetButton onSuccess 後直接呼叫 invalidate + 重新填表」靠 settings reference 變更觸發本 effect。
	 */
	const lastFilledSettingsRef = useRef<TProfitSettings | null>(null)
	useEffect(() => {
		if (!settings) return

		// 偵測 server settings 變化（reset / 外部更新）：reference 不同 + 內容不同就重填
		const last = lastFilledSettingsRef.current
		const isFirstFill = last === null
		const contentChanged =
			!!last &&
			(last.rewrite_slug !== settings.rewrite_slug ||
				last.report_slug !== settings.report_slug ||
				last.default_rate !== settings.default_rate ||
				last.page_template !== settings.page_template)
		if (isFirstFill || contentChanged) {
			lastFilledSettingsRef.current = settings
			if (!isFirstFill) {
				// 非首次 → 視為 server 端變動（如 reset），覆寫表單
				form.setFieldsValue(settings)
			}
		}
	}, [settings, form])

	const updater = useCustomMutation<TWrappedResponse<TProfitSettings>>()

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()

			await updater.mutateAsync({
				url: `${apiUrl}/profit-settings`,
				method: 'put',
				values,
				dataProviderName: 'power-shop',
			})

			notification.success({
				message: '設定已更新',
				description: 'Profit Shop 全域設定已儲存',
			})

			invalidate({
				resource: 'profit-settings',
				invalidates: ['all'],
				dataProviderName: 'power-shop',
			})
		} catch (err) {
			if (err && typeof err === 'object' && 'errorFields' in err) return
			const mapped = mapProfitShopException(err)
			if (mapped.fieldErrors) {
				const fields = Object.entries(mapped.fieldErrors).map(
					([name, msg]) => ({
						name: name as keyof TProfitSettings,
						errors: [msg],
					})
				)
				form.setFields(fields)
			}
			notification.error({
				message: '更新失敗',
				description: mapped.toastMessage,
			})
		}
	}

	return (
		<Edit
			title="Profit Shop 全域設定"
			isLoading={isLoading}
			saveButtonProps={{
				children: '儲存',
				loading: updater.isLoading,
				onClick: handleSubmit,
				disabled: !settings,
			}}
			headerButtons={() => <ResetButton />}
			goBack={undefined}
			footerButtonProps={{}}
			canDelete={false}
			resource="profit-settings"
			recordItemId="singleton"
		>
			{isLoading || isFetching || !settings ? (
				<Skeleton active paragraph={{ rows: 6 }} />
			) : (
				<Form<TProfitSettings>
					form={form}
					layout="vertical"
					disabled={updater.isLoading}
				>
					<Item
						name="rewrite_slug"
						label="Rewrite slug"
						extra="影響賣場前台網址；變更後需重新整理 rewrite rules"
						rules={[
							{ required: true, message: '請輸入 rewrite slug' },
							{
								pattern: SLUG_PATTERN,
								message:
									'格式錯誤：須以小寫英文/數字開頭，僅允許小寫英文、數字、連字號（-），長度 1-30',
							},
						]}
					>
						<Input placeholder="shop" autoComplete="off" />
					</Item>

					<Item
						name="report_slug"
						label="Partner 報表 slug"
						extra="影響 partner 報表前台網址（/profit-report/{slug}/）"
						rules={[
							{ required: true, message: '請輸入 report slug' },
							{
								pattern: SLUG_PATTERN,
								message:
									'格式錯誤：須以小寫英文/數字開頭，僅允許小寫英文、數字、連字號（-），長度 1-30',
							},
						]}
					>
						<Input placeholder="report" autoComplete="off" />
					</Item>

					<Item
						name="default_rate"
						label="預設分潤比例（%）"
						extra="新建立的賣場預設使用此比例；範圍 0-100，超出時後端會自動 clamp"
						rules={[
							{ required: true, message: '請輸入預設分潤比例' },
							{
								type: 'number',
								min: 0,
								max: 100,
								message: '範圍須在 0-100 之間',
							},
						]}
					>
						<InputNumber min={0} max={100} className="tw-w-full" />
					</Item>

					<Item
						name="page_template"
						label="頁面模板"
						extra="由當前主題提供的可用模板（如 default / fullwidth）"
						rules={[{ required: true, message: '請輸入頁面模板' }]}
					>
						<Input placeholder="default" autoComplete="off" />
					</Item>
				</Form>
			)}
		</Edit>
	)
}

export const ProfitSettings = memo(ProfitSettingsComponent)
