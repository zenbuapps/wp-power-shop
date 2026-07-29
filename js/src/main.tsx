import { StyleProvider } from '@ant-design/cssinjs'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { App as AntdApp, ConfigProvider } from 'antd'
import { EnvProvider } from 'antd-toolkit'
import { BunnyProvider } from 'antd-toolkit/refine'
import React from 'react'
import ReactDOM from 'react-dom/client'

import { APP1_SELECTOR, env } from '@/utils'

const App1 = React.lazy(() => import('./App1'))

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: 0,
			staleTime: 1000 * 60 * 10,
			cacheTime: 1000 * 60 * 10,
		},
	},
})

const app1Nodes = document.querySelectorAll(APP1_SELECTOR)

const mapping = [
	{
		els: app1Nodes,
		App: App1,
	},
]

const { BUNNY_LIBRARY_ID, BUNNY_CDN_HOSTNAME, BUNNY_STREAM_API_KEY } = env

document.addEventListener('DOMContentLoaded', () => {
	mapping.forEach(({ els, App }) => {
		if (!!els) {
			els.forEach((el) => {
				ReactDOM.createRoot(el).render(
					<React.StrictMode>
						<QueryClientProvider client={queryClient}>
							<StyleProvider hashPriority="low">
								<EnvProvider env={env}>
									<BunnyProvider
										bunny_library_id={BUNNY_LIBRARY_ID}
										bunny_cdn_hostname={BUNNY_CDN_HOSTNAME}
										bunny_stream_api_key={BUNNY_STREAM_API_KEY}
									>
										<ConfigProvider
											theme={{
												token: {
													colorPrimary: '#1677ff',
													borderRadius: 6,
												},
												components: {
													Segmented: {
														itemSelectedBg: '#1677ff',
														itemSelectedColor: '#ffffff',
													},
												},
											}}
										>
											{/*
											 * antd 的 App context —— 少了它，`App.useApp()` 會拿到
											 * 空物件，任何 `notification.*` / `message.*` 呼叫都會丟
											 * `TypeError: x.success is not a function`，並中斷該處後續流程。
											 * 全專案有 10 個檔案用 `App.useApp()`（Profit Shop 系列全部）。
											 *
											 * `component={false}` 讓它不輸出任何包裹用的 DOM 節點，
											 * 純提供 context，不影響既有版型。
											 */}
											<AntdApp component={false}>
												<App />
											</AntdApp>
										</ConfigProvider>
									</BunnyProvider>
								</EnvProvider>
							</StyleProvider>
						</QueryClientProvider>
					</React.StrictMode>
				)
			})
		}
	})
})
