import { useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { api, errorMessage, query, type Page } from './api'
import { RecordPicker } from './RecordPicker'
import { Banner, Dialog, Field, Heading, Pagination, Table, button, currency, display, input, primary, section, useData } from './workspace-ui'

type OrderLine = {
  id: number
  product_id: number
  product_name: string
  product_description?: string
  sku: string
  unit: string
  quantity: string
  processed_quantity: string
  supplier_returned_quantity: string
  unit_price: string
}
type Order = {
  id: number
  reference: string
  order_date: string
  status: string
  currency: string
  total: string
  subtotal: string
  discount: string
  additional_cost: string
  payment_method?: string
  notes?: string
  supplier_id?: number
  supplier_name?: string
  items?: OrderLine[]
  operations?: { id: number; type: string; reason: string; created_at: string }[]
}
type DraftLine = { key: string; product_id: number | null; quantity: string; unit_price: string }
type Action = 'submit' | 'receive' | 'return' | 'supplier_return' | 'cancel'

const labels: Record<Action, string> = { submit: 'Mark pending', receive: 'Receive stock', return: 'Return stock', supplier_return: 'Return to supplier', cancel: 'Cancel order' }
const localDate = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10)
}

export function Orders({ base, code, type, privileged }: { base: string; code: string; type: 'purchases' | 'sales'; privileged: boolean }) {
  const params = new URLSearchParams(location.search)
  const starter = Number(params.get('product')) || null
  const initialSupplier = Number(params.get('supplier')) || null
  const sale = type === 'sales'
  const resource = sale ? 'sale' : 'purchase'
  const url = base + '/' + type
  const [page, setPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [filter, setFilter] = useState({ search: '', from: '', to: '', status: '' })
  const [supplierFilter, setSupplierFilter] = useState(initialSupplier)
  const [creating, setCreating] = useState((starter && !sale) || params.has('new') ? crypto.randomUUID() : '')
  const [selected, setSelected] = useState<number | null>(Number(params.get('id')) || null)
  const [operation, setOperation] = useState<{ action: Action; key: string } | null>(null)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const listing = useData<Page<Order>>(url + query({ ...filter, page, supplier_id: supplierFilter ?? undefined }), version)
  const detail = useData<Order>(selected ? url + '/' + selected : null, version)

  function editFilter(name: keyof typeof filter, value: string) {
    setPage(1)
    setFilter(previous => ({ ...previous, [name]: value }))
  }

  async function record(payload: Record<string, unknown>) {
    setPending(true)
    setError('')
    try {
      const created = await api<Order>(url, { ...payload, request_id: creating })
      setCreating('')
      setSelected(created.id)
      setPage(1)
      setVersion(version + 1)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  async function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!operation || !detail.data) return
    const form = new FormData(event.currentTarget)
    const items = detail.data.items?.flatMap(line => {
      const quantity = String(form.get('quantity-' + line.product_id) ?? '')
      return Number(quantity) > 0 ? [{ product_id: line.product_id, quantity }] : []
    })
    setPending(true)
    setError('')
    try {
      await api(url + '/' + selected + '/' + operation.action, {
        request_id: operation.key,
        reason: form.get('reason'),
        ...(operation.action === 'receive' || operation.action === 'return' || operation.action === 'supplier_return' ? { items } : {}),
      })
      setOperation(null)
      setVersion(version + 1)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  const statuses = sale ? ['completed', 'partially_returned', 'returned', 'cancelled'] : ['draft', 'pending', 'partially_received', 'received', 'cancelled']
  const available: Action[] = []
  const status = detail.data?.status
  if (status && privileged) {
    if (status === 'draft') available.push('submit', 'cancel')
    if (status === 'pending') available.push('receive', 'cancel')
    if (status === 'partially_received') available.push('receive', 'cancel')
    if (!sale && detail.data?.items?.some(line => Number(line.processed_quantity) > Number(line.supplier_returned_quantity))) {
      available.push('supplier_return')
    }
    if (status === 'completed' || status === 'partially_returned') available.push('return', 'cancel')
  }

  return <>
    <Heading title={sale ? 'Sales' : 'Purchase orders'} description={sale ? 'Record completed sales and review returns or cancellations.' : 'Order products from suppliers and record goods as they arrive.'}
      actions={<button className={'inline-flex items-center gap-2 ' + primary} onClick={() => { setError(''); setCreating(crypto.randomUUID()) }}><Plus className="size-4" />New {resource}</button>} />
    <div className="flex flex-wrap items-end gap-3">
      <Field title="Reference number"><input className={input} type="number" min="1" value={filter.search} onChange={event => editFilter('search', event.target.value)} /></Field>
      <Field title="From"><input className={input} type="date" value={filter.from} onChange={event => editFilter('from', event.target.value)} /></Field>
      <Field title="To"><input className={input} type="date" value={filter.to} min={filter.from} onChange={event => editFilter('to', event.target.value)} /></Field>
      <Field title="Status"><select className={input} value={filter.status} onChange={event => editFilter('status', event.target.value)}>
        <option value="">All statuses</option>{statuses.map(state => <option value={state} key={state}>{state.replaceAll('_', ' ')}</option>)}
      </select></Field>
      {supplierFilter !== null && <button className={button} onClick={() => { setSupplierFilter(null); setPage(1) }}>Clear supplier filter</button>}
    </div>
    <Banner error={listing.error} />
    {!listing.error && <div className={section}>
      <Table items={listing.data?.data} columns={[
        { title: 'Reference', render: order => <button className="font-semibold text-blue-700 hover:underline dark:text-blue-300" onClick={() => { setError(''); setSelected(order.id) }}>{order.reference}</button> },
        { title: 'Date', render: order => order.order_date },
        ...(!sale ? [{ title: 'Supplier', render: (order: Order) => display(order.supplier_name) }] : []),
        { title: 'Status', render: order => <span className="capitalize">{display(order.status)}</span> },
        { title: 'Total', render: order => currency(order.total, order.currency) },
      ]} empty={'No ' + type + ' match these filters.'} />
      <Pagination result={listing.data} navigate={setPage} />
    </div>}
    {creating && <Dialog title={'New ' + resource} close={() => { if (!pending) setCreating('') }}>
      <OrderForm base={base} sale={sale} code={code} starter={starter} error={error} pending={pending} onSave={record} />
    </Dialog>}
    {selected && !operation && <Dialog title={detail.data?.reference ?? 'Order details'} close={() => { setSelected(null); setError('') }}>
      <Banner error={detail.error} />
      {!detail.data && !detail.error && <p role="status">Loading…</p>}
      {detail.data && <div className="grid gap-4">
        <p className="text-sm">{detail.data.order_date} <span className="ml-2 font-semibold capitalize">{display(detail.data.status)}</span></p>
        {!sale && <p className="text-sm">{display(detail.data.supplier_name)}</p>}
        <Table items={detail.data.items} columns={[
          { title: 'Product', render: line => <div>{line.product_name}<div className="text-xs opacity-70">{line.sku}</div>{line.product_description && <div className="max-w-xs text-xs opacity-75">{line.product_description}</div>}</div> },
          { title: 'Quantity', render: line => line.quantity },
          { title: sale ? 'Returned' : 'Received', render: line => line.processed_quantity },
          ...(!sale ? [{ title: 'Returned', render: (line: OrderLine) => line.supplier_returned_quantity }] : []),
          { title: 'Unit price', render: line => currency(line.unit_price, detail.data!.currency) },
        ]} />
        <dl className="grid grid-cols-2 gap-2 text-sm">
          <dt>Subtotal</dt><dd>{currency(detail.data.subtotal, detail.data.currency)}</dd>
          <dt>Discount</dt><dd>{currency(detail.data.discount, detail.data.currency)}</dd>
          {!sale && <><dt>Additional cost</dt><dd>{currency(detail.data.additional_cost, detail.data.currency)}</dd></>}
          <dt className="font-bold">Total</dt><dd className="font-bold">{currency(detail.data.total, detail.data.currency)}</dd>
          {sale && <><dt>Payment method</dt><dd>{display(detail.data.payment_method)}</dd></>}
        </dl>
        {detail.data.notes && <p className="whitespace-pre-wrap text-sm">{detail.data.notes}</p>}
        {available.length > 0 && <div className="flex flex-wrap gap-2">
          {available.map(action => <button type="button" className={action === 'cancel' ? button : primary} key={action} onClick={() => { setError(''); setOperation({ action, key: crypto.randomUUID() }) }}>{labels[action]}</button>)}
        </div>}
        {!!detail.data.operations?.length && <>
          <h3 className="font-bold">Order history</h3>
          <Table items={detail.data.operations} columns={[
            { title: 'Time', render: event => event.created_at },
            { title: 'Action', render: event => <span className="capitalize">{display(event.type)}</span> },
            { title: 'Reason', render: event => event.reason },
          ]} />
        </>}
      </div>}
    </Dialog>}
    {operation && detail.data && <Dialog title={labels[operation.action]} close={() => { if (!pending) setOperation(null) }}>
      <form className="grid gap-4" onSubmit={apply}>
        <Banner error={error} />
        {operation.action === 'cancel' && <p>{sale ? 'The remaining sold quantities will be restocked and this sale will stay in your records.' : 'The order will be closed and its details will remain in your purchase history.'}</p>}
        {(operation.action === 'return' || operation.action === 'receive' || operation.action === 'supplier_return') && detail.data.items?.map(line => {
          const outstanding = operation.action === 'supplier_return'
            ? Number(line.processed_quantity) - Number(line.supplier_returned_quantity)
            : Number(line.quantity) - Number(line.processed_quantity)
          const remaining = Math.round(outstanding * 1000) / 1000
          return remaining > 0 ? <Field key={line.id} title={line.product_name + ' · up to ' + remaining + ' ' + line.unit}>
            <input className={input} type="number" min="0" max={remaining} step="0.001" name={'quantity-' + line.product_id} />
          </Field> : null
        })}
        <Field title="Reason"><textarea className={input} name="reason" required maxLength={1000} /></Field>
        <button className={primary} type="submit" disabled={pending}>{pending ? 'Saving…' : labels[operation.action]}</button>
      </form>
    </Dialog>}
  </>
}

function OrderForm({ base, sale, code, starter, error, pending, onSave }: {
  base: string; sale: boolean; code: string; starter: number | null; error: string; pending: boolean; onSave: (data: Record<string, unknown>) => void
}) {
  const [supplier, setSupplier] = useState<number | null>(null)
  const [lines, setLines] = useState<DraftLine[]>([{ key: crypto.randomUUID(), product_id: starter, quantity: '1', unit_price: '' }])
  const initial = useData<{ purchase_cost: string }>(starter && !sale ? base + '/products/' + starter : null)

  function change(key: string, next: Partial<DraftLine>) {
    setLines(previous => previous.map(line => line.key === key ? { ...line, ...next } : line))
  }

  function price(line: DraftLine) {
    return line.unit_price || (line.product_id === starter && initial.data ? initial.data.purchase_cost : '')
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget))
    onSave({
      ...values,
      ...(sale ? {} : { supplier_id: supplier }),
      items: lines.map(line => ({ product_id: line.product_id, quantity: line.quantity, unit_price: price(line) })),
    })
  }

  return <form className="grid gap-5" onSubmit={submit}>
    <Banner error={error} />
    {!sale && <RecordPicker title="Supplier" base={base} resource="suppliers" value={supplier} onChange={setSupplier} />}
    <div className="grid grid-cols-2 gap-3">
      <Field title={sale ? 'Sale date' : 'Purchase date'}><input name="order_date" className={input} type="date" defaultValue={localDate()} required /></Field>
      {sale ? <Field title="Payment method"><select name="payment_method" className={input} required><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="credit">Credit</option><option value="other">Other</option></select></Field>
        : <Field title="Status"><select name="status" className={input} defaultValue="pending"><option value="draft">Draft</option><option value="pending">Pending</option></select></Field>}
    </div>
    <div className="grid gap-3">
      <h3 className="font-semibold">Line items</h3>
      {lines.map(line => <div className="grid gap-2 rounded-md border border-current/20 p-3" key={line.key}>
        <RecordPicker title="Product" base={base} resource="products" value={line.product_id}
          onChange={(id, row) => change(line.key, { product_id: id, unit_price: String((sale ? row?.selling_price : row?.purchase_cost) ?? '') })} />
        <div className="grid grid-cols-[1fr_1fr_auto] items-end gap-2">
          <Field title="Quantity"><input className={input} type="number" min="0.001" step="0.001" value={line.quantity} required onChange={event => change(line.key, { quantity: event.target.value })} /></Field>
          <Field title="Unit price"><input className={input} type="number" min="0" step="0.0001" value={price(line)} required onChange={event => change(line.key, { unit_price: event.target.value })} /></Field>
          <button className={button} type="button" aria-label="Remove line item" disabled={lines.length === 1} onClick={() => setLines(previous => previous.filter(entry => entry.key !== line.key))}><Trash2 className="size-4" /></button>
        </div>
      </div>)}
      <button className={button} type="button" disabled={lines.length === 100} onClick={() => setLines(previous => [...previous, { key: crypto.randomUUID(), product_id: null, quantity: '1', unit_price: '' }])}>Add line item</button>
    </div>
    <div className="grid grid-cols-2 gap-3">
      <Field title="Discount"><input className={input} name="discount" type="number" min="0" step="0.0001" defaultValue="0" /></Field>
      {!sale && <Field title="Additional cost"><input className={input} name="additional_cost" type="number" min="0" step="0.0001" defaultValue="0" /></Field>}
    </div>
    <p className="text-sm">Items subtotal <strong>{currency(lines.reduce((sum, line) => sum + Number(line.quantity) * Number(price(line)), 0), code)}</strong></p>
    <Field title="Notes"><textarea className={input} maxLength={5000} name="notes" /></Field>
    <button className={primary} type="submit" disabled={pending}>{pending ? 'Saving…' : sale ? 'Record sale' : 'Save purchase'}</button>
  </form>
}
