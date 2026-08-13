/**
 * Profit Migration 前端型別定義
 *
 * 對齊後端 `ListImportableLegacyShops` / `ImportLegacyShop` 的實際回傳。
 *
 * ⚠️ 這份型別曾經與後端完全對不上（前端要 `legacy_id` / `importable`，後端回 `id` / `meta`），
 * 造成「遷入」按鈕永久 disabled、站長完全無法使用此功能。改動任一端時，
 * 請同步確認另一端——後端的欄位定義在
 * `ListImportableLegacyShops::describe()` 的回傳型別註解。
 *
 * Migration 流程不可逆（spec §4.7）：
 *   - legacy 一頁商店資料會「轉換」成 ProfitShop CPT，原資料保留但不在標準 UI 上呈現
 *   - 失敗時不會自動回滾，需聯絡管理員手動處理
 *   - 因此 ImportModal 強制 PartnerSelector + 文字輸入「IMPORT」二次確認
 */

/**
 * 可遷入的 legacy 一頁商店參照（profit-migration/legacy-shops 列表項）
 *
 * `importable=false` 時 `reason` 會說明原因，目前有兩種：
 *   1. 永久連結已被其他賣場／頁面／商品佔用（後端走 SlugConflictDetector 判定）
 *   2. 舊版商品資料無法轉換（例如特價高於原價，會被 PriceOverride 擋下）
 */
export type TLegacyShop = {
	legacy_id: number
	title: string
	slug: string
	importable: boolean
	reason: string | null
	/** 轉換後會被帶入新賣場的商品筆數 */
	product_count: number
}

/**
 * 匯入成功回應 payload（responses.201.data）
 *
 * 後端回的是完整 `ProfitShopOutput`（欄位名是 `id`，**不是** `shop_id`），
 * 外加一個 `skipped_product_ids`。
 *
 * `skipped_product_ids`：legacy 引用了但商品已被刪除，因此沒有被帶入新賣場的 product_id。
 * 匯入不可逆，這些被丟掉的項目**必須**在 UI 上顯性告知，不能靜默吞掉。
 */
export type TImportResult = {
	id: number
	title: string
	slug: string
	status: string
	partner_term_id: number
	rate: number
	items: unknown[]
	skipped_product_ids: number[]
}
