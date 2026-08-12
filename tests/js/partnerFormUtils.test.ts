/**
 * 夥伴表單的純函式：密碼產生器與圖片降維
 *
 * 對應規格：specs/features/profit-shop/manage-partner-credentials.feature
 *   Rule: 前端（產生）- 密碼欄位旁的產生按鈕只填入不送出
 *
 * ── 這個檔案守的是一條跨層契約 ────────────────────────────────
 *
 * `generatePassword()` 產出的密碼，之後必須能通過**後端** `PartnerPassword` VO
 * （≥ 8 codepoint + ≥ 1 英文 + ≥ 1 數字）。兩者分屬前後端、沒有共用程式碼，
 * 只靠「寫的時候記得對齊」——那正是本專案已經吃過虧的失效模式。
 *
 * 對不齊的症狀特別惡劣且不會有錯誤訊息：站長按「產生」拿到一組純數字密碼、
 * 存檔成功、夥伴登入也成功，直到那位夥伴想**自己改密碼**時才被 VO 擋下——
 * 他現在正在用的密碼，反而是系統不允許他設定的。
 */
import { describe, it, expect } from 'vitest'
import { generatePassword, toImageId } from '@/pages/admin/ProfitPartner/utils'

/** 後端 PartnerPassword VO 的規則，在此重述一次當作驗收標準 */
const passesBackendVo = (pw: string): boolean =>
  [...pw].length >= 8 && /[A-Za-z]/.test(pw) && /[0-9]/.test(pw)

describe('generatePassword — 站長按「產生」拿到的密碼', () => {
  it('長度為 12', () => {
    expect(generatePassword()).toHaveLength(12)
  })

  it('🔴 必定通過後端 PartnerPassword VO 的強度規則（跨層契約）', () => {
    // 抽 300 次而不是 1 次：純字母或純數字是**機率事件**，
    // 單次抽樣幾乎不可能撞到，補強邏輯壞掉也測不出來。
    for (let i = 0; i < 300; i++) {
      const pw = generatePassword()
      expect(passesBackendVo(pw), `第 ${i} 次產生的 "${pw}" 不符合後端強度規則`).toBe(true)
    }
  })

  it('排除易混淆字元 0 O 1 l I', () => {
    // 站長常用 LINE 或電話把密碼唸給夥伴聽，一個 l 與 1 的混淆
    // 就是一次登入失敗加一通客訴
    for (let i = 0; i < 200; i++) {
      expect(generatePassword()).not.toMatch(/[0O1lI]/)
    }
  })

  it('只含英數，不含符號', () => {
    // 符號在跨平台複製貼上（尤其通訊軟體的自動格式化）時最容易出事
    for (let i = 0; i < 100; i++) {
      expect(generatePassword()).toMatch(/^[A-Za-z2-9]+$/)
    }
  })

  it('每次產生都不同', () => {
    const seen = new Set(Array.from({ length: 200 }, () => generatePassword()))
    expect(seen.size).toBe(200)
  })

  it('🔴 走 crypto.getRandomValues，不是 Math.random', () => {
    // Math.random 的輸出可預測，拿來產生密碼等於把「隨機」變成裝飾。
    // 這裡直接觀測有沒有呼叫到 crypto——改用 Math.random 會讓這條紅燈。
    const original = crypto.getRandomValues.bind(crypto)
    let called = 0
    const spy = ((arr: Uint32Array) => {
      called++
      return original(arr)
    }) as typeof crypto.getRandomValues

    Object.defineProperty(crypto, 'getRandomValues', { value: spy, configurable: true })
    try {
      generatePassword()
      expect(called, '必須呼叫 crypto.getRandomValues').toBe(1)
    } finally {
      Object.defineProperty(crypto, 'getRandomValues', { value: original, configurable: true })
    }
  })

  it('🔴 不發出任何網路請求（產生是純前端，按儲存才生效）', () => {
    // Rule 的 Example：「系統不應發出任何請求」。
    // 這條是刻意的設計：產生後若使用者反悔直接離開，夥伴密碼必須維持不變——
    // 若改成呼叫後端 regenerate-password，離開頁面時密碼**已經被換掉了**，
    // 而站長並不知情，那位夥伴會突然登不進報表頁。
    const originalFetch = globalThis.fetch
    let fetchCalls = 0
    globalThis.fetch = (() => {
      fetchCalls++
      return Promise.reject(new Error('不該發出請求'))
    }) as typeof fetch

    try {
      generatePassword()
      expect(fetchCalls).toBe(0)
    } finally {
      globalThis.fetch = originalFetch
    }
  })
})

describe('toImageId — 夥伴圖片降維成 image_id', () => {
  it('取第一張圖的 id 並轉成數字', () => {
    expect(toImageId([{ id: '42', url: 'x' } as never])).toBe(42)
  })

  it('無圖 / undefined 一律回 null（= 移除圖片）', () => {
    expect(toImageId(undefined)).toBeNull()
    expect(toImageId([])).toBeNull()
  })

  it('🔴 轉不出正整數時回 null，不得把 NaN 送進 payload', () => {
    // TImage.id 是**字串**（媒體庫慣例）。少了這道守門，
    // `Number('abc')` 的 NaN 會被 JSON.stringify 序列化成 `null` 送出去——
    // 表面上看起來一樣，但 0 與負數會原樣送出，後端拿到會是無效的 attachment ID。
    expect(toImageId([{ id: 'abc' } as never])).toBeNull()
    expect(toImageId([{ id: '0' } as never])).toBeNull()
    expect(toImageId([{ id: '-3' } as never])).toBeNull()
  })
})
