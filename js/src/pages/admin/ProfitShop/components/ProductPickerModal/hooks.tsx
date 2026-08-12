import { Button } from 'antd'
import { useSimpleModal, type TSimpleModalProps } from 'antd-toolkit'
import { useCallback, useMemo, useState } from 'react'

import { TProductRecord } from '@/components/product/types'

/** 單次批次加入上限（單一真相在 `./utils`，此處僅 re-export 給既有 import 用） */
export { MAX_BATCH_SIZE } from './utils'

type TUseProductPickerModalArgs = {
	existingProductIds: number[]
	onConfirm: (records: TProductRecord[]) => void
}

/**
 * 商品選擇 Modal 的狀態與 footer
 *
 * ⚠️ 選取狀態刻意是**元件區域** `useState`，**不接**
 * `components/product/ProductTable/atom.tsx` 的全域選取 atom ——
 * 那是 module-level atom，且 `ProductTable` mount 時會把它清空，
 * 接了會與 `#/products` 的批次選取雙向污染（風險 F-4）。
 *
 * （此處刻意不寫出該 atom 的識別字，以免 P3-9 的隔離 grep 閘門誤報。）
 *
 * caller 請把 `existingProductIds` 用 `useMemo` 穩定住，否則每次 render 都會重建 Set。
 */
export const useProductPickerModal = ({
	existingProductIds,
	onConfirm,
}: TUseProductPickerModalArgs) => {
	const { show, close, modalProps } = useSimpleModal()
	const [selectedRecords, setSelectedRecords] = useState<TProductRecord[]>([])

	const existingIds = useMemo(
		() => new Set(existingProductIds),
		[existingProductIds]
	)

	const handleClose = useCallback(() => {
		close()
		setSelectedRecords([])
	}, [close])

	const handleConfirm = useCallback(() => {
		onConfirm(selectedRecords)
		close()
		setSelectedRecords([])
	}, [onConfirm, selectedRecords, close])

	const selectedCount = selectedRecords.length

	const formattedModalProps: TSimpleModalProps = {
		...modalProps,
		title: '選擇商品',

		// children 在關閉時完全不掛載 → Modal 沒開時不會空跑 useTable 查詢
		destroyOnHidden: true,
		footer: (
			<>
				<Button
					type="primary"
					onClick={handleConfirm}
					disabled={0 === selectedCount}
				>
					加入賣場 ({selectedCount})
				</Button>
				<span className="text-sm text-gray-500">已選 {selectedCount} 筆</span>
			</>
		),

		// ⚠️ 必須放在 `...modalProps` 之後：`useSimpleModal` 內部會把 `onCancel` 設成
		// 它自己的 `close`，放在 spread 前面會被蓋掉，導致關閉時不 reset 選取集合。
		onCancel: handleClose,
	}

	return {
		show,
		close: handleClose,
		modalProps: formattedModalProps,
		selectedRecords,
		setSelectedRecords,
		existingIds,
	}
}

export type TProductPickerModalState = ReturnType<typeof useProductPickerModal>
