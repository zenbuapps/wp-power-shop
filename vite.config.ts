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
			// Phase 4-B1.2：partner portal 入口為 mock，由 4-B1.3 react-master 接續實作主體。
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
