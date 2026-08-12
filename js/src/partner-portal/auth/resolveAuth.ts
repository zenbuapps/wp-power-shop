import type { TMeOutput } from './api'

/**
 * 認證狀態三態
 *
 * 定義放在這裡而非 `AuthContext.tsx`，是為了讓推導邏輯與型別同住一個檔案、
 * 且不會與 `AuthContext` 形成循環相依（後者 import 本檔）。
 * `AuthContext` 仍 re-export 它，既有 import 路徑不受影響。
 */
export type TAuthStatus = 'loading' | 'authenticated' | 'guest'

/**
 * 跨夥伴遮蔽：由 /me 的查詢結果與網址 slug 推導出對外的認證狀態
 *
 * 從 `AuthContext.tsx` 抽出來，讓這條**安全不變量**能被直接測到——
 * 它決定的是「攻擊者直接打開另一位夥伴的網址時，畫面會不會閃出那個人的資料」。
 */

/** 推導所需的 /me 查詢狀態（只取用得到的欄位，不綁 react-query 型別） */
export type TMeQueryState = {
	isLoading: boolean
	isSuccess: boolean
	data: TMeOutput | null | undefined
}

export type TResolvedAuth = {
	status: TAuthStatus

	/** 對外暴露的 partner；mismatch 時必為 null */
	partner: TMeOutput | null

	/** 是否偵測到跨夥伴不一致（呼叫端據此觸發強制登出） */
	isMismatch: boolean
}

/**
 * 推導認證狀態
 *
 * 🔴 **兩道防線缺一不可**，這是本函式存在的全部理由：
 *
 *   1. mismatch 時 `status` 設 `'loading'` 而**不是** `'authenticated'`
 *      ——期間 AuthGate 顯示載入畫面而非 redirect，避免在強制登出生效前
 *        閃過另一位夥伴的資料
 *   2. `partner` 物件同時遮蔽成 `null`
 *      ——即使某種 race 下 status 短暫為 `'authenticated'`，下游拿到的仍是 null
 *
 * 只做第 1 道：`useEffect` 觸發登出**之前**的那一幀，status 仍可能是
 * `'authenticated'`，Dashboard 會閃出另一位夥伴的姓名。
 * 只做第 2 道：status 是 `'guest'` 會讓畫面直接 redirect 到登入頁，
 * 使用者看到的是「莫名其妙被登出」而不是「這個網址不屬於你」。
 *
 * ⚠️ `slug` 為空時視為 matched（向後相容）：env 解密失敗或測試環境下
 * 取不到 slug，此時若一律判為 mismatch，**所有夥伴都會登不進去**。
 * 這是刻意的降級——真正的權限由後端 token 把關，前端這層只是防止畫面閃錯資料。
 */
export const resolveAuth = (
	meQuery: TMeQueryState,
	slug: string | null | undefined
): TResolvedAuth => {
	const isPartnerMatched = !slug || (meQuery.data?.partner_slug ?? null) === slug

	let status: TAuthStatus
	if (meQuery.isLoading) {
		status = 'loading'
	} else if (meQuery.isSuccess) {
		status = isPartnerMatched ? 'authenticated' : 'loading'
	} else {
		status = 'guest'
	}

	return {
		status,
		partner: meQuery.data && isPartnerMatched ? meQuery.data : null,
		isMismatch: Boolean(meQuery.data) && !isPartnerMatched,
	}
}
