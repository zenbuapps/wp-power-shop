/**
 * 編輯頁的兩道表單守門
 *
 * 兩者都是**只有幾個字元的判斷式，但漏掉的後果由使用者承擔**，而且都不會有
 * 任何錯誤訊息。抽成具名函式是為了讓它們測得到、也讓下一個人知道那不是
 * 可以「順手簡化」的樣板碼。
 */

/**
 * 是否應該把 record 填進表單
 *
 * React Query 預設 `refetchOnWindowFocus`，使用者切到別的視窗再切回來就會
 * 觸發重取；`record` 的 reference 一變，填表的 `useEffect` 就會再跑一次，
 * **把使用者正在打字的內容整個覆蓋掉**。
 *
 * 因此只在「首次取得 record」或「切換到不同 id 的編輯頁」時才填。
 *
 * @param recordId  這次拿到的 record id（尚未載入時為 undefined）
 * @param filledId  上次填表用的 id（尚未填過為 null）
 */
export const shouldFillForm = (
	recordId: number | undefined,
	filledId: number | null
): boolean => {
	if (undefined === recordId) return false
	return filledId !== recordId
}

/**
 * 是否應該把密碼一起送出
 *
 * 🔴 後端 `PartnerTermRepository::save()` **一收到 password 就 bump
 * `password_changed_at`**，而那個時間戳會讓該夥伴所有已登入的 session
 * 立即失效。密碼欄在編輯頁是預填的，若不比對就原封不動送回去，
 * 站長只是改個名稱按儲存，那位夥伴就會莫名其妙被踢出報表頁。
 *
 * 空字串代表「站長把欄位清空」＝不變更密碼（後端 `PartnerInput::from_array()`
 * 會把空字串正規化為 null）。這裡先擋掉，連送都不送。
 *
 * @param nextPassword    表單目前的值
 * @param initialPassword 載入時的原值（舊資料沒有明文可還原時為 null）
 */
export const shouldSendPassword = (
	nextPassword: string,
	initialPassword: string | null
): boolean => {
	if ('' === nextPassword) return false
	return nextPassword !== initialPassword
}
