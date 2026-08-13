import type { TImage } from 'antd-toolkit/wp'

import {
	GENERATED_PASSWORD_LENGTH,
	type TPartnerFormValues,
	type TPartnerQueryState,
	type TProfitPartner,
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
 * 判斷「複製密碼」不可用的原因；回傳 null 代表可用
 *
 * ── 為什麼這個函式跨 `#/profit-partner` 與 `#/profit-shop` 兩頁共用 ──────
 *
 * 兩頁複製的是**同一個東西**：該分潤夥伴的報表登入明文密碼。
 * `#/profit-shop` 那一列雖然是賣場，複製的仍是 `partner.password`，
 * 停用條件（沒有明文可複製）與訊息因此逐字相同——共用才是正確的。
 *
 * ⚠️ 這**不違反** `admin-list-ui.rule.md` §1「各動作的停用條件要分開判斷、
 * 不要為了 DRY 併成一個」。那條規則防的是「條件看似相同、但差異點才是關鍵」的情況
 * （「開啟」要 slug、「複製短碼」不要 slug、「查看報表」連賣場狀態都不看——
 * 三者檢查的根本是不同的東西）。這裡兩個呼叫端檢查的是**逐字相同**的條件，
 * 反而是各寫一份會分家：改了一頁的措辭、另一頁沒改，同一個狀況兩頁說法不同。
 *
 * ── 訊息措辭必須兩頁都成立 ──────────────────────────────
 *
 * 舊訊息結尾是「請進編輯頁重設一次密碼」，在 `#/profit-shop` 上會把站長送到
 * **賣場**編輯頁（四個 Tab 都沒有密碼欄位），他會翻遍找不到、認定系統壞了。
 * 因此明確寫出「分潤夥伴編輯頁」。
 *
 * ── `state` 三態的必要性 ────────────────────────────────
 *
 * 見 `TPartnerQueryState` 的 docblock。同一列的「報表」與「複製密碼」兩顆按鈕
 * 對同一個狀況必須給出一致的說法，因此兩者都吃這個三態
 * （`ProfitShop/List` 的 `resolveViewReportBlockedReason` 同步改用）。
 *
 * ⚠️ `ProfitPartner/List` 呼叫時 `state` 恆為 `'ready'`——該頁一列就是一個夥伴，
 * `record` 必然存在（列表沒資料時根本不會 render 任何列）。因此 `!partner` 分支
 * 在該頁**不可達**，但**不可刪**：跨頁共用的另一端（`ProfitShop/List`，
 * 以 `partner_term_id` 反查、查得到查不到都有可能）會走到。
 *
 * @param {TProfitPartner|undefined} partner 夥伴資料（查不到時為 undefined）
 * @param {TPartnerQueryState}       state   夥伴列表查詢的三態
 * @return {string|null} 不可用的原因；可用時回 null
 */
export const resolveCopyPartnerPasswordBlockedReason = (
	partner: TProfitPartner | undefined,
	state: TPartnerQueryState
): string | null => {
	// 載入中先停用，但訊息要與「真的找不到」區分——否則每次進頁面都會閃一次
	// 「夥伴已被刪除」的假警告
	if ('loading' === state) {
		return '分潤夥伴資料載入中，請稍候'
	}

	// 查詢失敗時 data 為 undefined，但那不代表夥伴不存在——說成「已被刪除」
	// 會讓站長對著一批其實好好的資料採取補救動作
	if ('error' === state) {
		return '分潤夥伴資料載入失敗，請重新整理頁面'
	}

	if (!partner) {
		return '找不到此賣場的分潤夥伴（可能已被刪除）'
	}

	if (!partner.password) {
		return '此夥伴建立於舊版，資料庫僅存不可逆的雜湊，無法還原密碼。請至分潤夥伴編輯頁重設一次密碼。'
	}

	return null
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
