import { CopyOutlined } from '@ant-design/icons'
import { useGo } from '@refinedev/core'
import { App, Button, Popconfirm, Space } from 'antd'
import { memo } from 'react'

import {
	useProfitShopDuplicate,
	useProfitShopPublish,
	useProfitShopUnpublish,
} from '@/pages/admin/ProfitShop/hooks'
import {
	PROFIT_SHOP_STATUS,
	type TProfitShop,
} from '@/pages/admin/ProfitShop/types'
import { mapProfitShopException } from '@/utils/profitShopExceptionMapper'

type TShopActionsButtonsProps = {
	shop: TProfitShop
}

/**
 * 賣場頁右上方動作按鈕群（publish / unpublish / duplicate）
 *
 * - publish/unpublish 依目前 status 動態顯示其中一個
 * - duplicate 成功後 navigate 到新賣場 edit 頁
 * - 錯誤統一走 mapProfitShopException
 */
const ShopActionsButtonsComponent = ({ shop }: TShopActionsButtonsProps) => {
	const { notification } = App.useApp()
	const go = useGo()

	const publisher = useProfitShopPublish()
	const unpublisher = useProfitShopUnpublish()
	const duplicator = useProfitShopDuplicate()

	const handlePublish = async () => {
		try {
			await publisher.mutateAsync(shop.id)
			notification.success({
				message: '已上架賣場',
				description: `#${shop.id} ${shop.title}`,
			})
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '上架失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const handleUnpublish = async () => {
		try {
			await unpublisher.mutateAsync(shop.id)
			notification.success({
				message: '已下架賣場',
				description: `#${shop.id} ${shop.title}`,
			})
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '下架失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const handleDuplicate = async () => {
		try {
			const result = await duplicator.mutateAsync(shop.id)
			const newId = result?.data?.data?.id
			notification.success({
				message: '已複製賣場',
				description: newId ? `新賣場 #${newId}` : undefined,
			})
			if (newId) {
				go({ to: { resource: 'profit-shop', action: 'edit', id: newId } })
			}
		} catch (err) {
			const mapped = mapProfitShopException(err)
			notification.error({
				message: '複製失敗',
				description: mapped.toastMessage,
			})
		}
	}

	const isDraft = shop.status === PROFIT_SHOP_STATUS.DRAFT
	const isPublish = shop.status === PROFIT_SHOP_STATUS.PUBLISH

	return (
		<Space>
			{isDraft && (
				<Popconfirm
					title="確認上架賣場？"
					description="上架後夥伴將可在前台訪問此賣場"
					okText="確認上架"
					cancelText="取消"
					onConfirm={handlePublish}
				>
					<Button type="primary" loading={publisher.isLoading}>
						上架
					</Button>
				</Popconfirm>
			)}
			{isPublish && (
				<Popconfirm
					title="確認下架賣場？"
					description="下架後前台將無法訪問此賣場（不刪除資料）"
					okText="確認下架"
					okButtonProps={{ danger: true }}
					cancelText="取消"
					onConfirm={handleUnpublish}
				>
					<Button danger loading={unpublisher.isLoading}>
						下架
					</Button>
				</Popconfirm>
			)}
			<Popconfirm
				title="確認複製此賣場？"
				description="會建立一個 status=draft 的新賣場，slug 會自動加 -copy 後綴"
				okText="確認複製"
				cancelText="取消"
				onConfirm={handleDuplicate}
			>
				<Button icon={<CopyOutlined />} loading={duplicator.isLoading}>
					複製
				</Button>
			</Popconfirm>
		</Space>
	)
}

export const ShopActionsButtons = memo(ShopActionsButtonsComponent)
