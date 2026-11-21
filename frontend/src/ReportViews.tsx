import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'
import { Boxes, ClipboardList, PackageMinus, ShoppingBag, TrendingUp, Wallet } from 'lucide-react'
import { query, type Page } from './api'
import { Banner, Field, Heading, Pagination, Table, button, currency, display, input, primary, section, useData } from './workspace-ui'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

type Row = Record<string, string | number | null>
type DashboardData = {
  metrics: Row
  sales: Row
  purchases: Row
  sales_trend: Row[]
  purchase_trend: Row[]
  stock_trend: Row[]
  top_products: Row[]
  stock_by_category: Row[]
  recent_sales: Row[]
  recent_purchases: Row[]
  recent_movements: Row[]
}

const reportOptions = {
  inventory: 'Current inventory',
  valuation: 'Stock valuation',
  low_stock: 'Low-stock products',
  out_of_stock: 'Out-of-stock products',
  movements: 'Stock movement history',
  adjustments: 'Stock adjustments',
  sales_by_date: 'Sales by date',
  sales_by_product: 'Sales by product',
  sales_by_category: 'Sales by category',
  sales_totals: 'Sales totals',
  purchases_by_supplier: 'Purchases by supplier',
  purchases_by_date: 'Purchases by date',
  purchase_totals: 'Purchase totals',
  purchasing_history: 'Product purchasing history',
}
type ReportType = keyof typeof reportOptions

const moneyColumns = new Set(['stock_value', 'average_cost', 'unit_cost', 'unit_price', 'total', 'revenue', 'cost_of_goods', 'gross_profit', 'expenditure'])
const titles: Record<string, string> = {
  name: 'Product', sku: 'SKU', stock: 'On hand', stock_value: 'Inventory value', day: 'Date', supplier_id: 'Supplier ID',
  product_id: 'Product ID', category_name: 'Category', product_name: 'Product', created_at: 'Time', id: 'ID', purchase_id: 'Purchase',
}
const pretty = (column: string) => titles[column] ?? column[0].toUpperCase() + column.slice(1).replaceAll('_', ' ')

function columnsFor(rows: Row[], code: string) {
  return Object.keys(rows[0] ?? {}).map(key => ({
    title: pretty(key),
    render: (row: Row) => moneyColumns.has(key) ? currency(row[key], code) : display(row[key]),
  }))
}

function Dates({ values, submit, search = false }: {
  values: { from: string; to: string; search?: string }
  submit: (values: { from: string; to: string; search: string }) => void
  search?: boolean
}) {
  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    submit({ from: String(values.get('from') ?? ''), to: String(values.get('to') ?? ''), search: String(values.get('search') ?? '') })
  }
  return <form className="flex flex-wrap items-end gap-3" onSubmit={apply}>
    <Field title="From"><input name="from" className={input} type="date" defaultValue={values.from} /></Field>
    <Field title="To"><input name="to" className={input} type="date" defaultValue={values.to} /></Field>
    {search && <Field title="Search"><input name="search" className={input} type="search" maxLength={160} defaultValue={values.search} /></Field>}
    <button className={button} type="submit">Apply filters</button>
  </form>
}

export function Reports({ base, code }: { base: string; code: string }) {
  const [type, setType] = useState<ReportType>('inventory')
  const [filters, setFilters] = useState({ from: '', to: '', search: '' })
  const [page, setPage] = useState(1)
  const url = base + '/reports/' + type
  const report = useData<Page<Row>>(url + query({ ...filters, page }))
  const rows = report.data?.data ?? []
  return <>
    <Heading title="Reports" description="Review stock balances, purchase expenditure, sales revenue, and gross profit."
      actions={<div className="flex gap-2 print:hidden">
        <a className={button} href={'/api/v1' + url + query({ ...filters, format: 'csv' })}>Download CSV</a>
        <button className={button} type="button" onClick={() => window.print()}>Print</button>
      </div>} />
    <div className="flex flex-wrap items-end gap-3 print:hidden">
      <Field title="Report"><select className={input} value={type} onChange={event => { setType(event.target.value as ReportType); setPage(1) }}>
        {Object.entries(reportOptions).map(([type, label]) => <option key={type} value={type}>{label}</option>)}
      </select></Field>
      <Dates values={filters} search submit={values => { setFilters(values); setPage(1) }} />
    </div>
    <div className="hidden print:block"><h2>{reportOptions[type]}</h2><p>{filters.from || 'Earliest'} through {filters.to || 'Latest'}</p></div>
    <Banner error={report.error} />
    {!report.error && <section className={section}><Table items={report.data?.data} columns={columnsFor(rows, code)} />
      <div className="print:hidden"><Pagination result={report.data} navigate={setPage} /></div>
    </section>}
  </>
}

function BarChart({ title, rows, label, series, dark }: {
  title: string; rows: Row[]; label: string; series: { key: string; name: string; color: string }[]; dark: boolean
}) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const [expanded, setExpanded] = useState(false)
  useEffect(() => {
    if (!canvas.current || !rows.length) return
    const text = dark ? '#dbeafe' : '#334155'
    const grid = dark ? '#334155' : '#dbe4f1'
    const chart = new Chart(canvas.current, {
      type: 'bar',
      data: {
        labels: rows.map(row => String(row[label] ?? 'Uncategorized')),
        datasets: series.map(item => ({
          label: item.name, backgroundColor: item.color, data: rows.map(row => Number(row[item.key] ?? 0)),
          borderRadius: 2, maxBarThickness: 28,
        })),
      },
      options: {
        animation: false,
        maintainAspectRatio: false,
        scales: {
          x: { ticks: { color: text, maxTicksLimit: 8 }, grid: { display: false } },
          y: { beginAtZero: true, ticks: { color: text, maxTicksLimit: 5 }, grid: { color: grid } },
        },
      },
    })
    return () => chart.destroy()
  }, [rows, label, series, dark])
  return <section className={section + ' min-w-0 p-4'}>
    <div className="flex flex-wrap items-start justify-between gap-3">
      <h2 className="font-semibold">{title}</h2>
      {rows.length > 0 && <button type="button" className="text-sm font-semibold underline" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}>{expanded ? 'Hide data' : 'View data'}</button>}
    </div>
    {rows.length ? <>
      <div className="mt-4 h-52"><canvas ref={canvas} role="img" aria-label={title} /></div>
      <div className="mt-3 flex flex-wrap gap-4 text-xs">{series.map(item => <span className="inline-flex items-center gap-2" key={item.key}><span className="size-2.5 rounded-sm" style={{ backgroundColor: item.color }} />{item.name}</span>)}</div>
      {expanded && <div className="mt-3 max-h-56 overflow-y-auto"><Table items={rows} columns={[
        { title: pretty(label), render: row => display(row[label]) },
        ...series.map(item => ({ title: item.name, render: (row: Row) => display(row[item.key]) })),
      ]} /></div>}
    </> : <p className="mt-6 text-sm opacity-70">No activity in this view.</p>}
  </section>
}

export function Dashboard({ base, code, dark, business }: { base: string; code: string; dark: boolean; business: string }) {
  const [filters, setFilters] = useState({ from: '', to: '' })
  const response = useData<DashboardData>(base + '/dashboard' + query(filters))
  const data = response.data
  const blue = dark ? '#60a5fa' : '#2563eb'
  const teal = dark ? '#5eead4' : '#0f766e'
  const indicators = data ? [
    { label: 'Active products', value: data.metrics.product_count, icon: Boxes, link: '/workspace/products' },
    { label: 'Stock units', value: data.metrics.stock_units, icon: ClipboardList, link: '/workspace/inventory' },
    { label: 'Inventory value', value: currency(data.metrics.inventory_value, code), icon: Wallet, link: '/workspace/reports' },
    { label: 'Low stock', value: data.metrics.low_stock, icon: PackageMinus, link: '/workspace/low-stock' },
    { label: 'Out of stock', value: data.metrics.out_of_stock, icon: PackageMinus, link: '/workspace/low-stock' },
    { label: 'Sales revenue', value: currency(data.sales.revenue, code), icon: TrendingUp, link: '/workspace/sales' },
    { label: 'Purchase expenditure', value: currency(data.purchases.expenditure, code), icon: ShoppingBag, link: '/workspace/purchases' },
    { label: 'Gross profit', value: currency(data.sales.gross_profit, code), icon: TrendingUp, link: '/workspace/reports' },
  ] : []
  return <>
    <Heading title="Dashboard" description={business + ' · inventory and trading activity'} actions={<a className={primary} href="/workspace/sales?new=1">Record a sale</a>} />
    <div>
      <Dates values={filters} submit={({ from, to }) => setFilters({ from, to })} />
      {!filters.from && !filters.to && <p className="mt-2 text-sm opacity-75">Trading activity covers the last 30 days. Stock figures show current balances.</p>}
    </div>
    <Banner error={response.error} />
    {!response.error && !data && <p role="status">Loading dashboard…</p>}
    {data && <>
      <div className={section + ' grid grid-cols-2 sm:grid-cols-4'}>
        {indicators.map(item => <a key={item.label} href={item.link} className="min-w-0 border-b border-r border-current/10 p-4 hover:bg-blue-500/5">
          <item.icon className="mb-3 size-4 text-blue-600 dark:text-blue-300" aria-hidden="true" />
          <p className="text-sm opacity-75">{item.label}</p><p className="mt-1 break-words text-xl font-bold">{item.value}</p>
        </a>)}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <BarChart title="Sales trend" rows={data.sales_trend} label="day" series={[{ key: 'revenue', name: 'Revenue', color: blue }]} dark={dark} />
        <BarChart title="Purchase trend" rows={data.purchase_trend} label="day" series={[{ key: 'expenditure', name: 'Expenditure', color: teal }]} dark={dark} />
        <BarChart title="Stock movement trend" rows={data.stock_trend} label="day" series={[{ key: 'stock_in', name: 'Stock in', color: blue }, { key: 'stock_out', name: 'Stock out', color: teal }]} dark={dark} />
        <BarChart title="Top-selling products" rows={data.top_products} label="product_name" series={[{ key: 'units', name: 'Net units sold', color: blue }]} dark={dark} />
        <BarChart title="Inventory value by category" rows={data.stock_by_category} label="category" series={[{ key: 'stock_value', name: 'Inventory value', color: teal }]} dark={dark} />
        <section className={section}><h2 className="px-4 pt-4 font-semibold">Recent stock movements</h2>
          <Table items={data.recent_movements} columns={[
            { title: 'Product', render: row => display(row.name) },
            { title: 'Change', render: row => display(row.quantity) },
            { title: 'Direction', render: row => <span className="capitalize">{display(row.type)}</span> },
          ]} />
        </section>
        {(['sales', 'purchases'] as const).map(type => <section className={section} key={type}><h2 className="px-4 pt-4 font-semibold">Recent {type}</h2>
          <Table items={type === 'sales' ? data.recent_sales : data.recent_purchases} columns={[
            { title: 'Reference', render: row => <a className="font-semibold underline" href={'/workspace/' + type + '?id=' + row.id}>{type === 'sales' ? 'SO-' : 'PO-'}{row.id}</a> },
            { title: 'Date', render: row => display(row.order_date) },
            { title: 'Total', render: row => currency(row.total, code) },
            { title: 'Status', render: row => <span className="capitalize">{display(row.status)}</span> },
          ]} />
        </section>)}
      </div>
    </>}
  </>
}
