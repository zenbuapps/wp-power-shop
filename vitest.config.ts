import path from 'path'
import { defineConfig } from 'vitest/config'

/**
 * vitest 設定 —— 僅供 `inc/assets/js/profit-shop-front.js` 的行為測試
 *
 * ⚠ 這份設定**刻意與 `vite.config.ts` 分離**，不共用 plugin 鏈：
 * 受測目標是一支不進 Vite build pipeline 的手寫 vanilla JS（IIFE、零 export），
 * 掛上 React / v4wp / tsconfigPaths 那些 plugin 對它毫無意義，只會拖慢啟動並
 * 在 plugin 版本變動時製造與測試無關的失敗。
 *
 * `environment: 'jsdom'` 是必要的：受測程式全程操作 DOM（querySelector、
 * addEventListener、dispatchEvent），沒有 DOM 就一行都跑不起來。
 */
export default defineConfig({
	// admin SPA 的純函式測試需要 `@/` alias（與 vite.config.ts 一致）。
	// 只補這一個 alias，不引入 React / v4wp plugin——受測的是不依賴 React 的純邏輯。
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'js/src'),
		},
	},
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.ts'],
		// 賣場前台 JS 是 IIFE，每個測試都要在乾淨的 document 上重新執行一次；
		// 用 forks 隔離避免不同測試檔之間殘留 module-level 狀態。
		pool: 'forks',
		reporters: ['default'],
	},
})
