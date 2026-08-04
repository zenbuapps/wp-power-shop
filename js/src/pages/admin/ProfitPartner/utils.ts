import type { TImage } from 'antd-toolkit/wp'

import {
	GENERATED_PASSWORD_LENGTH,
	type TPartnerFormValues,
} from '@/pages/admin/ProfitPartner/types'

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

/**
 * 密碼字元集
 *
 * 刻意排除 `0O1lI` 這幾個容易看錯的字元——站長常是用 LINE / 電話把密碼唸給夥伴聽，
 * 一個 `l` 與 `1` 的混淆就是一次登入失敗加一通客訴。
 *
 * 也不含符號：符號在跨平台複製貼上（尤其通訊軟體的自動格式化）時最容易出事，
 * 而長度 12 的英數組合熵已足夠（59^12 ≈ 2^70）。
 */
const PASSWORD_CHARS =
	'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'

/**
 * 產生隨機密碼（純前端，不打 API）
 *
 * 保證至少含 1 個英文字母與 1 個數字，與後端 `PartnerPassword` VO 的強度規則對齊——
 * 否則站長按了「產生」卻可能拿到純數字密碼，之後夥伴自助改密碼時反而被 VO 擋下。
 *
 * 用 `crypto.getRandomValues` 而非 `Math.random()`：後者的輸出可預測，
 * 拿來產生密碼等於把「隨機」變成裝飾。此 API 在所有目標瀏覽器與 WP 後台（https / localhost）
 * 均可用，因此不做 fallback——若真的取不到，寧可讓它拋錯被發現，
 * 也不要靜默退回一個看起來隨機、實際上可預測的密碼。
 *
 * @return {string} 產生的密碼
 */
export const generatePassword = (): string => {
	const buf = new Uint32Array(GENERATED_PASSWORD_LENGTH)
	crypto.getRandomValues(buf)

	const chars = Array.from(
		buf,
		(n) => PASSWORD_CHARS[n % PASSWORD_CHARS.length] as string
	)

	// 補強：確保英文與數字都至少各出現一次。改寫固定位置（0 與 1）而非重抽，
	// 是為了讓函式必定在單次呼叫內結束，不會有「極低機率重抽很多次」的不確定性。
	const letters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'
	const digits = '23456789'

	if (!chars.some((c) => letters.includes(c))) {
		chars[0] = letters[buf[0]! % letters.length] as string
	}
	if (!chars.some((c) => digits.includes(c))) {
		chars[1] = digits[buf[1]! % digits.length] as string
	}

	return chars.join('')
}
