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
 * 🔴 **已解決的根因：`playwright.config.ts` 的 `extraHTTPHeaders: { 'X-WP-Nonce': '' }`**
 *
 * 那個空字串 header 會套用到 **page 內的所有請求**（不只 `request` fixture），
 * 把 SPA 自己送出的有效 nonce 覆蓋掉 ⇒ 列表查詢收到
 * `403 {"code":"rest_cookie_invalid_nonce"}` ⇒ 畫面顯示「尚未建立任何分潤賣場」。
 *
 * 這個現象非常容易被誤判成產品缺陷（先後猜過「SPA 列表壞了」「建立階段失敗」
 * 「SPA 剝錯層數」三種，全錯）。實測排除的順序：
 *   1. `POST /profit-partners` 回 **`data.id`** 不是 `data.term_id`——取錯會拿到 0，
 *      建賣場回 `404 Partner term 0 不存在`
 *   2. 用對欄位後建立回 **201**；`GET /profit-shops` 回
 *      `{"code":"success","data":[…]}`（陣列直接在 data）且含新建那筆 ⇒ **API 完全正常**
 *      ⚠️ SPA 寫 `data?.data?.data` 是對的：Refine 的 useCustom 會再包一層
 *   3. 攔截 SPA 自己的請求 → `403 rest_cookie_invalid_nonce` ⇒ 根因在測試設定
 *
 * 解法：本檔 `test.use({ extraHTTPHeaders: {} })`。修好後「SlugInput 即時衝突」與
 * 「夥伴列表篩選」兩條立刻由紅轉綠——它們都需要 SPA 真的查得到資料。
 *
 * ⚠️ **仍未納入一條**：「操作欄依 mode 呈現不同的主要動作」。同樣的建立流程下，
 * **夥伴**列表看得到新建的夥伴，**賣場**列表卻找不到新建的賣場（不論有無篩選）。
 * 差異點指向賣場列表的分頁 / 排序行為，尚未查清楚。對應的兩條 Rule
 * （change-shop-status 前端（列表）、render-shop-via-shortcode 前端（後台））
 * 在 .feature 內仍如實標為 NOT-COVERED。
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8890'
const SPA = `${BASE_URL}/wp-admin/admin.php?page=power-shop`


/** 從 `{code, data}` 取出 id。⚠️ partner 回的是 `data.id`，**不是** `data.term_id` */
function pickId(body: unknown): number {
  const b = body as Record<string, unknown>
  const d = (b?.data ?? b) as Record<string, unknown>
  const v = Number(d?.id)
  return Number.isFinite(v) && v > 0 ? v : 0
}

async function createPartner(request: APIRequestContext, nonce: string, name?: string): Promise<number> {
  const stamp = Date.now()
  const res = await request.post(`${BASE_URL}/wp-json/power-shop/profit-partners`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: { name: name ?? `E2EUI${stamp}`, slug: (name ?? `e2eui${stamp}`).toLowerCase(), password: 'e2epass12345' },
  })
  expect(res.ok(), `建立夥伴失敗 ${res.status()}: ${await res.text()}`).toBeTruthy()
  const id = pickId(await res.json())
  expect(id, '夥伴 id 應為正整數').toBeGreaterThan(0)
  return id
}

async function createShop(
  request: APIRequestContext,
  nonce: string,
  payload: Record<string, unknown>
): Promise<number> {
  const res = await request.post(`${BASE_URL}/wp-json/power-shop/profit-shops`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  })
  expect(res.ok(), `建立賣場失敗 ${res.status()}: ${await res.text()}`).toBeTruthy()
  const id = pickId(await res.json())
  expect(id, '賣場 id 應為正整數').toBeGreaterThan(0)
  return id
}

/** 在列表頁用關鍵字篩選（client-side useMemo 過濾） */
async function filterList(page: Page, keyword: string): Promise<void> {
  const search = page.locator('input#search')
  await search.waitFor({ state: 'visible', timeout: 30_000 })
  await search.fill(keyword)
  await page.locator('button:has-text("篩選")').first().click()
  await page.waitForTimeout(1200)
}

/** 進入 SPA 某個 hash route，等到列表或空狀態出現 */
async function gotoList(page: Page, hash: string): Promise<void> {
  await page.goto(`${SPA}#${hash}`)
  await page.locator('.ant-table, .ant-empty').first().waitFor({ state: 'visible', timeout: 45_000 })
}

function getNonce(): string {
  return fs.readFileSync(path.resolve(import.meta.dirname, '../.auth/nonce.txt'), 'utf-8').trim()
}

test.describe('分潤賣場 admin SPA', () => {
  // 🔴 見檔頭：清掉 config 的空 X-WP-Nonce，否則 SPA 所有 REST 查詢都會 403
  test.use({ extraHTTPHeaders: {} })

  let nonce: string
  let partnerTermId: number
  const createdShopIds: number[] = []

  test.beforeAll(async ({ browser }) => {
    nonce = getNonce()
    const ctx = await browser.newContext()
    partnerTermId = await createPartner(ctx.request, nonce)
    await ctx.close()
  })

  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext()
    for (const id of createdShopIds) {
      await ctx.request
        .delete(`${BASE_URL}/wp-json/power-shop/profit-shops/${id}`, { headers: { 'X-WP-Nonce': nonce } })
        .catch(() => undefined)
    }
    await ctx.close()
  })


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


  /**
   * Rule: 前端（即時）- 輸入時延遲驗證，且編輯自己時不與自己衝突
   *
   * ⚠️ slug 欄位**沒有 id**，只有 placeholder="my-shop"（實地 dump 確認）。
   */
  test('建立頁的永久連結欄位會即時回報衝突', async ({ page }) => {
    const stamp = Date.now()
    const takenSlug = `ui-taken-${stamp}`
    createdShopIds.push(
      await createShop(page.request, nonce, {
        title: `UITAKEN${stamp}`, slug: takenSlug, status: 'publish', mode: 'page',
        partner_term_id: partnerTermId, rate: 10, items: [], settings: {},
      })
    )

    await page.goto(`${SPA}#/profit-shop/create`)
    const slugInput = page.locator('input[placeholder="my-shop"]')
    await slugInput.waitFor({ state: 'visible', timeout: 45_000 })
    await slugInput.fill(takenSlug)

    await expect(
      page.locator('.ant-alert').filter({ hasText: /衝突|已被|不可用|已存在|重複/ }).first()
    ).toBeVisible({ timeout: 20_000 })
  })

  /**
   * Rule: 後置（列表）- 夥伴列表可依關鍵字與圖片有無篩選
   *
   * client-side useMemo 過濾（admin-list-ui.rule.md 路線 B），無 server round-trip。
   */
  test('夥伴列表可依關鍵字篩選', async ({ page }) => {
    const marker = `UIPARTNER${Date.now()}`
    await createPartner(page.request, nonce, marker)

    await gotoList(page, '/profit-partner')

    // 篩不存在的關鍵字 → 不應出現
    await filterList(page, 'ZZZNOSUCHPARTNERZZZ')
    await expect(page.locator('.ant-table-row', { hasText: marker })).toHaveCount(0)

    // 篩真正的關鍵字 → 應出現
    await filterList(page, marker)
    await expect(page.locator('.ant-table-row', { hasText: marker })).toHaveCount(1, { timeout: 15_000 })
  })
})
