/**
 * 分潤賣場前台 entry point（第三個 Vite entry）
 *
 * Mount 點：`<div id="profit_shop_front_app">`（PHP 印在商品 grid 之後）
 * URL：`/profit-shop/{slug}/`
 *
 * Provider 鏈：
 *   Mount(profit_shop_front_app)
 *     └─ ConfigProvider    (antd v5 theme token，colorPrimary 來自賣場設定)
 *         └─ AntdApp        (component={false}：零 DOM 輸出，只提供 notification /
 *                            message / modal 的 context。**必須有**，否則
 *                            `App.useApp()` 回空物件，呼叫 notification 會 throw
 *                            並中斷後續程式碼——見 .claude/rules/react.rule.md §2)
 *             └─ StyleProvider hashPriority="high"  (提高 antd CSS 權重，
 *                                避免被 theme 樣式蓋掉)
 *                 └─ <App />
 *
 * ⚠ 已移除 `QueryClientProvider`（舊版 Phase 10 / 11 有）：本 bundle 現在**不做任何
 *    非同步查詢**——商品資料全部來自 PHP `wp_localize_script`，加入購物車走
 *    WooCommerce 原生表單送出（整頁重新載入），購物車與結帳由 WooCommerce 自己的
 *    頁面負責。留著一個沒有任何 query 的 QueryClientProvider 會誤導後人以為這裡
 *    還有非同步資料流。反之 `AntdApp` provider 刻意保留（見上）。
 *
 * ⚠ 未使用 `React.StrictMode`（舊版有用）：本 bundle 會對 PHP 印出的外部 DOM
 *    綁 event listener，StrictMode 在 dev 下刻意雙跑 effect，雖然已有正確 cleanup，
 *    但仍會多一輪「解綁 → 重綁」造成 debug 噪音。與 partner-portal 的 main.tsx 一致。
 */

import { StyleProvider } from '@ant-design/cssinjs'
import { App as AntdApp, ConfigProvider } from 'antd'
import { createRoot } from 'react-dom/client'

import App from './App'
import { colorPrimary } from './env'

// 本 bundle 專屬 CSS。**Tailwind directive 就寫在這個檔案裡**（而非 import 共用的
// `@/assets/styles/tailwind.css`）——原因見 styles.css 檔頭：共用檔會被 Vite 提取成
// 掛在 admin chunk 上的共用 CSS chunk，本 bundle 抓不到，所有 utility 會靜默失效。
import './styles.css'

/** mount React 到 PHP 印出的容器；找不到容器就靜默結束（非分潤賣場頁面） */
const init = (): void => {
	const mountNode = document.getElementById('profit_shop_front_app')
	if (!mountNode) return

	createRoot(mountNode).render(
		<ConfigProvider theme={{ token: { colorPrimary } }}>
			<AntdApp component={false}>
				<StyleProvider hashPriority="high">
					<App />
				</StyleProvider>
			</AntdApp>
		</ConfigProvider>
	)
}

// module script 屬 deferred，通常在 DOMContentLoaded 之前就執行完 HTML 解析；
// 但仍保留 readyState 判斷，避免任何載入時序造成 mount 點抓不到。
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init)
} else {
	init()
}
