import { Alert, Form, Input, Radio, Select } from 'antd'
import { useEnv } from 'antd-toolkit'
import { memo, useMemo, type ReactNode } from 'react'

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

/** 可整份換掉賣場前台 template 的 hook 名（`ProfitShopRenderer::resolve_template_path()`） */
const TEMPLATE_FILTER_HOOK = 'power_shop_profit_shop_template'

/** 有範本可選時的欄位說明 */
const TEMPLATE_EXTRA_DEFAULT =
	'選擇佈景主題的頁面 / 文章範本包住賣場內容；用頁面建構器（如 Elementor）排版時需選對應範本。留空則使用內建版型。'

/**
 * 清單為空時的欄位說明
 *
 * 清單為空時**必須**換掉 `TEMPLATE_EXTRA_DEFAULT`：那句話在教站長「用頁面建構器
 * 排版時需選對應範本」，而此時一個都選不到，照著做只會白繞一圈。
 */
const TEMPLATE_EXTRA_WHEN_NONE = '賣場會使用內建版型。'

type TTemplateNotice = {
	type: 'info' | 'warning'
	message: string
	description: ReactNode
}

/*
 * ⚠️ 以下說明文字一律寫成**字串常數**，不要直接當 JSX 文字節點放進 `<>…</>`。
 *
 * JSX 會把「文字中間的換行」轉成一個空格（用本專案的 TypeScript 5 實測
 * `ts.transpileModule` 確認：源碼 `註冊的是沒有\n\t\t實體檔案` 會編成
 * `"註冊的是沒有 實體檔案"`）。中文句子沒有詞間空格，prettier 又會依 printWidth
 * 自動換行，於是句子中間會冒出一個誰都沒寫過的空格，而且**只有在正式畫面上看得到**。
 * 字串常數不受 prettier 重排影響，是唯一穩定的寫法。
 */

/**
 * 清單為空的原因說明（「沒有範本」與「選項失效」兩則提示共用）
 *
 * 只描述 `ThemeTemplateWhitelist::allowed()` 實際做的事：先取佈景主題登記的
 * 頁面 / 文章範本，再用 `locate_template()` 濾掉找不到實體檔案的項目。
 *
 * 「選了也載不動」不是推測：`ProfitShopRenderer::resolve_theme_template()` 委派
 * `ThemeTemplateWhitelist::resolve()`，解不出實體路徑時回空字串 ⇒ 退回內建版型。
 */
const WHY_EMPTY =
	'清單只列出佈景主題實際有對應檔案的範本。頁面建構器（如 Elementor）註冊的是沒有實體檔案的虛擬範本，選了也載不動、畫面與沒選一樣，因此不列出。'

/** 載入失敗：重點在「這不等於沒有範本」，否則站長會跑去查佈景主題 */
const ERROR_CAUSE =
	'這與「沒有可用範本」是兩回事——目前無法確認佈景主題提供了哪些範本，請重新整理頁面再試。'

/** 載入失敗不影響已存的值：`settings.pageTemplate` 由表單帶回，不受清單查詢左右 */
const ERROR_IMPACT =
	'已儲存的範本設定不會因為載入失敗而被更動，賣場前台也照常顯示。'

/** 選項失效：先講清楚現況（已 fallback），再講要做什麼 */
const STALE_CAUSE =
	'這個範本檔案在目前的佈景主題裡找不到（常見於換過佈景主題之後）。賣場前台已自動改用內建版型，但這個設定不會自己清掉。'

/** 選項失效 + 還有其他範本可選 */
const STALE_ACTION = '請清空這個欄位，或改選清單中其他範本。'

/** 選項失效 + 一個範本都沒有 */
const STALE_ACTION_WHEN_NONE =
	'目前的佈景主題也沒有其他可選範本，請清空這個欄位。'

/** 沒有範本可選時的安撫句：避免站長以為功能壞了 */
const EMPTY_IMPACT = '賣場會使用內建版型，功能與外觀都不受影響。'

/** 不靠佈景主題範本、改由程式換掉整份賣場 template 的作法 */
const FILTER_HINT = (
	<>
		{'若需要自訂版型，可由佈景主題或外掛掛上 '}
		<code>{TEMPLATE_FILTER_HOOK}</code>
		{' filter，整份換掉賣場前台的 template。'}
	</>
)

/**
 * 解析「頁面範本」欄位要顯示哪一則說明
 *
 * 🔴 四種狀態的**成因與處置各不相同，不可合併**——它們在畫面上的共同症狀都是
 * 「下拉是空的」，混為一談等於把三件事說成同一件：
 *
 * | 狀態 | 成因 | 站長該做什麼 |
 * |---|---|---|
 * | `isError` | 清單載入失敗，**還不知道**有哪些範本 | 重新整理再試 |
 * | 清單為空 | 佈景主題**確實沒有**可用範本 | 不用做什麼，走內建版型 |
 * | 選項失效 | 已存的值不在清單內（例如換過佈景主題） | 清掉它，或換一個 |
 * | 正常 | — | 不顯示提示 |
 *
 * 載入中刻意**不出提示**：此時清單為空只是還沒回來，出「沒有範本」是錯的，
 * 出「載入中」則與 Select 自己的 loading 轉圈重複。
 *
 * @param isError   清單載入失敗
 * @param isLoading 清單載入中
 * @param isStale   已儲存的範本不在清單內
 * @param isEmpty   清單確定為空（已載入成功）
 */
const resolveTemplateNotice = ({
	isError,
	isLoading,
	isStale,
	isEmpty,
}: {
	isError: boolean
	isLoading: boolean
	isStale: boolean
	isEmpty: boolean
}): TTemplateNotice | null => {
	if (isLoading) return null

	if (isError) {
		return {
			type: 'warning',
			message: '無法載入佈景主題範本清單',
			description: (
				<>
					{ERROR_CAUSE}
					<br />
					{ERROR_IMPACT}
				</>
			),
		}
	}

	if (isStale) {
		return {
			type: 'warning',
			message: '目前選擇的範本已無法使用',
			description: (
				<>
					{STALE_CAUSE}
					<br />
					{isEmpty ? `${STALE_ACTION_WHEN_NONE}${WHY_EMPTY}` : STALE_ACTION}
				</>
			),
		}
	}

	if (isEmpty) {
		return {
			type: 'info',
			message: '目前的佈景主題沒有提供可用的頁面範本',
			description: (
				<>
					{WHY_EMPTY}
					<br />
					{EMPTY_IMPACT}
					{FILTER_HINT}
				</>
			),
		}
	}

	return null
}

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
	const {
		templates,
		isLoading: isLoadingTemplates,
		isError: isTemplatesError,
	} = useThemeTemplates(PROFIT_SHOP_MODE.SHORTCODE !== mode)

	const pageTemplate = Form.useWatch<string | undefined>([
		'settings',
		'pageTemplate',
	])
	const savedTemplate = 'string' === typeof pageTemplate ? pageTemplate : ''

	/*
	 * 「清單確定是空的」與「清單還沒回來 / 沒回來成功」必須分開。
	 * 少了這個旗標，載入中的那一瞬間會被當成「這個佈景主題沒有範本」，
	 * 提示閃一下再消失——講的還是錯的話。
	 */
	const isTemplatesResolved = !isLoadingTemplates && !isTemplatesError
	const isTemplatesEmpty = isTemplatesResolved && 0 === templates.length
	const isTemplateStale =
		isTemplatesResolved &&
		'' !== savedTemplate &&
		!templates.some((t) => t.value === savedTemplate)

	/*
	 * 已儲存但不在清單內的值，**仍要自己補成一個選項**。
	 *
	 * 不補的話那個值在下拉裡沒有對應項目，畫面呈現就落到 antd 的預設處理上；
	 * 自己補成選項則是我們自己決定要顯示什麼，行為與 antd 版本無關。順帶讓站長
	 * 看得到「他現在存的到底是哪個檔名」，才有辦法判斷要不要清掉。
	 *
	 * 標記文字只在**確定載完**之後才加：載入中 / 載入失敗時我們還不知道它有沒有效，
	 * 先原樣列出，別先給一個之後要收回的結論。
	 */
	const themeTemplateOptions = useMemo(() => {
		const options = templates.map((t) => ({ value: t.value, label: t.label }))

		if (
			'' === savedTemplate ||
			options.some((o) => o.value === savedTemplate)
		) {
			return options
		}

		return [
			...options,
			{
				value: savedTemplate,
				label: isTemplatesResolved
					? `${savedTemplate}（找不到此範本）`
					: savedTemplate,
			},
		]
	}, [templates, savedTemplate, isTemplatesResolved])

	const templateNotice = resolveTemplateNotice({
		isError: isTemplatesError,
		isLoading: isLoadingTemplates,
		isStale: isTemplateStale,
		isEmpty: isTemplatesEmpty,
	})

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
				<>
					<Item
						name={['settings', 'pageTemplate']}
						label="頁面範本"
						extra={
							isTemplatesEmpty
								? TEMPLATE_EXTRA_WHEN_NONE
								: TEMPLATE_EXTRA_DEFAULT
						}
					>
						{/*
						 * `disabled` 只綁「一個選項都沒有」——那時展開下拉只會看到空面板，
						 * 而下方的提示已經把原因講清楚了。
						 *
						 * 反過來說，只要清單裡有東西（含上面補進去的失效值），就**不能**停用：
						 * 失效值得讓站長點得進去才清得掉，載入失敗時把欄位鎖死更是連
						 * 「我想清空」都做不到。
						 */}
						<Select
							allowClear
							loading={isLoadingTemplates}
							disabled={0 === themeTemplateOptions.length}
							placeholder={
								isTemplatesEmpty ? '無可用範本（使用內建版型）' : '使用內建版型'
							}
							options={themeTemplateOptions}
						/>
					</Item>

					{templateNotice ? (
						<Alert
							type={templateNotice.type}
							showIcon
							className="mb-6"
							message={templateNotice.message}
							description={templateNotice.description}
						/>
					) : null}
				</>
			) : null}
		</>
	)
}

export const BasicTab = memo(BasicTabComponent)
