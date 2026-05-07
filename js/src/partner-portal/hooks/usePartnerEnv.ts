/**
 * Partner Portal 環境變數 Hook
 *
 * 解密 PHP 注入到 window.power_shop_partner_data.env 的環境資訊。
 *
 * 注意：
 * - 與 admin SPA 的 useEnv 不同，partner portal 讀取的是 `power_shop_partner_data`
 *   （由 PartnerPortalRenderer.php 的 wp_localize_script 注入）
 * - partner 不是 WP user，**不需要也沒有** wp_rest nonce
 * - 直接複用 antd-toolkit 的 simpleDecrypt（純函式，不會拉入額外 bundle）
 */

import { simpleDecrypt } from 'antd-toolkit'

/**
 * Partner Portal 解密後的環境變數結構
 *
 * 對應 PartnerPortalRenderer::render_portal() 的 $env 陣列：
 * - SITE_URL: 站台網址（不含尾斜線）
 * - API_URL:  REST API 完整 base URL，含 namespace（例：https://site.com/wp-json/power-shop）
 * - KEBAB:    Plugin kebab-case name（power-shop）
 * - SLUG:     當前 partner slug（從 URL 抓出，已由後端驗證 + sanitize）
 */
export type TPartnerEnv = {
	SITE_URL: string
	API_URL: string
	KEBAB: string
	SLUG: string
}

declare global {
	interface Window {
		power_shop_partner_data?: { env: string }
	}
}

/**
 * 純函式版本：從 window.power_shop_partner_data.env 解密讀取環境變數
 *
 * 此實作為純函式，可在 React tree 之外（例如 axios interceptor）安全呼叫。
 * 不使用 React state / context，避免違反 react-hooks rule-of-hooks。
 *
 * @return {TPartnerEnv} 解密後的 env，缺欄位以空字串 fallback
 */
export const readPartnerEnv = (): TPartnerEnv => {
	const encrypted = window?.power_shop_partner_data?.env
	const decrypted = encrypted ? simpleDecrypt(encrypted) : null

	return {
		SITE_URL: decrypted?.SITE_URL ?? '',
		API_URL: decrypted?.API_URL ?? '',
		KEBAB: decrypted?.KEBAB ?? 'power-shop',
		SLUG: decrypted?.SLUG ?? '',
	}
}

/**
 * Hook 介面：在 React 元件內取得 partner 環境變數
 *
 * 內部直接委派 readPartnerEnv()——env 在 page load 後不會變動，
 * 不需 useState / useMemo（每次呼叫都返回同樣結構但新的物件參考）。
 *
 * @return {TPartnerEnv} 解密後的 env
 */
export const usePartnerEnv = (): TPartnerEnv => readPartnerEnv()
