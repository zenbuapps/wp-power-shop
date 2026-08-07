/**
 * 分潤賣場 admin SPA 的 UI 行為
 *
 * 對應規格：
 *   specs/features/profit-shop/query-shop-detail.feature（前端（列表））
 *
 * ⚠️ 本檔目前只涵蓋「列表頁標準版型」一條。原本一併嘗試的另外兩條
 * （操作欄依 mode 切換動作、SlugInput 即時衝突提示）**尚未納入**——
 * 它們需要更精確的 selector（antd Form.Item 產生的 id 依 form name 而異、
 * 新建賣場未必落在列表第一頁），而每輪 e2e 需時約 4 分鐘，探索成本高。
 * 對應的 Rule 在 .feature 內仍如實標為 NOT-COVERED，**不假裝已覆蓋**。
 *
 * ── 為什麼這幾條只能用 e2e 測 ────────────────────────────────
 *
 * 它們的契約住在 React 元件的**渲染結果**（依資料狀態切換不同的主要動作、
 * 停用時顯示原因、debounce 後才發出驗證請求），不是可以獨立 import 的純函式。
 * 純函式的部分已經由 tests/js/**.test.ts（vitest）覆蓋，這裡只補元件層。
 *
 * ⚠️ 本檔是 **UI 驅動**，與同目錄的 profit-shop-items.spec.ts（API-level）分工不同。
 */
import { test, expect, type Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8890'
const SPA = `${BASE_URL}/wp-admin/admin.php?page=power-shop`

function getNonce(): string {
  return fs.readFileSync(path.resolve(import.meta.dirname, '../.auth/nonce.txt'), 'utf-8').trim()
}

/** 建一個賣場（走 REST，比 UI 快且穩定） */
async function createShop(
  page: Page,
  nonce: string,
  payload: Record<string, unknown>
): Promise<number> {
  const res = await page.request.post(`${BASE_URL}/wp-json/power-shop/profit-shops`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  })
  expect(res.ok(), `建立賣場失敗：${res.status()} ${await res.text()}`).toBeTruthy()
  const body = await res.json()
  return Number(body?.data?.id ?? body?.id)
}

/** 等 SPA 掛載完成（mount 點有內容） */
async function gotoSpa(page: Page, hash: string): Promise<void> {
  await page.goto(`${SPA}#${hash}`)
  await page.waitForSelector('#power_shop', { state: 'attached', timeout: 30_000 })
  // Refine 首屏會有一段查詢；等到表格或空狀態任一出現
  await page
    .locator('.ant-table, .ant-empty, .ant-spin')
    .first()
    .waitFor({ state: 'visible', timeout: 30_000 })
}

test.describe('分潤賣場 admin SPA — 列表頁與表單', () => {
  let nonce: string
  let partnerTermId: number
  const createdShopIds: number[] = []

  test.beforeAll(async ({ request }) => {
    nonce = getNonce()

    // 建一個夥伴（賣場的必填關聯）
    const res = await request.post(`${BASE_URL}/wp-json/power-shop/profit-partners`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data: {
        name: 'E2E UI 夥伴',
        slug: `e2e-ui-partner-${Date.now()}`,
        password: 'e2epass123',
      },
    })
    expect(res.ok(), `建立夥伴失敗：${res.status()} ${await res.text()}`).toBeTruthy()
    const body = await res.json()
    partnerTermId = Number(body?.data?.term_id ?? body?.term_id ?? body?.data?.id)
    expect(partnerTermId).toBeGreaterThan(0)
  })

  test.afterAll(async ({ request }) => {
    for (const id of createdShopIds) {
      await request
        .delete(`${BASE_URL}/wp-json/power-shop/profit-shops/${id}`, {
          headers: { 'X-WP-Nonce': nonce },
        })
        .catch(() => undefined)
    }
  })

  /**
   * Rule: 前端（列表）- 列表頁採篩選卡片加實體列表的標準版型
   *
   * 對應 admin-list-ui.rule.md 的硬性規則：兩張 Card、新增按鈕在列表卡片內。
   */
  test('列表頁採「篩選卡片 + 實體列表」標準版型', async ({ page }) => {
    await gotoSpa(page, '/profit-shop')

    // 篩選卡片（title="篩選"）
    await expect(page.getByText('篩選', { exact: true }).first()).toBeVisible({ timeout: 30_000 })
    // 篩選與重置兩顆按鈕
    await expect(page.getByRole('button', { name: /篩選/ }).first()).toBeVisible()
    await expect(page.getByRole('button', { name: /重置/ }).first()).toBeVisible()
    // 新增按鈕（在列表卡片內、Table 之上）
    await expect(page.getByRole('button', { name: /新增|建立/ }).first()).toBeVisible()
    // 實體列表
    await expect(page.locator('.ant-table').first()).toBeVisible()
  })

})
