import {
	RadarChartOutlined,
	LineChartOutlined,
	ProductOutlined,
	SettingOutlined,
	UserOutlined,
	MailOutlined,
	TagsOutlined,
	ShopOutlined,
	ImportOutlined,
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
	{
		parentName: 'marketing',
		name: 'one-shop',
		list: '/marketing/one-shop',

		// edit: '/one_shop/edit/:id',
		meta: {
			label: (
				<>
					一頁賣場
					<span className="bg-orange-500 text-white rounded-full px-1 ml-1 text-[0.625rem]">
						即將推出
					</span>
				</>
			),
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-shop',
		list: '/profit-shop',
		create: '/profit-shop/create',
		edit: '/profit-shop/edit/:id',
		meta: {
			label: '分潤賣場',
			icon: <ShopOutlined />,
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
			icon: <UserOutlined />,
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-migration',
		list: '/profit-migration',
		meta: {
			label: '遷入舊版',
			icon: <ImportOutlined />,
		},
	},
	{
		parentName: 'marketing',
		name: 'profit-settings',
		list: '/profit-settings',
		meta: {
			label: 'Profit 設定',
			icon: <SettingOutlined />,
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
