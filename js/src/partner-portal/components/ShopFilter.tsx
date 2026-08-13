/**
 * Partner Portal 賣場篩選器
 *
 * 讓夥伴把 KPI / 趨勢 / 結算明細三塊一起縮到指定賣場。預設「全部賣場」。
 *
 * ⚠️ 本元件**只回控制項本身，不含「賣場」標籤**。標籤與欄位對齊由
 *    Dashboard 的篩選卡片統一提供——期間與賣場必須共用同一條標籤軸，
 *    否則兩列一個有標籤、一個沒有，看起來會像沒排完的半成品。
 *    寬度同理交給容器決定（`width: 100%`），與 DateRangeFilter 的
 *    `<Segmented block />` 對齊視覺重量。
 *
 * 設計取捨：
 * - 用 antd Select（multiple）而非 Segmented：賣場數量不可預期，
 *   Segmented 一多就會換行撐爆版面；Select 有搜尋、有 maxTagCount 收合。
 * - 空陣列 = 全部賣場。不使用 `0` / `'all'` 之類的哨符值——
 *   哨符要在 API 層再翻譯一次，多一層可能對不上的映射。
 * - 清單來源見 `usePartnerShops`：**目前指派給他的賣場 ∪ 有過業績的賣場**，
 *   因此新賣場（零業績）與已被刪除的賣場都在選項內。
 *
 * ⚠️ 選了零業績的賣場會看到 0——那是誠實的答案，不是缺陷。
 *    反過來「看不到那個賣場」才是資訊缺失：夥伴無從分辨是沒業績還是系統漏了。
 *
 * ⚠️ 只有一個賣場時仍然顯示（不自動隱藏）：夥伴看到「這份報表涵蓋哪個賣場」
 *    本身就是有用的資訊，藏起來反而讓人不確定數字的範圍。
 *
 * ─────────────────────────────────────────────────────────────
 * 🔴 選中的 ID 不在清單內時，**保留它、絕不 prune**
 * ─────────────────────────────────────────────────────────────
 * 值可能來自 `?shop_ids=` 深連結（見 `utils/shopIdsParam.ts`），而連結裡的
 * 賣場**未必**在這位夥伴的清單內（站長貼錯、賣場改派給別人、連結過期）。
 *
 * 直覺上會想「不在清單內就把它濾掉」，但那條路是錯的：
 *
 *     prune 成空陣列 → 前端不送 shop_ids → 後端不過濾 → **顯示全部賣場合計**
 *
 * 連結的語意是「只看賣場 X」，結果卻給出一個**比正確答案大**的數字，
 * 而且畫面上沒有任何線索說明發生了什麼。反過來原樣送給後端，
 * 後端的 EXISTS 找不到就誠實回 0——那才是這個問題的正確答案
 * （與後端「清洗後為空要塞哨符、不可退化成不過濾」是同一條紀律）。
 *
 * ⚠️ 後端那個哨符**必須是一個保證匹配不到的正整數**（現行為
 *    `V2Api::SHOP_IDS_FAIL_CLOSED_SENTINEL` = `PHP_INT_MAX`），
 *    **不可**用 `0` 或負數——`WpSettlementSummaryProvider::build_extra_where()`
 *    會先跑 `array_filter( ..., fn( $id ) => $id > 0 )`，`0` 會被那道過濾吃掉、
 *    陣列又變回空的 ⇒ 哨符等於完全沒作用、fail-closed 靜默失效。
 *
 * 因此這裡替「已選中但不在清單內」的 ID 補上合成選項——不補的話 antd Select
 * 會直接把裸數字當 tag 顯示，夥伴看到一串沒有意義的數字。
 *
 * ⚠️ 揭露「目前正在篩選」的**主要責任在 Dashboard**（它有一條常駐 Alert 與
 *    「顯示全部賣場」按鈕）。本元件的三條提早 return 都不渲染 <Select>，
 *    那時只補一句最小提示，不要把 Dashboard 那條 Alert 再複製一份。
 */

import { Alert, Select, Skeleton, Space, Typography } from 'antd'
import { memo } from 'react'

import { usePartnerShops } from '../hooks/usePartnerShops'
import { mapPartnerException } from '../utils/partnerExceptionMapper'

/**
 * ShopFilter props
 *
 * - value：目前選中的賣場 ID，**空陣列 = 全部賣場**
 * - onChange：選取變動時回拋新的 ID 陣列
 */
type TShopFilterProps = {
	value: number[]
	onChange: (shopIds: number[]) => void
}

/** Partner Portal 賣場篩選器元件 */
const ShopFilterComponent: React.FC<TShopFilterProps> = ({
	value,
	onChange,
}) => {
	const { data, isLoading, isError, error } = usePartnerShops()

	const shops = Array.isArray(data) ? data : []

	/*
	 * 清單渲染不出來（載入中 / 失敗 / 空）時的最小提示。
	 * 沒有這一句，「篩選正在生效」這件事在這三種狀態下就完全不可見了。
	 */
	const activeFilterHint =
		value.length > 0 ? (
			<Typography.Text type="secondary" style={{ fontSize: 12 }}>
				目前仍套用著 {value.length} 個賣場篩選
			</Typography.Text>
		) : null

	if (isLoading) {
		return (
			<Space direction="vertical" size={4} style={{ width: '100%' }}>
				<Skeleton.Input active block size="small" />
				{activeFilterHint}
			</Space>
		)
	}

	if (isError) {
		return (
			<Space direction="vertical" size={4} style={{ width: '100%' }}>
				<Alert
					type="warning"
					showIcon
					banner
					message={`賣場清單載入失敗：${mapPartnerException(error)}`}
				/>
				{activeFilterHint}
			</Space>
		)
	}

	/*
	 * 清單為空 = 既沒有被指派任何已發佈的賣場，也沒有任何歷史業績。
	 * 此時給一個明確說明，而不是一個空的下拉選單（那會讓人以為壞了）。
	 */
	if (shops.length === 0) {
		return (
			<Space direction="vertical" size={4} style={{ width: '100%' }}>
				<Typography.Text type="secondary">尚未被指派任何賣場</Typography.Text>
				{activeFilterHint}
			</Space>
		)
	}

	/*
	 * 選項 = 後端清單 ∪「已選中但不在清單內」的合成項（見檔頭：絕不 prune）。
	 * 合成項標明來歷，夥伴才知道那個 tag 不是壞掉的資料，而是連結帶進來的。
	 */
	const knownIds = new Set(shops.map((shop) => shop.shop_id))
	const options = [
		...shops.map((shop) => ({
			label: shop.shop_name,
			value: shop.shop_id,
		})),
		...value
			.filter((shopId) => !knownIds.has(shopId))
			.map((shopId) => ({
				label: `#${shopId}（不在您的賣場清單中）`,
				value: shopId,
			})),
	]

	return (
		<Select
			mode="multiple"
			allowClear
			value={value}
			onChange={onChange}
			placeholder="全部賣場"
			style={{ width: '100%' }}
			maxTagCount="responsive"
			optionFilterProp="label"
			options={options}
		/>
	)
}

export const ShopFilter = memo(ShopFilterComponent)
