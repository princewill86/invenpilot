import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { randomUUID } from 'node:crypto'
import { chromium } from 'playwright'

const base = process.env.BASE_URL ?? 'http://localhost:8080'
const screenshots = process.env.SCREENSHOT_DIR
const browser = await chromium.launch()
const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1440, height: 1000 } })
const page = await context.newPage()
page.setDefaultTimeout(12_000)
const errors = []
page.on('pageerror', error => errors.push(error.message))
const run = randomUUID().slice(0, 8)

async function screenshot(name) {
  if (!screenshots) return
  await mkdir(screenshots, { recursive: true })
  await page.evaluate(() => document.fonts.ready)
  await page.screenshot({ path: screenshots + '/' + name + '.png', fullPage: true })
}

async function heading(name) {
  await page.getByRole('heading', { name, exact: true }).first().waitFor()
}

async function navigate(name) {
  await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name, exact: true }).click()
}

const field = (name) => page.getByLabel(name, { exact: true })
const action = (name) => page.getByRole('button', { name, exact: true })

try {
  await page.goto(base)
  await page.setViewportSize({ width: 320, height: 680 })
  const publicToggle = action('Toggle site navigation')
  const publicNav = page.getByRole('navigation', { name: 'Main navigation' })
  await publicToggle.click()
  await publicNav.getByRole('link', { name: 'Get started' }).waitFor()
  await page.keyboard.press('Escape')
  await publicNav.waitFor({ state: 'hidden' })
  await publicToggle.click()
  await publicNav.getByRole('link', { name: 'Features' }).click()
  await publicNav.waitFor({ state: 'hidden' })
  await page.goto(base)
  await page.setViewportSize({ width: 1440, height: 1000 })
  await heading('Know what’s in stock. Know what’s next.')
  await screenshot('landing')
  await page.getByRole('link', { name: 'Create a business', exact: true }).first().click()
  await field('Full name').fill('Morgan Lee')
  await field('Business name').fill('Northline Supply')
  await field('Email').fill('morgan-' + run + '@example.com')
  await field('Password').fill('a-long-smoke-test-password')
  await field('Confirm password').fill('a-long-smoke-test-password')
  await page.getByRole('checkbox', { name: /I agree to the Terms of service and Privacy policy/ }).check()
  await action('Create business').click()
  await heading('Dashboard')

  await navigate('Categories')
  await action('Add category').click()
  await field('Name').fill('Workshop essentials')
  await action('Save category').click()
  await action('Workshop essentials').waitFor()

  await navigate('Products')
  await action('Add product').click()
  await field('Name').fill('Metric wrench')
  await field('SKU').fill('TOOLS-100')
  await field('Category').selectOption({ label: 'Workshop essentials' })
  await field('Purchase cost').fill('9.50')
  await field('Selling price').fill('15.00')
  await field('Minimum stock').fill('5')
  await action('Save product').click()
  await action('Metric wrench').waitFor()

  await navigate('Suppliers')
  await action('Add supplier').click()
  await field('Name').fill('Meridian Tools')
  await action('Save supplier').click()
  await action('Meridian Tools').waitFor()

  await navigate('Purchases')
  await action('New purchase').click()
  await field('Supplier').selectOption({ label: 'Meridian Tools' })
  await field('Product').selectOption({ label: 'Metric wrench · TOOLS-100' })
  await field('Quantity').fill('15')
  await action('Save purchase').click()
  await action('Receive stock').click()
  await page.getByLabel(/Metric wrench · up to 15/).fill('10')
  await field('Reason').fill('First delivery')
  await action('Receive stock').click()
  const purchase = page.getByRole('dialog', { name: /^PO-/ })
  await purchase.waitFor()
  await purchase.getByText('partially received', { exact: true }).waitFor()
  await purchase.getByRole('button', { name: 'Close dialog' }).click()

  await navigate('Sales')
  await action('New sale').click()
  await field('Product').selectOption({ label: 'Metric wrench · TOOLS-100' })
  await field('Quantity').fill('3')
  await action('Record sale').click()
  const sale = page.getByRole('dialog', { name: /^SO-/ })
  await sale.waitFor()
  await sale.getByText('completed', { exact: true }).waitFor()
  await action('Return stock').click()
  await page.getByLabel(/Metric wrench · up to 3/).fill('1')
  await field('Reason').fill('Customer return')
  await action('Return stock').click()
  await sale.getByText('partially returned', { exact: true }).waitFor()
  await sale.getByRole('button', { name: 'Close dialog' }).click()

  const racingSales = await page.evaluate(async () => {
    const request = async (path, payload) => {
      const token = decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN=')).slice('XSRF-TOKEN='.length))
      const result = await fetch('/api/v1' + path, {
        method: payload ? 'POST' : 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
        body: payload ? JSON.stringify(payload) : undefined,
      })
      return { status: result.status, data: await result.json() }
    }
    const account = await request('/account')
    const business = '/businesses/' + account.data.businesses[0].id
    const products = await request(business + '/products')
    const product = products.data.data[0]
    const fields = {
      order_date: new Date().toISOString().slice(0, 10), payment_method: 'cash',
      items: [{ product_id: product.id, quantity: '6', unit_price: '15' }],
    }
    const attempts = await Promise.all(
      Array.from({ length: 2 }, () => request(business + '/sales', { ...fields, request_id: crypto.randomUUID() })),
    )
    const sold = attempts.find(attempt => attempt.status === 201)
    if (sold) {
      await request(business + '/sales/' + sold.data.id + '/cancel', {
        request_id: crypto.randomUUID(), reason: 'Reverse concurrency check',
      })
    }
    return attempts.map(attempt => attempt.status).sort()
  })
  assert.deepEqual(racingSales, [201, 422])

  await navigate('Inventory')
  await page.getByRole('row', { name: /Metric wrench.*\b8(?:\.000)? pieces\b/ }).waitFor()
  await navigate('Reports')
  await field('Report').selectOption('sales_totals')
  await page.getByRole('cell', { name: '$30.00', exact: true }).waitFor()
  const downloadEvent = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Download CSV' }).click()
  const download = await downloadEvent
  assert.equal(download.suggestedFilename(), 'sales_totals.csv')
  assert.equal(await download.failure(), null)

  await navigate('Dashboard')
  await page.getByRole('img', { name: 'Sales trend' }).waitFor()
  await screenshot('dashboard')

  await page.setViewportSize({ width: 390, height: 844 })
  await page.locator('.app-header-inner').getByRole('button', { name: 'Toggle workspace navigation' }).waitFor()
  assert.equal(await page.locator('.workspace-sidebar').evaluate(element => element.getBoundingClientRect().height), 0)
  const workspaceNav = page.getByRole('navigation', { name: 'Workspace' })
  await action('Toggle workspace navigation').click()
  await workspaceNav.getByLabel('Current business').waitFor()
  await page.locator('.app-header-inner').click({ position: { x: 2, y: 2 } })
  await workspaceNav.waitFor({ state: 'hidden' })
  await action('Toggle workspace navigation').click()
  await navigate('Products')
  await action('Metric wrench').waitFor()
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
  await action('Switch to dark theme').click()
  await page.waitForFunction(() => document.documentElement.classList.contains('dark'))

  await action('Toggle workspace navigation').click()
  await action('Sign out').click()
  await heading('Sign in')
  await page.goto(base + '/workspace')
  await heading('Sign in to your workspace')
  assert.deepEqual(errors, [])
  console.log('Passed browser workflow for registration, catalog, partial receiving, sales, returns, reports, mobile layout, and logout.')
} finally {
  await browser.close()
}
