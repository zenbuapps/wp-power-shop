/**
 * 賣場商品補齊查詢的分塊規則（`toChunks`）
 *
 * 對應規格：specs/features/profit-shop/enrich-shop-item-display.feature
 *   Rule: 前置（參數）- 僅在賣場含有商品時才發出補齊查詢
 *   Rule: 前置（參數）- 補齊查詢必須以賣場 items 的 product_id 集合為範圍
 *
 * ── 為什麼測純函式就足以覆蓋這兩條 Rule ──────────────────────
 *
 * `useShopItemProducts` 對這兩條 Rule 做的事只有兩行：
 *   `queryOptions: { enabled: chunk.length > 0 }`  ← 空賣場不發查詢
 *   `filters: [{ field: 'include', value: chunk }]` ← 查詢範圍
 * 兩者都是把 `toChunks()` 的輸出直接接出去。決定行為的是這個純函式，
 * hook 只是轉接。架整套 Refine dataProvider 進 jsdom 只會測到轉接層。
 *
 * ⚠️ 但這也劃出了本檔**測不到**的界線：若有人把 `enabled` 改成常數 `true`，
 * 或把 `include` 換成別的欄位，本檔全綠而行為已壞。那一層屬元件整合，
 * 在 .feature 內另以 e2e 覆蓋，不要把本檔的綠燈當成整條鏈路的保證。
 */
import { describe, it, expect } from 'vitest'
import { toChunks } from '@/pages/admin/ProfitShop/hooks'

/** 產生 n 個遞增 id，起點可指定 */
const ids = (n: number, from = 1): number[] =>
  Array.from({ length: n }, (_, i) => from + i)

describe('toChunks — 補齊查詢的分塊', () => {
  it('固定回傳 5 塊，不隨商品數變動', () => {
    // hook 數量必須固定（Rules of Hooks）——塊數浮動 React 會直接炸
    expect(toChunks([])).toHaveLength(5)
    expect(toChunks(ids(1))).toHaveLength(5)
    expect(toChunks(ids(500))).toHaveLength(5)
  })

  it('賣場沒有商品時每一塊都是空的（= 五個查詢全部停用）', () => {
    // Rule: 僅在賣場含有商品時才發出補齊查詢
    // enabled 的判斷式是 `chunk.length > 0`，所以「全空」等同「零查詢」
    expect(toChunks([]).every((c) => c.length === 0)).toBe(true)
  })

  it('只有一筆商品時，只有第一塊非空，其餘四個查詢仍停用', () => {
    const [first, ...rest] = toChunks([101])
    expect(first).toEqual([101])
    expect(rest.every((c) => c.length === 0)).toBe(true)
  })

  it('查詢範圍恰好等於 items 的 product_id 集合，不多不少', () => {
    // Rule: 補齊查詢必須以賣場 items 的 product_id 集合為範圍
    const input = [101, 102]
    expect(toChunks(input).flat()).toEqual(input)
  })

  it('每塊最多 100 筆（WC REST 的 per_page 硬上限）', () => {
    const chunks = toChunks(ids(250))
    expect(chunks.map((c) => c.length)).toEqual([100, 100, 50, 0, 0])
  })

  it('超過 500 筆的尾段被丟棄，不會塞爆任何一塊', () => {
    // 賣場商品軟上限 500 = 100 × 5。超出的商品退回只顯示 #id（R-3 已知取捨）——
    // 這是刻意的，但必須是「丟棄」而不是「擠進最後一塊」，否則會送出
    // 超過 per_page 上限的 include，WC 會靜默截斷成前 100 筆。
    const chunks = toChunks(ids(620))
    expect(chunks.map((c) => c.length)).toEqual([100, 100, 100, 100, 100])
    expect(chunks.flat()).toHaveLength(500)
  })

  it('🔴 拖曳排序（同一組 id、不同順序）必須得到逐字相同的分塊', () => {
    // 這是 toChunks 先排序的唯一理由：chunk 內容會直接進 react-query 的 queryKey。
    // 若照 items 原順序切，使用者拖曳排序（商品集合沒變、只換位置）會讓 queryKey
    // 變動 → 整批重新查詢 → 表格閃一次 Skeleton、類型/狀態/價格/庫存欄短暫變「—」。
    const original = [305, 101, 202]
    const dragged = [202, 305, 101] // 使用者拖動後，集合完全相同

    expect(toChunks(dragged)).toEqual(toChunks(original))
    expect(toChunks(original)[0]).toEqual([101, 202, 305])
  })

  it('不修改傳入的陣列（排序必須在複本上做）', () => {
    // 直接 sort 傳入陣列會就地改動 caller 的 state。在 React 裡改到 state 物件
    // 不會觸發重繪，但下一次比較會誤判成「沒變」——這種 bug 極難追。
    const input = [3, 1, 2]
    toChunks(input)
    expect(input).toEqual([3, 1, 2])
  })
})
