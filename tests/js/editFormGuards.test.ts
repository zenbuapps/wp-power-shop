/**
 * 編輯頁的兩道表單守門
 *
 * 對應規格：
 *   specs/features/profit-shop/query-shop-detail.feature
 *     Rule: 前端（填表）- 編輯頁載入後不得被背景重取資料覆蓋
 *   specs/features/profit-shop/manage-partner-credentials.feature
 *     Rule: 路徑（更新）- 只在密碼真的改動時才送出
 *
 * ── 兩個判斷式都只有幾個字元，漏掉的後果卻由使用者承擔 ────────
 *
 * 而且兩者都**不會有任何錯誤訊息**：一個是打到一半的字被吃掉，
 * 一個是夥伴莫名其妙被登出。站長回報時只會說「怪怪的」。
 */
import { describe, it, expect } from 'vitest'
import {
	shouldFillForm,
	shouldSendPassword,
} from '@/pages/admin/ProfitShop/formGuards'

describe('shouldFillForm — 防背景重取覆蓋使用者編輯', () => {
	it('首次拿到 record 時填表', () => {
		expect(shouldFillForm(1932, null)).toBe(true)
	})

	it('🔴 同一筆 record 再次抵達時不得重填', () => {
		// React Query 預設 refetchOnWindowFocus：使用者切到別的視窗查資料再切回來，
		// record 的 reference 就變了、useEffect 再跑一次——若不擋，
		// **他正在打字的內容會整個被伺服器的舊值覆蓋掉**，且毫無提示。
		expect(shouldFillForm(1932, 1932)).toBe(false)
	})

	it('切換到不同 id 的編輯頁時要重新填', () => {
		// 路由從 /edit/1932 換到 /edit/2000 時元件可能不 unmount，
		// 不重填會讓畫面停在上一筆賣場的資料
		expect(shouldFillForm(2000, 1932)).toBe(true)
	})

	it('record 尚未載入（undefined）時不填', () => {
		expect(shouldFillForm(undefined, null)).toBe(false)
		expect(shouldFillForm(undefined, 1932)).toBe(false)
	})

	it('id 為 0 時仍照常判斷（0 是合法的比較對象，不可被當成 falsy 跳過）', () => {
		// 寫成 `if (!recordId) return false` 會把 0 一起擋掉。
		// 目前不會有 id 0 的賣場，但那是資料的巧合、不是這個函式該依賴的前提。
		expect(shouldFillForm(0, null)).toBe(true)
		expect(shouldFillForm(0, 0)).toBe(false)
	})
})

describe('shouldSendPassword — 只在密碼真的改動時才送', () => {
	it('🔴 密碼沒改時不得送出', () => {
		// 後端 PartnerTermRepository::save() **一收到 password 就 bump
		// password_changed_at**，那個時間戳會讓該夥伴所有已登入的 session 立即失效。
		// 密碼欄在編輯頁是預填的——不比對就原封不動送回去的話，
		// 站長只是改個名稱按儲存，那位夥伴就會莫名其妙被踢出報表頁。
		expect(shouldSendPassword('abc12345', 'abc12345')).toBe(false)
	})

	it('密碼真的改了才送', () => {
		expect(shouldSendPassword('newpass123', 'abc12345')).toBe(true)
	})

	it('🔴 清空欄位視為「不變更」，連送都不送', () => {
		// 空字串代表站長把欄位清空。後端 PartnerInput::from_array() 會把空字串
		// 正規化為 null（那道防線本身有 DtoRoundTripTest 守著），
		// 但前端先擋掉更好：連帶避免了無謂的 password_changed_at bump。
		expect(shouldSendPassword('', 'abc12345')).toBe(false)
		expect(shouldSendPassword('', null)).toBe(false)
	})

	it('舊資料沒有明文可還原（initial 為 null）時，站長輸入什麼都算改動', () => {
		// 功能上線前建立的夥伴只有雜湊、沒有明文。此時欄位是空的，
		// 站長一旦輸入就是要設新密碼
		expect(shouldSendPassword('abc12345', null)).toBe(true)
	})

	it('只差一個空白也算改動（密碼不做 trim）', () => {
		// 密碼欄位的字面值必須原樣保留——後端 PARTNER_RAW_FIELDS 就是為此存在。
		// 這裡若「順手」trim，站長設的密碼與夥伴實際要輸入的會不一致。
		expect(shouldSendPassword('abc12345 ', 'abc12345')).toBe(true)
		expect(shouldSendPassword(' abc12345', 'abc12345')).toBe(true)
	})

	it('大小寫不同算改動', () => {
		expect(shouldSendPassword('ABC12345', 'abc12345')).toBe(true)
	})
})
