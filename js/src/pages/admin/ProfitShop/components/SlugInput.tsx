import { CheckCircleFilled, CloseCircleFilled } from '@ant-design/icons'
import { useApiUrl, useCustom } from '@refinedev/core'
import { Alert, Input, Spin } from 'antd'
import { debounce } from 'lodash-es'
import { memo, useEffect, useMemo, useState } from 'react'

import type { TSlugValidationOutput } from '@/pages/admin/ProfitShop/types'

type TSlugInputProps = {
	value?: string // 受 antd Form.Item 控制的目前值
	onChange?: (value: string) => void // 由 antd Form.Item 注入的 onChange
	currentSlug?: string // 跳過驗證的目前 slug（編輯模式：自己的 slug 不算衝突）
	placeholder?: string
	disabled?: boolean
}

/** Slug 格式 regex：小寫英數 + 連字號 / 底線（與後端 InvalidPartnerSlug 對齊） */
const SLUG_FORMAT = /^[a-z0-9_-]+$/

/**
 * 帶 debounce 即時驗證的 Slug 輸入框
 *
 * - 採用 Refine `useCustom` + 受控 debounced query string；
 *   query 改變時 React Query 會自動 cancel 舊 request，無須手動 abortController
 * - 5 類衝突（spec §6.11）由後端 SlugConflictDetector 計算，前端只負責呈現
 * - currentSlug 用於編輯模式：與目前 slug 相同時直接顯示「可用」不打 API
 */
const SlugInputComponent = ({
	value,
	onChange,
	currentSlug,
	placeholder = 'my-shop',
	disabled,
}: TSlugInputProps) => {
	const apiUrl = useApiUrl('power-shop')
	const [debouncedSlug, setDebouncedSlug] = useState<string>('')

	// debounce 寫入 debouncedSlug，從而觸發 useCustom
	const updateDebouncedSlug = useMemo(
		() => debounce((slug: string) => setDebouncedSlug(slug), 400),
		[]
	)

	useEffect(() => {
		updateDebouncedSlug(value ?? '')
		return () => {
			updateDebouncedSlug.cancel()
		}
	}, [value, updateDebouncedSlug])

	const isFormatValid = !!debouncedSlug && SLUG_FORMAT.test(debouncedSlug)
	const isSameAsCurrent =
		!!currentSlug && !!debouncedSlug && currentSlug === debouncedSlug
	const shouldQuery = isFormatValid && !isSameAsCurrent

	const { data, isFetching } = useCustom<{
		code: string
		data: TSlugValidationOutput
	}>({
		url: `${apiUrl}/profit-shops/validate-slug`,
		method: 'get',
		config: {
			query: { slug: debouncedSlug },
		},
		queryOptions: {
			enabled: shouldQuery,
			retry: 0,
			keepPreviousData: false,
		},
	})

	// 後端 V2Api 統一裹 {code, data}，axios → result.data 是 axios body，
	// 故 result.data.data 才是 SlugValidationOutput 本體
	const validation = data?.data?.data

	// 加 shouldQuery 守門：使用者刪光 slug / 與 currentSlug 相同 / 格式無效時，
	// 強制清空 conflicts/available，避免上一輪 React Query cache 殘留 alert
	const conflicts = shouldQuery ? (validation?.conflicts ?? []) : []
	const available = shouldQuery ? (validation?.available ?? false) : false

	// 渲染 suffix（loading / 通過 / 衝突）
	let suffix: React.ReactNode = null
	if (!debouncedSlug) {
		suffix = null
	} else if (!isFormatValid) {
		suffix = <CloseCircleFilled style={{ color: 'var(--ant-color-error)' }} />
	} else if (isSameAsCurrent) {
		suffix = <CheckCircleFilled style={{ color: 'var(--ant-color-success)' }} />
	} else if (isFetching) {
		suffix = <Spin size="small" />
	} else if (available) {
		suffix = <CheckCircleFilled style={{ color: 'var(--ant-color-success)' }} />
	} else if (conflicts.length > 0) {
		suffix = <CloseCircleFilled style={{ color: 'var(--ant-color-error)' }} />
	}

	return (
		<>
			<Input
				value={value}
				onChange={(e) => onChange?.(e.target.value)}
				placeholder={placeholder}
				disabled={disabled}
				suffix={suffix}
			/>
			{!!debouncedSlug && !isFormatValid && (
				<Alert
					className="tw-mt-2"
					type="error"
					showIcon
					message="Slug 格式錯誤"
					description="僅允許小寫英文、數字、連字號（-）與底線（_）"
				/>
			)}
			{shouldQuery && !isFetching && conflicts.length > 0 && (
				<Alert
					className="tw-mt-2"
					type="error"
					showIcon
					message={`與 ${conflicts.length} 個既有資源衝突`}
					description={
						<ul className="tw-m-0 tw-pl-4">
							{conflicts.map((c) => (
								<li
									key={`${c.conflict_kind}-${c.conflicting_slug}-${c.conflicting_id ?? 'null'}`}
								>
									{c.conflicting_label}（{c.conflict_kind}）：
									<code>{c.conflicting_slug}</code>
								</li>
							))}
						</ul>
					}
				/>
			)}
		</>
	)
}

export const SlugInput = memo(SlugInputComponent)
