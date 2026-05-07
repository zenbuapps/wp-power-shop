/**
 * Profit Migration 前端型別定義
 *
 * 對齊 specs/api/api.yml 的 LegacyShopRef + import endpoint。
 *
 * Migration 流程不可逆（spec §4.7）：
 *   - legacy 一頁商店資料會「轉換」成 ProfitShop CPT，原資料保留但不在標準 UI 上呈現
 *   - 失敗時不會自動回滾，需聯絡管理員手動處理
 *   - 因此 ImportModal 強制 PartnerSelector + 文字輸入「IMPORT」二次確認
 */

/**
 * 可遷入的 legacy 一頁商店參照（profit-migration/legacy-shops 列表項）
 *
 * 對應 OpenAPI LegacyShopRef。
 * importable=false 時 reason 會說明原因（例：已遷入過 / slug 衝突 / 缺必要欄位）。
 */
export type TLegacyShop = {
	legacy_id: number
	title: string
	slug: string
	importable: boolean
	reason?: string
}

/**
 * 匯入成功回應 payload（responses.201.data，後端裹 {code, data}，data 是 ProfitShopOutput）
 *
 * 此處只取「導頁需要的欄位」做最小耦合：
 *   - shop_id：新建立的 ProfitShop id（前端會 navigate 到 edit）
 *   - partner_term_id：對應 partner 的 term id（呈現提示用）
 */
export type TImportResult = {
	shop_id: number
	partner_term_id: number
}
