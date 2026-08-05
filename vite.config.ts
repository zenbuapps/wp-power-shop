import react from '@vitejs/plugin-react'
import tsconfigPaths from 'vite-tsconfig-paths'
import alias from '@rollup/plugin-alias'
import path from 'path'
import { defineConfig } from 'vite'

// import liveReload from 'vite-plugin-live-reload'

import { v4wp } from '@kucrut/vite-for-wp'

export default defineConfig({
	server: {
		port: 5178,
		cors: {
			origin: '*',
		},
	},
	plugins: [
		alias(),
		react(),
		tsconfigPaths(),

		// liveReload(__dirname + '/**/*.php'), // Optional, if you want to reload page on php changed

		v4wp({
			// Multi-entry：admin SPA 與 partner self-service portal 共用同一個 build pipeline。
			// 使用 array 形式可保持 manifest key 為原始 entry path（既有 PHP enqueue 不需改動）。
			//
			// ⚠ 第三個 entry（js/src/profit-shop-front/main.tsx）已於 Phase 14 移除：
			// 賣場前台改為 PHP + daisyUI + 手寫 vanilla JS
			// （inc/assets/js/profit-shop-front.js，不進 build pipeline）。
			//
			// ⚠ 本專案**不再產出任何自己的 CSS**：Tailwind / daisyUI 一律由 powerhouse
			// 的 admin.min.css / front.min.css 提供（其 tailwind.config.cjs 的 content
			// 已含 `../power-*/js/src/**` 與 `../power-*/inc/**`）。因此本專案已移除
			// tailwind.config.cjs / postcss.config.cjs——改動 class 之後要到 powerhouse
			// 重跑 `pnpm build-css:admin` / `build-css:front` 才會產出。
			input: [
				'js/src/main.tsx',
				'js/src/partner-portal/main.tsx',
			],
			outDir: 'js/dist',
		}),
	],

	// build: {
	// 	rollupOptions: {
	// 		output: {
	// 			// 修改入口檔案名稱
	// 			entryFileNames: 'index.js',

	// 			// 修改代碼分割後的檔案名稱
	// 			chunkFileNames: '[name]-[hash].js',

	// 			// 修改資源檔案名稱
	// 			assetFileNames: '[name]-[hash].[ext]',
	// 		},
	// 	},
	// },
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'js/src'),
			dayjs: 'dayjs',
		},
	},
	optimizeDeps: {
		include: ['dayjs'],
	},
})
