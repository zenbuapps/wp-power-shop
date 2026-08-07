/**
 * 幾條「保證住在程式碼形狀裡」的接線
 *
 * 對應規格：
 *   select-shop-products.feature
 *     Rule: 前置（開啟）- Modal 必須提供與商品列表頁相同的篩選能力
 *     Rule: 後置（取消）- 取消 Modal 不得變更賣場商品列表
 *     Rule: 後置（加入）- 未勾選任何商品時確認按鈕應為停用狀態
 *   save-variation-overrides.feature
 *     Rule: 前端（展開）- 可變商品在商品名稱欄提供展開鈕
 *   login-partner.feature
 *     Rule: 前置（表單）- 登入頁必須從網址帶入 slug 且不可由使用者更改
 *
 * ── 為什麼這幾條用原始碼斷言，而不是行為測試 ────────────────
 *
 * 它們的保證**本身就是程式碼的形狀**，不是可抽出來的邏輯：
 *   - 「與商品列表頁相同的篩選能力」＝ 複用同一個 `ProductTableBody`。
 *     複製一份出來改，行為測試照樣會綠，但兩份會從此各自漂移。
 *   - 「取消不得變更列表」＝ Modal 的 onCancel 走 handleClose（reset 選取）
 *     而**不是** onConfirm。這是一個接線，不是一段計算。
 *   - 「展開鈕在商品名稱欄」＝ `showExpandColumn: false` 把 antd 預設的
 *     最左展開欄關掉，改在目標欄位自刻。少了它會多出一欄，
 *     而畫面看起來仍然「能展開」——行為測試分不出來。
 *
 * ⚠️ **這類測試守得住的東西有限**，必須誠實標明界線：
 * 它們證明「接線還在」，不證明「接線正確運作」。例如它們無法發現
 * `ProductTableBody` 被傳了錯誤的 props、或 `destroyOnHidden` 因為
 * antd 改版而失效。真正的端到端行為由 e2e 覆蓋。
 */
import { describe, it, expect } from 'vitest'
import * as fs from 'node:fs'
import * as path from 'node:path'

/**
 * 讀原始碼並**移除所有註解**
 *
 * 🔴 這一步不可省略。第一版直接對整份檔案比對，結果
 * `expect(itemsEditor).toMatch(/showExpandColumn:\s*false/)` 命中的是
 * **註解裡**那句「`showExpandColumn: false` 關掉了」——把程式碼改成 `true`
 * 之後測試依然全綠。那是一個**永遠不會紅的測試**，比沒有測試更糟：
 * 它會讓人以為這條規則有人守著。
 *
 * 這類檔案的註解密度很高（本專案刻意如此），而註解**必然**會提到它在說明的
 * 那段程式碼，所以「對整份原始碼做子字串比對」在這裡幾乎注定失效。
 */
const read = (rel: string) =>
	fs
		.readFileSync(path.resolve(process.cwd(), rel), 'utf-8')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/(^|[^:])\/\/.*$/gm, '$1')

const pickerHooks = read(
	'js/src/pages/admin/ProfitShop/components/ProductPickerModal/hooks.tsx'
)
const pickerIndex = read(
	'js/src/pages/admin/ProfitShop/components/ProductPickerModal/index.tsx'
)
const itemsEditor = read(
	'js/src/pages/admin/ProfitShop/components/ItemsEditor.tsx'
)
const login = read('js/src/partner-portal/pages/Login.tsx')

describe('商品選擇 Modal 的接線', () => {
	it('🔴 複用商品列表頁的 ProductTableBody，而非自己刻一份篩選器', () => {
		// Rule: 前置（開啟）- Modal 必須提供與商品列表頁相同的篩選能力。
		// 「相同」的唯一可靠保證就是同一個元件。複製一份出來改，
		// 行為測試照樣會綠，但 #/products 之後每加一個篩選條件，
		// Modal 就少一個——而且沒有任何東西會提醒你。
		expect(pickerIndex).toMatch(
			/from\s+['"][^'"]*ProductTable\/ProductTableBody['"]/
		)
	})

	it('🔴 useTable 明確關閉 syncWithLocation', () => {
		// 預設繼承全域 true，會把 Modal 內的篩選 / 分頁寫進宿主頁
		//（#/profit-shop/edit/{id}）的網址，關閉 Modal 後還殘留在那裡
		expect(pickerIndex).toMatch(/syncWithLocation:\s*false/)
	})

	it('🔴 取消時走 handleClose（會 reset 選取），不是 onConfirm', () => {
		// Rule: 後置（取消）- 取消 Modal 不得變更賣場商品列表。
		// handleClose 只 close + 清空；handleConfirm 才呼叫 onConfirm。
		// 接錯的話「取消」會把勾選的商品加進賣場——與按鈕上的字完全相反。
		expect(pickerHooks).toMatch(/onCancel:\s*handleClose/)
		expect(pickerHooks).toMatch(/handleConfirm[\s\S]{0,200}onConfirm\(selectedRecords\)/)
	})

	it('🔴 onCancel 必須放在 ...modalProps 之後', () => {
		// useSimpleModal 內部會把 onCancel 設成它自己的 close，
		// 放在 spread **前面**會被蓋掉 → 關閉時不 reset 選取集合，
		// 下次開啟殘留上次的勾選。
		const spreadAt = pickerHooks.indexOf('...modalProps')
		const cancelAt = pickerHooks.indexOf('onCancel: handleClose')
		expect(spreadAt).toBeGreaterThan(-1)
		expect(cancelAt).toBeGreaterThan(spreadAt)
	})

	it('關閉時不掛載 children（destroyOnHidden）', () => {
		// Modal 沒開時不會空跑 useTable 查詢，也不會殘留捲動位置
		expect(pickerHooks).toMatch(/destroyOnHidden:\s*true/)
	})

	it('🔴 未勾選任何商品時確認按鈕停用', () => {
		// Rule: 後置（加入）。允許送出空集合會讓 handleBatchAdd 白跑一趟，
		// 使用者看到 Modal 關閉卻什麼都沒加，會以為功能壞了。
		expect(pickerHooks).toMatch(/disabled=\{0 === selectedCount\}/)
	})
})

describe('變體展開鈕的接線', () => {
	it('🔴 關掉 antd 預設的最左展開欄（showExpandColumn: false）', () => {
		// Rule: 前端（展開）- 可變商品在**商品名稱欄**提供展開鈕。
		// 少了這行，antd 會在表格最左端多插一欄自己的展開鈕——
		// 畫面看起來仍然「能展開」，只是位置不對且多了一欄，
		// 行為測試完全分不出來。
		expect(itemsEditor).toMatch(/showExpandColumn:\s*false/)
	})

	it('🔴 展開狀態必須受控（expandedRowKeys）', () => {
		// 關掉預設展開欄之後，antd 不知道自刻的按鈕對應哪一列——
		// 非受控的話點下去完全沒有反應，且沒有任何錯誤訊息
		expect(itemsEditor).toMatch(/expandedRowKeys:\s*expandedKeys/)
	})

	it('只有可變商品可展開（rowExpandable）', () => {
		expect(itemsEditor).toMatch(/rowExpandable:/)
		expect(itemsEditor).toMatch(/isVariable\(/)
	})
})

describe('Partner 登入頁的 slug 帶入', () => {
	it('🔴 slug 從網址帶入且使用者不可更改', () => {
		// Rule: 前置（表單）。夥伴通常是點連結進來的，slug 讓他自己打
		// 只會打錯——而打錯的結果是「查無此夥伴」，看起來像帳號被停用。
		expect(login).toMatch(/initialValues=\{\{\s*slug:\s*SLUG\s*\}\}/)
		expect(login).toMatch(/disabled=\{Boolean\(SLUG\)\}/)
	})

	it('⚠️ SLUG 取不到時欄位可編輯（刻意的降級，非疏漏）', () => {
		// `disabled={Boolean(SLUG)}` 而非 `disabled`：env 解密失敗時
		// 若一律鎖死，夥伴會看到一個空的、不能填的必填欄位，徹底登不進去。
		// 這與 resolveAuth 對空 slug 視為 matched 是同一條降級紀律。
		expect(login).not.toMatch(/name="slug"[\s\S]{0,200}<Input[^>]*\sdisabled(\s|\/|>)/)
	})
})
