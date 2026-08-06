/**
 * 分潤結算前端型別定義
 *
 * 對齊後端 `Application/DTO/Settlement*.php` 與
 * `Infrastructure/Persistence/WpAdminSettlementQuery.php` 的輸出形狀。
 *
 * 🔴 **所有金額一律是十進位字串**（後端以 bcmath 產出），不是 number。
 * 撥款金額用 float 累加會在幾千筆之後出現分位誤差，而這裡的數字會直接變成
 * 站長轉給夥伴的金額——**任何一端把它轉成 number 再轉回來都算破壞契約**。
 * 前端只做「顯示」與「原樣回傳」，絕不做算術（合計一律取後端給的 `total_amount` /
 * `*_amount`，不要自己 reduce 相加）。
 */

import type { Dayjs } from 'dayjs'

/** 結算狀態（對齊 PHP `SettlementRecord::STATUS_*`） */
export const SETTLEMENT_STATUS = {
	PENDING: 'pending',
	PAID: 'paid',
	REFUNDED: 'refunded',
	CANCELLED: 'cancelled',
} as const

export type TSettlementStatus =
	(typeof SETTLEMENT_STATUS)[keyof typeof SETTLEMENT_STATUS]

/**
 * 狀態顯示對映（label 必須與訂單頁分潤面板逐字一致）
 *
 * 對應 `view-order-profit-panel.feature` 的
 * 「Rule: 後置（狀態）- 結算狀態以中文呈現且與結算頁一致」——
 * 兩個頁面共用這一份，就不會出現「結算頁寫『待結算』、訂單頁寫『未撥款』」的分歧。
 */
export const SETTLEMENT_STATUS_META: Record<
	TSettlementStatus,
	{ label: string; color: string }
> = {
	[SETTLEMENT_STATUS.PENDING]: { label: '待結算', color: 'gold' },
	[SETTLEMENT_STATUS.PAID]: { label: '已撥款', color: 'green' },
	[SETTLEMENT_STATUS.REFUNDED]: { label: '已退款', color: 'red' },
	[SETTLEMENT_STATUS.CANCELLED]: { label: '已取消', color: 'default' },
}

/** 狀態下拉選項（順序即畫面順序） */
export const SETTLEMENT_STATUS_OPTIONS = (
	Object.keys(SETTLEMENT_STATUS_META) as TSettlementStatus[]
).map((value) => ({ value, label: SETTLEMENT_STATUS_META[value].label }))

/**
 * 賣場 / 夥伴已刪除時的佔位文字
 *
 * 🔴 **必須與 PHP `WpAdminSettlementQuery::DELETED_SHOP_LABEL` /
 * `DELETED_PARTNER_LABEL` 逐字相同**。
 *
 * 結算頁的名稱由後端解析後直接送來（前端不需要這兩個常數）；
 * 但**訂單頁的分潤面板**是直接讀訂單品項上凍結的 meta，名稱得在前端解析
 * （見 `Orders/Edit/ProfitPanel`），因此需要自己填佔位文字。
 * 兩邊字串一旦分家，站長會在兩個頁面看到同一筆紀錄有不同的賣場名稱，
 * 而且沒有任何錯誤訊息。
 */
export const DELETED_SHOP_LABEL = '[已刪除賣場]'
export const DELETED_PARTNER_LABEL = '[已停用夥伴]'

/**
 * 結算列表的單列
 *
 * 對齊 `WpAdminSettlementQuery::find_settlements()` 的逐欄輸出。
 */
export type TSettlementRow = {
	order_item_id: number
	order_id: number
	product_name: string
	partner_term_id: number

	/** 後端已解析；夥伴已刪除時為 `DELETED_PARTNER_LABEL` */
	partner_name: string
	shop_id: number

	/** 後端已解析；賣場已刪除 / 已丟垃圾桶時為 `DELETED_SHOP_LABEL` */
	shop_name: string

	/** 十進位字串 */
	actual_price: string

	/** 十進位字串 */
	profit_amount: string

	/** 分潤比例（%） */
	rate: number

	/**
	 * 是否需要人工設定比例（後端在 `rate === 0` 時為 true）
	 *
	 * 🔴 這類紀錄**必須照常顯示**，不可被前端過濾掉：顧客確實付了分潤價，
	 * 只是結帳當下賣場已被刪除、比例算不出來。濾掉它等於讓一筆該付的錢
	 * 從系統中消失（後端 `find_settlements()` 的行內註解同義）。
	 */
	needs_manual_rate: boolean

	status: TSettlementStatus

	/** 撥款時間（unix 秒），未撥款為 null */
	settled_at: number | null

	/** 訂單建立日期（後端格式化後的字串） */
	order_date: string
}

/** `GET /profit-settlements` 的 data 區塊 */
export type TSettlementList = {
	items: TSettlementRow[]
	total: number
	page: number
	per_page: number
}

/** `GET /profit-settlements/summary` 的 data 區塊 */
export type TSettlementSummary = {
	pending_amount: string
	pending_count: number
	paid_amount: string
	paid_count: number
	refunded_amount: string
	refunded_count: number
	cancelled_amount: string
	cancelled_count: number
	total_sales: string
	total_count: number
}

/**
 * 三個批次操作（mark-paid / mark-cancelled / reverse-paid）的共用回應
 *
 * 🔴 `skipped` 是**部分成功**的核心資訊，不是可省略的細節。
 * 它涵蓋「找不到」與「狀態不符」兩類，鍵是 `order_item_id`（JSON 化後是字串），
 * 值是原因。兩個管理員同時按下「標記已撥款」時，第二個人必須看到
 * 「這幾筆已經是 paid 了，本次未重複計入」——只說「操作成功」會讓他以為
 * 自己那次也生效了，而這是真實金流：他很可能真的再去銀行轉一次帳。
 */
export type TSettlementBatchResult = {
	updated_ids: number[]
	updated_count: number

	/** `{ [order_item_id]: 原因 }`，JSON 化後鍵為字串 */
	skipped: Record<string, string>
	skipped_count: number

	/** 本次異動的分潤金額合計（十進位字串，直接顯示、不要自己加總） */
	total_amount: string

	/**
	 * 稽核紀錄寫入失敗的 partner term ID
	 *
	 * 這是**部分成功**：狀態已經改掉了，只有 `_partner_settled_log` 沒寫進去。
	 * 併進失敗清單會讓站長以為那些品項沒被撥款而重撥；完全不報則會讓稽核紀錄
	 * 悄悄少一筆、日後對不上帳。因此獨立成一則警告。
	 */
	audit_log_failed_partner_ids: number[]
}

/** `POST /profit-settlements/export` 的 data 區塊 */
export type TSettlementExport = {
	filename: string
	mime_type: string

	/** CSV 全文的 base64（解碼後含 UTF-8 BOM，前端**不要**再加一次） */
	content_base64: string
	row_count: number
}

/** `GET /profit-settlements/order-ids` 的 data 區塊 */
export type TSettlementOrderIds = {
	order_ids: number[]

	/** 符合條件的訂單總數（未受 limit 影響） */
	total: number
	limit: number

	/**
	 * 是否因超過上限而被截斷
	 *
	 * 🔴 為 true 時 UI **必須**明確告知站長，不可靜默呈現截斷後的結果——
	 * 否則他會直接得出「這個賣場只有這幾張訂單」的錯誤結論。
	 */
	truncated: boolean
}

/**
 * 結算頁篩選表單的值
 *
 * `date_range` 是 antd RangePicker 的原始值，送出前才轉成 unix 秒
 * （見 `utils.ts` 的 `toSettlementQuery`）。
 */
export type TSettlementFilterValues = {
	partner_term_id?: number
	shop_id?: number
	status?: TSettlementStatus[]
	date_range?: [Dayjs | null, Dayjs | null] | null
}

/**
 * 送給後端的查詢參數（已正規化）
 *
 * 多值欄位一律以**逗號字串**送出：後端 `SettlementFilterInput::flatten()`
 * 對字串會 `explode(',')`，這是所有 HTTP client / 序列化方式都不會弄錯的形式
 * （送陣列則取決於 dataProvider 用 `a[]=1&a[]=2` 還是 `a=1&a=2`）。
 */
export type TSettlementQuery = {
	partner_term_ids?: string
	shop_ids?: string
	statuses?: string
	date_start?: number
	date_end?: number
	page?: number
	per_page?: number
}

/** 三個批次操作 */
export const SETTLEMENT_BATCH_ACTION = {
	MARK_PAID: 'mark-paid',
	MARK_CANCELLED: 'mark-cancelled',
	REVERSE_PAID: 'reverse-paid',
} as const

export type TSettlementBatchAction =
	(typeof SETTLEMENT_BATCH_ACTION)[keyof typeof SETTLEMENT_BATCH_ACTION]

/**
 * 每個批次操作的中繼資料
 *
 * `allowedFrom` 是**唯一真相在後端**（`SettlementRecord` 狀態機）的前端鏡像，
 * 只用來在按下按鈕前先算出「這批選取裡有幾筆會被跳過」，讓站長在確認視窗裡
 * 就看得到，而不是送出後才從 `skipped` 得知。後端仍會獨立驗證，
 * 前端算錯只會多送幾筆被跳過的、不會造成越權轉換。
 */
export const SETTLEMENT_BATCH_ACTION_META: Record<
	TSettlementBatchAction,
	{
		label: string
		/** 確認視窗標題 */
		title: string
		/** 允許的來源狀態 */
		allowedFrom: TSettlementStatus[]
		/** 轉換後的狀態 */
		to: TSettlementStatus
		/** 是否為危險操作（確認鈕轉紅） */
		danger: boolean
	}
> = {
	[SETTLEMENT_BATCH_ACTION.MARK_PAID]: {
		label: '標記已撥款',
		title: '確認標記為已撥款',
		allowedFrom: [SETTLEMENT_STATUS.PENDING],
		to: SETTLEMENT_STATUS.PAID,
		danger: false,
	},
	[SETTLEMENT_BATCH_ACTION.MARK_CANCELLED]: {
		label: '標記取消',
		title: '確認標記為取消',
		allowedFrom: [SETTLEMENT_STATUS.PENDING],
		to: SETTLEMENT_STATUS.CANCELLED,
		danger: true,
	},
	[SETTLEMENT_BATCH_ACTION.REVERSE_PAID]: {
		label: '人工沖回',
		title: '確認人工沖回（已撥款 → 已退款）',
		allowedFrom: [SETTLEMENT_STATUS.PAID],
		to: SETTLEMENT_STATUS.REFUNDED,
		danger: true,
	},
}
