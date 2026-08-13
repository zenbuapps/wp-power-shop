import {
	RadarChartOutlined,
	LineChartOutlined,
	ProductOutlined,
	UserOutlined,
	MailOutlined,
	TagsOutlined,
} from '@ant-design/icons'
import { ResourceProps } from '@refinedev/core'
import { Tag } from 'antd'
import { BsBox2 } from 'react-icons/bs'
import { FaPhotoVideo } from 'react-icons/fa'
import { LiaClipboardListSolid } from 'react-icons/lia'

export const resources: ResourceProps[] = [
	{
		name: 'dashboard',
		list: '/dashboard',
		meta: {
			label: '總覽',
			icon: <RadarChartOutlined />,
		},
	},
	{
		name: 'order-management',
		meta: {
			label: '訂單',
			icon: <LiaClipboardListSolid />,
		},
	},
	{
		parentName: 'order-management',
		name: 'orders',
		list: '/orders',
		edit: '/orders/edit/:id',
		meta: {
			label: '訂單管理',
		},
	},

	// {
	// 	parentName: 'order-management',
	// 	name: 'subscriptions',
	// 	list: '/subscriptions',
	// 	edit: '/subscriptions/edit/:id',
	// 	meta: {
	// 		label: '訂閱管理',
	// 	},
	// },

	{
		name: 'product-management',
		meta: {
			label: '商品',
			icon: <ProductOutlined />,
		},
	},
	{
		parentName: 'product-management',
		name: 'products',
		list: '/products',
		edit: '/products/edit/:id',
		meta: {
			label: '商品列表',
		},
	},
	{
		parentName: 'product-management',
		name: 'taxonomies',
		list: '/products/taxonomies',
		meta: {
			label: '商品分類',
		},
	},
	{
		parentName: 'product-management',
		name: 'attributes',
		list: '/products/attributes',
		meta: {
			label: '商品規格',
		},
	},
	{
		name: 'users',
		list: '/users',
		edit: '/users/edit/:id',
		meta: {
			label: '顧客',
			icon: <UserOutlined />,
		},
	},
	{
		name: 'marketing',
		meta: {
			label: '行銷',
			icon: <TagsOutlined />,
		},
	},

	// {
	// 	parentName: 'marketing',
	// 	name: 'coupons',
	// 	list: '/marketing/coupons',
	// 	edit: '/marketing/coupons/edit/:id',
	// 	meta: {
	// 		label: '優惠碼',
	// 	},
	// },
	/*
	 * 「一頁賣場」（`one-shop`）已於 2026-08-13 移除。
	 *
	 * 那頁是一個雙入口導覽頁：一邊介紹新版 Profit Shop、一邊連到舊版 wp-admin。
	 * 但「介紹新版」對已經站在後台選單裡的站長沒有價值（分潤賣場就在隔壁），
	 * 真正有用的只有「舊版管理介面在哪」與「為什麼該遷移」——兩者都已併進
	 * 「遷入舊版」（`profit-migration`）頁面上方的說明卡片，元件檔一併刪除。
	 */

	/*
	 * 以下四個分潤 resource 刻意**不設 `meta.icon`**。
	 *
	 * 本檔的慣例是「icon 只掛在頂層 resource」——`dashboard` / `order-management` /
	 * `product-management` / `users` / `marketing` / `analytics` / `wp-media-library`
	 * 有 icon，而所有帶 `parentName` 的子項（`orders` / `products` / `taxonomies` /
	 * `attributes`）一律沒有。這四個原本是唯一的例外，
	 * 造成「行銷」展開後上半沒 icon、下半有 icon 的視覺斷層。
	 */
	{
		parentName: 'marketing',
		name: 'profit-shop',
		list: '/profit-shop',
		create: '/profit-shop/create',
		edit: '/profit-shop/edit/:id',
		meta: {
			label: '分潤賣場',
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-partner',
		list: '/profit-partner',
		create: '/profit-partner/create',
		edit: '/profit-partner/edit/:id',
		meta: {
			label: '分潤夥伴',
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-settlement',
		list: '/profit-settlements',
		meta: {
			label: '分潤結算',
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-migration',
		list: '/profit-migration',
		meta: {
			label: '遷入舊版',
		},
	},
	{
		name: 'analytics',
		list: '/analytics',
		meta: {
			label: '數據報表',
			icon: <LineChartOutlined />,
		},
	},

	// {
	// 	name: 'emails',
	// 	list: '/emails',
	// 	edit: '/emails/edit/:id',
	// 	meta: {
	// 		label: '通知信',
	// 		icon: <MailOutlined />,
	// 	},
	// },
	// {
	// 	name: 'settings',
	// 	list: '/settings',
	// 	meta: {
	// 		label: '設定',
	// 		icon: <SettingOutlined />,
	// 	},
	// },
	// {
	// 	name: 'media-library',
	// 	list: '/media-library',
	// 	meta: {
	// 		label: 'Bunny 媒體庫',
	// 		icon: <FaPhotoVideo />,
	// 	},
	// },
	{
		name: 'wp-media-library',
		list: '/wp-media-library',
		meta: {
			label: '媒體庫',
			icon: <FaPhotoVideo />,
		},
	},

	// {
	// 	name: 'shortcodes',
	// 	list: '/shortcodes',
	// 	meta: {
	// 		label: '短代碼',
	// 		icon: <CodeOutlined />,
	// 	},
	// },
]
