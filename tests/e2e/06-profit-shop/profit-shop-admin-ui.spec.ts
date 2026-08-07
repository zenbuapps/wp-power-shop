/**
 * 分潤賣場 admin SPA 的 UI 行為
 *
 * 對應規格：
 *   specs/features/profit-shop/query-shop-detail.feature（前端（列表））
 *   specs/features/profit-shop/save-profit-shop.feature（前端（表單））
 *
 * ── 為什麼這幾條只能用 e2e 測 ────────────────────────────────
 *
 * 它們的契約住在 React 元件的**渲染結果**（依資料狀態切換不同的主要動作、
 * debounce 後才發出驗證請求、驗證失敗導向出錯的分頁），不是可獨立 import 的
 * 純函式。純函式的部分已由 tests/js/**.test.ts（vitest）覆蓋，這裡只補元件層。
 *
 * ⚠️ selector 一律取自實地 dump 的 DOM，**不用猜的**（第一版用猜的，三條有兩條紅）：
 *   - slug 輸入框**沒有 id**，只有 placeholder="my-shop"
 *   - 列表篩選是 input#search
 *
 * 🔴 **尚未納入的三條**（操作欄依 mode / SlugInput 即時衝突 / 夥伴列表篩選）
 * 卡在一個環境層面的落差，不是測試寫法問題：透過 REST 建立賣場**回 2xx 且拿得到
 * id**，但 SPA 列表隨即顯示「尚未建立任何分潤賣場」（實地 dump 確認 tbody 只有
 * No data 那一列）。在釐清這個落差之前，硬寫的測試只會是紅燈或假綠燈。
 * 對應的 Rule 在 .feature 內仍如實標為 NOT-COVERED，**不假裝已覆蓋**。
 */
import { test, expect, type Page } from '@playwright/test'

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8890'
const SPA = `${BASE_URL}/wp-admin/admin.php?page=power-shop`

/** 進入 SPA 某個 hash route，等到列表或空狀態出現 */
async function gotoList(page: Page, hash: string): Promise<void> {
  await page.goto(`${SPA}#${hash}`)
  await page.locator('.ant-table, .ant-empty').first().waitFor({ state: 'visible', timeout: 45_000 })
}

test.describe('分潤賣場 admin SPA', () => {

  /**
   * Rule: 前端（列表）- 列表頁採篩選卡片加實體列表的標準版型
   * （admin-list-ui.rule.md 硬性規則：兩張 Card、新增按鈕在列表卡片內）
   */
  test('賣場列表採「篩選卡片 + 實體列表」標準版型', async ({ page }) => {
    await gotoList(page, '/profit-shop')

    await expect(page.locator('input#search')).toBeVisible()
    await expect(page.locator('button:has-text("篩選")').first()).toBeVisible()
    await expect(page.locator('button:has-text("重置")').first()).toBeVisible()
    await expect(page.locator('button:has-text("新增賣場")').first()).toBeVisible()
  })

  /**
   * Rule: 前端（表單）- 多分頁表單的驗證失敗必須導向出錯的分頁
   *
   * 所有 Tab 都 forceRender，但非 active 的 pane 是 display:none——欄位驗證有跑、
   * 紅字有渲染，**使用者看不到**。若父層只是靜默 return，畫面就完全沒有回饋
   * （實際發生過：站長停在「分潤設定」按建立，真正沒填的是「基本設定」的 slug）。
   */
  test('多分頁表單驗證失敗時給出回饋並導向出錯的分頁', async ({ page }) => {
    await page.goto(`${SPA}#/profit-shop/create`)
    await page.locator('input#title').waitFor({ state: 'visible', timeout: 45_000 })

    // 只填標題、不填 slug，切到別的分頁再送出
    await page.locator('input#title').fill(`PROBETAB${Date.now()}`)
    await page.locator('.ant-tabs-tab:has-text("分潤設定")').click()
    await page.waitForTimeout(500)

    await page.locator('button:has-text("儲存"), button:has-text("建立")').first().click()

    // 必須有明確回饋，而不是靜默 return
    await expect(
      page.locator('.ant-notification-notice, .ant-message-notice').first()
    ).toBeVisible({ timeout: 15_000 })

    // 且應把使用者帶回出錯的「基本設定」分頁
    await expect(page.locator('.ant-tabs-tab-active:has-text("基本設定")')).toBeVisible({
      timeout: 10_000,
    })
  })

})
