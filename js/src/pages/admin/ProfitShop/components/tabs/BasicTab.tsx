import { Form, Input, Radio, Select } from 'antd'
import { useEnv } from 'antd-toolkit'
import { memo, useMemo } from 'react'

import { SlugInput } from '@/pages/admin/ProfitShop/components/SlugInput'
import { useThemeTemplates } from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_MODE,
	PROFIT_SHOP_URL_PREFIX,
} from '@/pages/admin/ProfitShop/types'

const { Item } = Form

/** slug 欄位共用的衝突檢查說明 */
const CONFLICT_HINT =
	'會經 5 類衝突檢查（保留字 / 站台 page / 既有賣場 / 夥伴 / 商品分類）'

type TBasicTabProps = {
	currentSlug?: string // 編輯模式傳入目前 slug，供 SlugInput 跳過驗證自己
}

/**
 * 「基本設定」Tab：賣場名稱 / 永久連結（slug）/ 呈現模式
 *
 * ⚠️ 狀態（發佈 / 草稿）不在此 Tab —— 已移到 Edit / Create 的 Card footer
 * （對齊 `#/products/edit/:id` 的版型），見 `Edit/index.tsx` 的 `footerButtons`。
 */
const BasicTabComponent = ({ currentSlug }: TBasicTabProps) => {
	const { SITE_URL } = useEnv()

	/*
	 * 「獨立頁面」才把 slug 呈現成永久連結（網址 addon + 複製鈕）。
	 *
	 * 用 `!== SHORTCODE` 而非 `=== PAGE` 判斷：`useWatch` 在 Edit 頁首次 render
	 * （`setFieldsValue` 之前）會回 undefined，此時應沿用 `initialValues` 的 PAGE，
	 * 否則欄位會先閃一次 Slug 版再跳成永久連結版。
	 */
	const mode = Form.useWatch<string | undefined>('mode')
	const isShortcode = PROFIT_SHOP_MODE.SHORTCODE === mode

	// 只有「獨立頁面」模式需要這份清單，Shortcode 模式不發查詢
	const { templates, isLoading: isLoadingTemplates } = useThemeTemplates(
		PROFIT_SHOP_MODE.SHORTCODE !== mode
	)
	const themeTemplateOptions = useMemo(
		() => templates.map((t) => ({ value: t.value, label: t.label })),
		[templates]
	)

	return (
		<>
			<Item
				name="title"
				label="賣場名稱"
				rules={[
					{ required: true, message: '請輸入賣場名稱' },
					{ max: 200, message: '長度不可超過 200 字元' },
				]}
			>
				<Input placeholder="例：夏季限定夥伴賣場" />
			</Item>

			<Item
				name="slug"
				label={isShortcode ? 'Slug（網址識別）' : '永久連結'}
				rules={[
					{
						required: true,
						message: isShortcode ? '請輸入 slug' : '請輸入永久連結',
					},
					{
						pattern: /^[a-z0-9_-]+$/,
						message: '僅允許小寫英文、數字、連字號（-）與底線（_）',
					},
				]}
				extra={
					isShortcode ? (
						<>
							{CONFLICT_HINT}
							<br />
							{/*
							 * 據實告知，不能只是把永久連結藏起來：後端 ProfitShopRenderer 只檢查
							 * slug 與 status、**完全不看 mode**，所以 Shortcode 模式的賣場一樣
							 * 開得起來、也會被搜尋引擎索引。UI 若暗示「沒有公開網址」會誤導站長。
							 */}
							Shortcode 模式仍會保留公開網址 {SITE_URL}/{PROFIT_SHOP_URL_PREFIX}
							/{currentSlug ? `${currentSlug}/` : '{slug}/'}
							，上架後任何人都可直接開啟。
						</>
					) : (
						CONFLICT_HINT
					)
				}
			>
				<SlugInput currentSlug={currentSlug} showPermalink={!isShortcode} />
			</Item>

			<Item
				name="mode"
				label="呈現模式"
				rules={[{ required: true, message: '請選擇呈現模式' }]}
			>
				<Radio.Group>
					<Radio value={PROFIT_SHOP_MODE.PAGE}>獨立頁面</Radio>
					<Radio value={PROFIT_SHOP_MODE.SHORTCODE}>Shortcode</Radio>
				</Radio.Group>
			</Item>

			{/*
			 * 頁面範本只在「獨立頁面」模式下有意義：Shortcode 模式的賣場是被嵌進
			 * 宿主頁面的，版型由那個頁面自己決定，這裡設什麼都不會生效。
			 */}
			{!isShortcode ? (
				<Item
					name={['settings', 'pageTemplate']}
					label="頁面範本"
					extra="選擇佈景主題的頁面 / 文章範本包住賣場內容；用頁面建構器（如 Elementor）排版時需選對應範本。留空則使用內建版型。"
				>
					<Select
						allowClear
						loading={isLoadingTemplates}
						placeholder="使用內建版型"
						options={themeTemplateOptions}
					/>
				</Item>
			) : null}
		</>
	)
}

export const BasicTab = memo(BasicTabComponent)
