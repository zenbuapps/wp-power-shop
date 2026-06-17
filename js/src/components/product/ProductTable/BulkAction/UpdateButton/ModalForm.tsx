import {
	useCustomMutation,
	useApiUrl,
	useInvalidate,
	useNotification,
} from '@refinedev/core'
import { ModalProps, Modal, Form, Skeleton } from 'antd'
import { useAtomValue } from 'jotai'
import { isEqual as _isEqual } from 'lodash-es'
import { useEffect, useMemo, useState } from 'react'

import { ProductEditTable } from '@/components/product'
import {
	TFormValues,
	ZFormValues,
} from '@/components/product/ProductEditTable/types'
import { productsToFields } from '@/components/product/ProductEditTable/utils'
import { selectedProductsAtom } from '@/components/product/ProductTable/atom'
import {
	buildBatchPayload,
	TWcBatchUpdateItem,
} from '@/components/product/ProductTable/BulkAction/UpdateButton/utils'
import { TProductRecord } from '@/components/product/types'

/**
 * 批量修改商品的 Modal 表單
 *
 * 透過 ProductEditTable 逐列編輯選取商品（簡單商品與變體皆可），
 * 送出時依商品類型拆分為 WooCommerce 原生 batch 請求：
 * - 簡單商品：合併為一個 `products/batch`。
 * - 變體：依母商品分組，各自 `products/{parent_id}/variations/batch`。
 *
 * 效能原則：選取純簡單商品時，正好只發一個 request。
 *
 * @param {Object}     props            - 組件屬性
 * @param {ModalProps} props.modalProps - Refine useModal 提供的 Modal props
 * @param {() => void} props.close      - 關閉 Modal 的函式
 * @return {JSX.Element} 批量修改 Modal
 */
const ModalForm = ({
	modalProps,
	close,
}: {
	modalProps: ModalProps
	close: () => void
}) => {
	// 使用 Refine notification 機制（App1.tsx 已配置 notificationProvider），
	// 與「快速修改」按鈕及各 useForm 一致，避免依賴 antd <App> 祖先導致通知 silent no-op
	const { open } = useNotification()
	const products = useAtomValue(selectedProductsAtom)
	const ids = products.map((product) => product.id)
	const [form] = Form.useForm<TFormValues>()
	const [showTable, setShowTable] = useState(false)

	// 虛擬欄位，因為 Table 組件使用虛擬列表，只會 render 部分的欄位，如果用 form.getFieldsValue() 會抓不到所有欄位值，因此使用這個欄位紀錄變化值
	const [virtualFields, setVirtualFields] = useState<TProductRecord[]>([])

	// 多請求協調，故以獨立狀態控制送出中（confirmLoading）
	const [isSubmitting, setIsSubmitting] = useState(false)

	// 用來判斷是否可以按下修改按鈕：有選取商品，且使用者已實際變更表格資料才允許送出
	const canUpdate = useMemo(
		() => !!ids.length && !_isEqual(products, virtualFields),
		[ids.length, products, virtualFields]
	)

	const apiUrl = useApiUrl('wc-rest')
	const invalidate = useInvalidate()
	const { mutateAsync } = useCustomMutation()

	/**
	 * 送出單一 batch 請求（簡單商品或某一母商品的變體）
	 *
	 * @param {string}               url    - WooCommerce batch endpoint 完整網址
	 * @param {TWcBatchUpdateItem[]} update - 該批次的逐筆 update payload
	 * @return {Promise<unknown>} mutateAsync 的回傳
	 */
	const submitBatch = (url: string, update: TWcBatchUpdateItem[]) =>
		mutateAsync({
			url,
			method: 'post',
			values: { update },
			dataProviderName: 'wc-rest',
		})

	const handleUpdate = async () => {
		// 1. 取得逐筆表單值（productsToFields 會把變體展開成獨立 id）
		const fields = productsToFields(virtualFields, 'submit')
		const parsed = ZFormValues.array().safeParse(Object.values(fields))

		// 2. 驗證失敗就擋下並提示
		if (!parsed.success) {
			open?.({
				type: 'error',
				message: '商品資料格式有誤，請檢查後再送出',
				key: 'bulk-update-products',
			})
			return
		}

		// 3. 依商品類型拆分為 batch payload
		const { simpleUpdates, variationGroups } = buildBatchPayload(parsed.data)

		const variationParentIds = Object.keys(variationGroups)
		if (!simpleUpdates.length && !variationParentIds.length) {
			// Refine notification 無 warning type，改用 error
			open?.({
				type: 'error',
				message: '沒有可更新的商品',
				key: 'bulk-update-products',
			})
			return
		}

		setIsSubmitting(true)
		try {
			const requests: Promise<unknown>[] = []

			// 簡單商品：合併為一個 products/batch
			if (simpleUpdates.length) {
				requests.push(submitBatch(`${apiUrl}/products/batch`, simpleUpdates))
			}

			// 變體：依母商品分組，各自打 products/{parent_id}/variations/batch
			variationParentIds.forEach((parentId) => {
				requests.push(
					submitBatch(
						`${apiUrl}/products/${parentId}/variations/batch`,
						variationGroups[parentId]
					)
				)
			})

			await Promise.all(requests)

			open?.({
				type: 'success',
				message: `商品 ${ids.map((id) => `#${id}`).join(', ')} 已修改成功`,
				key: 'bulk-update-products',
			})

			// 重新整理商品列表，讓表格反映最新資料
			invalidate({
				resource: 'products',
				invalidates: ['list'],
			})

			close()
		} catch (error) {
			open?.({
				type: 'error',
				message: '批量修改失敗，請稍後再試',
				key: 'bulk-update-products',
			})
			// eslint-disable-next-line no-console
			console.error('批量修改失敗', error)
		} finally {
			setIsSubmitting(false)
		}
	}

	useEffect(() => {
		const delay = setTimeout(() => {
			setShowTable(!!modalProps.open)
			setVirtualFields(products)
		}, 500)
		return () => clearTimeout(delay)
	}, [modalProps.open])

	return (
		<Modal
			{...modalProps}
			width="100%"
			title={`批量修改商品 ${ids.map((id) => `#${id}`).join(', ')}`}
			centered
			okText="批量修改"
			cancelText="取消"
			onOk={handleUpdate}
			okButtonProps={{ disabled: !canUpdate }}
			confirmLoading={isSubmitting}
		>
			{!showTable && <Skeleton active />}
			{showTable && (
				<ProductEditTable
					form={form}
					virtualFields={virtualFields}
					setVirtualFields={setVirtualFields}
				/>
			)}
		</Modal>
	)
}

export default ModalForm
