/**
 * Profit Migration 共用 hooks（admin_only）
 *
 * 採與 ProfitShop / ProfitPartner 相同 pattern：
 *   - useCustom / useCustomMutation 直打點，dataProviderName: 'power-shop'
 *   - 後端統一裹 {code, data} → axios body 在 result.data → 真正資料在 result.data.data
 *
 * 安全紀律：
 *   - import 是不可逆操作；hook 只負責 mutation 本身，「二次確認 / 導頁 / notify」
 *     由呼叫者（ImportModal）處理，避免 hook 內偷偷做副作用。
 */

import { useApiUrl, useCustom, useCustomMutation } from '@refinedev/core'

import { useInvalidateProfitQueries } from '@/hooks'
import type {
	TImportResult,
	TLegacyShop,
} from '@/pages/admin/ProfitMigration/types'
import type { TWrappedResponse } from '@/pages/admin/ProfitShop/hooks'

const PROFIT_MIGRATION_RESOURCE = 'profit-migration'

/** 取得可遷入的 legacy 一頁商店列表 */
export const useLegacyShopList = () => {
	const apiUrl = useApiUrl('power-shop')
	const url = `${apiUrl}/${PROFIT_MIGRATION_RESOURCE}/legacy-shops`

	return useCustom<TWrappedResponse<TLegacyShop[]>>({
		url,
		method: 'get',
		dataProviderName: 'power-shop',
	})
}

/**
 * 匯入單一 legacy 商店（admin_only）
 *
 * onSuccess 後同時 invalidate：
 *   - profit-migration list（被遷入的不會再出現於候選）
 *   - profit-shops list（新賣場應出現於 ProfitShop 列表）
 */
export const useLegacyShopImport = () => {
	const apiUrl = useApiUrl('power-shop')
	const invalidateProfitQueries = useInvalidateProfitQueries()
	const mutation = useCustomMutation<TWrappedResponse<TImportResult>>()

	const mutateAsync = (values: {
		legacy_id: number
		partner_term_id: number
	}) =>
		mutation.mutateAsync(
			{
				url: `${apiUrl}/${PROFIT_MIGRATION_RESOURCE}/import`,
				method: 'post',
				values,
				dataProviderName: 'power-shop',
			},
			{
				onSuccess: () => {
					// 匯入會同時影響兩份清單：legacy 來源（可匯入狀態變了）與賣場列表（多了一筆）。
					void invalidateProfitQueries(PROFIT_MIGRATION_RESOURCE)
					void invalidateProfitQueries('profit-shops')
				},
			}
		)

	return { ...mutation, mutateAsync }
}
