import type { TImage } from 'antd-toolkit/wp'

import type { TPartnerFormValues } from '@/pages/admin/ProfitPartner/types'

/**
 * 後端欄位名 → PartnerForm 表單欄位名的對映
 *
 * 兩者大多同名，唯一的落差是圖片：後端收 `image_id`（單一 ID），
 * 表單沿用商品圖片 `Gallery` 的 `images`（`TImage[]`）。
 * 後端回傳 fieldErrors 時若不轉換，`form.setFields` 會把錯誤掛到不存在的欄位上，
 * 使用者只會看到 toast、看不到欄位紅字。
 */
const BACKEND_TO_FORM_FIELD: Record<string, keyof TPartnerFormValues> = {
	image_id: 'images',
}

/**
 * 將後端 fieldErrors 的欄位名轉為表單欄位名
 *
 * @param {string} name 後端欄位名
 * @return {keyof TPartnerFormValues} 對應的表單欄位名
 */
export const toPartnerFormFieldName = (
	name: string
): keyof TPartnerFormValues =>
	BACKEND_TO_FORM_FIELD[name] ?? (name as keyof TPartnerFormValues)

/**
 * 將 Gallery 的 `TImage[]` 降維成後端要的單一 `image_id`
 *
 * 夥伴圖片限一張，取第一筆即可。`TImage.id` 是**字串**（媒體庫慣例），
 * 轉不出正整數時一律回 null（= 移除圖片），避免把 NaN 送進 payload。
 *
 * @param {TImage[]|undefined} images 表單中的圖片陣列
 * @return {number|null} attachment ID；無圖或格式不符回 null
 */
export const toImageId = (images?: TImage[]): number | null => {
	const rawId = images?.[0]?.id
	if (undefined === rawId || null === rawId) return null

	const id = Number(rawId)
	return Number.isFinite(id) && id > 0 ? id : null
}
