/**
 * 賣場商品覆寫 items 契約 API 測試（BUG-A 修補後契約鎖）
 * Endpoint: POST/PUT/GET /power-shop/profit-shops[/{id}]
 *
 * 背景（BUG-A）：
 * 前端曾長期送出後端從未定義的扁平欄位 `price_override` / `variation_id`，
 * 但 `ProfitShopHydrator::build_override_item()` 只讀巢狀 `override` / `variations`，
 * 導致使用者設定的價格覆寫在儲存後被靜默丟棄。
 *
 * Phase 1 已將前端改為送出巢狀 items 契約，PHP 端零修改（本來就只讀巢狀），
 * 並補了 PHP Unit / IT 測試鎖住行為
 * （見 tests/Unit/Application/UseCase/Shop/Support/ProfitShopHydratorTest.php、
 *   tests/Integration/Infrastructure/Rest/ProfitShopItemsRoundTripTest.php）。
 *
 * 本檔在 HTTP 層再鎖一道：
 *   1. 巢狀 items 正確持久化（正向 round-trip）
 *   2. 扁平 items（舊契約）被完全忽略（回歸鎖，證明舊契約已死）
 *   3. 兩條延伸負向 case（sale > regular / product 不存在）
 *
 * 對應規格：specs/features/profit-shop/save-shop-item-overrides.feature
 * 參考既有 pattern：tests/e2e/03-products/product-list.spec.ts（API-level，非 UI 驅動）
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8890'

function getNonce(): string {
  return fs.readFileSync(path.resolve(import.meta.dirname, '../.auth/nonce.txt'), 'utf-8').trim()
}

type TProfitShopPayload = {
  title: string
  slug: string
  status: 'draft' | 'publish' | 'trash'
  mode: 'page' | 'shortcode'
  partner_term_id: number
  rate: number
  items: Record<string, unknown>[]
  settings: Record<string, unknown>
}

test.describe('賣場商品覆寫 items 契約 — PUT/GET /power-shop/profit-shops/{id}', () => {
  let nonce: string
  let partnerTermId: number
  let productIdA: number
  const createdShopIds: number[] = []
  const createdProductIds: number[] = []

  test.beforeAll(async ({ request }) => {
    nonce = getNonce()

    // 建立獨立測試商品（可識別前綴 [E2E-PS]，測試結束後於 afterAll 清理）
    const productRes = await apiPost(request, 'wc/v3/products', {
      name: '[E2E-PS] Items Contract Lock Product',
      type: 'simple',
      regular_price: '999',
      status: 'publish',
    })
    expect(productRes.status(), '測試前置：建立測試商品應成功').toBe(201)
    const product = await productRes.json()
    productIdA = product.id
    createdProductIds.push(productIdA)

    // 建立獨立測試 Partner（POST /profit-partners 為 admin_only=manage_options，
    // e2e 以 admin 帳號登入取得的 nonce 具備該權限）
    const partnerRes = await apiPost(request, 'power-shop/profit-partners', {
      name: '[E2E-PS] Items Contract Lock Partner',
      slug: `e2e-ps-partner-${Date.now()}`,
      password: 'E2ePs-Passw0rd!',
    })
    expect(partnerRes.status(), '測試前置：建立測試 Partner 應成功').toBe(201)
    const partnerBody = await partnerRes.json()
    partnerTermId = partnerBody.data.id
  })

  test.afterAll(async ({ request }) => {
    // 順序很重要：PartnerRepository::is_in_use() 只查 publish/draft（不含 trash），
    // 所以必須先把賣場 trash 掉，Partner 的 is_in_use 預檢才會通過。
    for (const id of createdShopIds) {
      await request.delete(`${BASE_URL}/wp-json/power-shop/profit-shops/${id}`, {
        headers: { 'X-WP-Nonce': nonce },
      })
    }
    if (partnerTermId) {
      await request.delete(`${BASE_URL}/wp-json/power-shop/profit-partners/${partnerTermId}`, {
        headers: { 'X-WP-Nonce': nonce },
      })
    }
    for (const id of createdProductIds) {
      await request.delete(`${BASE_URL}/wp-json/wc/v3/products/${id}?force=true`, {
        headers: { 'X-WP-Nonce': nonce },
      })
    }
  })

  async function apiPost(request: APIRequestContext, endpoint: string, data: Record<string, unknown>) {
    return request.post(`${BASE_URL}/wp-json/${endpoint}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data,
    })
  }
  async function apiPut(request: APIRequestContext, endpoint: string, data: Record<string, unknown>) {
    return request.put(`${BASE_URL}/wp-json/${endpoint}`, {
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      data,
    })
  }
  async function apiGet(request: APIRequestContext, endpoint: string) {
    return request.get(`${BASE_URL}/wp-json/${endpoint}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  }

  /** 建立一個 draft 賣場（items 空陣列），回傳新 ID 與 slug；slug 帶時間戳避免衝突 */
  async function createShop(request: APIRequestContext, slugPrefix: string): Promise<{ id: number; slug: string }> {
    const slug = `${slugPrefix}-${Date.now()}`
    const res = await apiPost(request, 'power-shop/profit-shops', {
      title: '[E2E-PS] Items Contract Shop',
      slug,
      status: 'draft',
      mode: 'page',
      partner_term_id: partnerTermId,
      rate: 10,
      items: [],
      settings: {},
    } satisfies TProfitShopPayload)
    expect(res.status(), '測試前置：建立賣場應成功（201）').toBe(201)
    const body = await res.json()
    const id = body.data.id as number
    createdShopIds.push(id)
    return { id, slug }
  }

  // ── 正向：巢狀 items round-trip ──────────────────────────────────

  test('正向：PUT 巢狀 items → GET 應原樣還原 override 與 inflated_count', async ({ request }) => {
    const { id, slug } = await createShop(request, 'e2e-ps-nested')

    const putRes = await apiPut(request, `power-shop/profit-shops/${id}`, {
      title: '[E2E-PS] Items Contract Shop',
      slug,
      status: 'draft',
      mode: 'page',
      partner_term_id: partnerTermId,
      rate: 10,
      items: [
        {
          product_id: productIdA,
          inflated_count: 30,
          override: {
            regular_price: '50000',
            sale_price: '49900',
            signup_fee: null,
          },
        },
      ],
      settings: {},
    } satisfies TProfitShopPayload)

    // ---- 防空斷言：先確認 PUT 成功且真的寫入 1 筆，才驗欄位值 ----
    expect(putRes.status()).toBe(200)
    const putBody = await putRes.json()
    expect(Array.isArray(putBody.data.items)).toBe(true)
    expect(putBody.data.items.length).toBe(1)

    const getRes = await apiGet(request, `power-shop/profit-shops/${id}`)
    expect(getRes.status()).toBe(200)
    const getBody = await getRes.json()
    expect(Array.isArray(getBody.data.items)).toBe(true)
    expect(getBody.data.items.length).toBe(1)

    const item = getBody.data.items[0]
    expect(item.product_id).toBe(productIdA)
    expect(item.inflated_count).toBe(30)
    expect(item.override.regular_price).toBe('50000')
    expect(item.override.sale_price).toBe('49900')
    expect(item.override.signup_fee).toBeNull()
  })

  // ── 回歸鎖：扁平 items（舊契約）必須被完全忽略 ──────────────────

  test('回歸鎖：PUT 扁平 items（price_override/variation_id）→ GET 價格未被寫入（舊契約已死）', async ({ request }) => {
    const { id, slug } = await createShop(request, 'e2e-ps-flat')

    const putRes = await apiPut(request, `power-shop/profit-shops/${id}`, {
      title: '[E2E-PS] Items Contract Shop',
      slug,
      status: 'draft',
      mode: 'page',
      partner_term_id: partnerTermId,
      rate: 10,
      items: [
        {
          product_id: productIdA,
          // 舊版前端扁平契約（BUG-A 成因）：後端應完全忽略這兩個從未定義過的鍵
          price_override: 50000,
          variation_id: 999,
          inflated_count: 5,
        },
      ],
      settings: {},
    } satisfies unknown as TProfitShopPayload)

    // ---- 防空斷言：先確認 PUT 成功且真的寫入 1 筆（product_id 本身仍應正確寫入）----
    expect(putRes.status()).toBe(200)
    const putBody = await putRes.json()
    expect(Array.isArray(putBody.data.items)).toBe(true)
    expect(putBody.data.items.length).toBe(1)
    expect(putBody.data.items[0].product_id).toBe(productIdA)

    const getRes = await apiGet(request, `power-shop/profit-shops/${id}`)
    expect(getRes.status()).toBe(200)
    const getBody = await getRes.json()
    expect(Array.isArray(getBody.data.items)).toBe(true)
    expect(getBody.data.items.length).toBe(1)

    const item = getBody.data.items[0]
    expect(item.product_id).toBe(productIdA)
    // 巢狀合法欄位（inflated_count）仍應正常寫入 —— 證明不是整筆 item 被拒絕，
    // 而是精準只忽略未定義的扁平欄位。
    expect(item.inflated_count).toBe(5)
    expect(item.override.regular_price).toBeNull()
    expect(item.override.sale_price).toBeNull()
    // 注意：api.yml 將 variations 定義為 type: object，但 PHP 空陣列經
    // wp_json_encode 序列化為空陣列時無從區分關聯 / 索引陣列，實際 wire 格式
    // 是 JSON []（而非 {}）。這裡鎖的是實際 HTTP 回應，而非 OpenAPI 型別宣告。
    expect(item.variations).toEqual([])
  })

  // ── 延伸負向 case（save-shop-item-overrides.feature 前置 Rule）──

  test('負向：sale_price 高於 regular_price → 400 validation_failed', async ({ request }) => {
    const { id, slug } = await createShop(request, 'e2e-ps-sale-gt-regular')

    const putRes = await apiPut(request, `power-shop/profit-shops/${id}`, {
      title: '[E2E-PS] Items Contract Shop',
      slug,
      status: 'draft',
      mode: 'page',
      partner_term_id: partnerTermId,
      rate: 10,
      items: [
        {
          product_id: productIdA,
          override: { regular_price: '50000', sale_price: '60000', signup_fee: null },
        },
      ],
      settings: {},
    } satisfies TProfitShopPayload)

    expect(putRes.status()).toBe(400)
    const body = await putRes.json()
    expect(body.code).toBe('validation_failed')
  })

  test('負向：不存在的 product_id → 404 not_found', async ({ request }) => {
    const { id, slug } = await createShop(request, 'e2e-ps-missing-product')
    const nonexistentProductId = 999999

    const putRes = await apiPut(request, `power-shop/profit-shops/${id}`, {
      title: '[E2E-PS] Items Contract Shop',
      slug,
      status: 'draft',
      mode: 'page',
      partner_term_id: partnerTermId,
      rate: 10,
      items: [
        {
          product_id: nonexistentProductId,
          override: { regular_price: '50000', sale_price: null, signup_fee: null },
        },
      ],
      settings: {},
    } satisfies TProfitShopPayload)

    expect(putRes.status()).toBe(404)
    const body = await putRes.json()
    expect(body.code).toBe('not_found')
  })
})
