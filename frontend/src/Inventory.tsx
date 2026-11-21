import { useState, type FormEvent } from 'react'
import { api, errorMessage, query, type Page } from './api'
import { Banner, Dialog, Field, Heading, Pagination, Table, display, input, primary, section, useData } from './workspace-ui'
import { RecordPicker } from './RecordPicker'

type Movement = {
  id: number
  product_id: number
  product_name: string
  sku: string
  type: string
  quantity: string
  previous_stock: string
  resulting_stock: string
  user_id: number
  reference: string
  reason: string
  created_at: string
}

export function Inventory({ base, canEdit }: { base: string; canEdit: boolean }) {
  const [page, setPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [product, setProduct] = useState<number | null>(Number(new URLSearchParams(location.search).get('product')) || null)
  const [editing, setEditing] = useState<string | null>(null)
  const [selected, setSelected] = useState<number | null>(null)
  const [type, setType] = useState('received')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const result = useData<Page<Movement>>(base + '/movements' + query({ page, product_id: product ?? undefined }), version)

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget))
    setError('')
    setPending(true)
    try {
      await api(base + '/movements', { ...values, product_id: selected, reference: editing, type })
      setEditing(null)
      setPage(1)
      setVersion(version + 1)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  return <>
    <Heading title="Stock movements" description="Review every stock change and its resulting balance."
      actions={canEdit && <button className={primary} onClick={() => { setSelected(product); setType('received'); setError(''); setEditing(crypto.randomUUID()) }}>Record movement</button>} />
    <div className="max-w-sm"><RecordPicker title="Filter by product" base={base} resource="products" required={false} value={product} onChange={id => { setProduct(id); setPage(1) }} /></div>
    <Banner error={result.error} />
    {!result.error && <div className={section}>
      <Table items={result.data?.data} empty="No stock movements have been recorded for this view." columns={[
        { title: 'Time', render: row => row.created_at },
        { title: 'Product', render: row => <div>{row.product_name}<div className="text-xs opacity-70">{row.sku}</div></div> },
        { title: 'Movement', render: row => <span className="capitalize">{display(row.type)}</span> },
        { title: 'Quantity', render: row => <span className={Number(row.quantity) < 0 ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300'}>{Number(row.quantity) > 0 ? '+' : ''}{row.quantity}</span> },
        { title: 'Before', render: row => row.previous_stock },
        { title: 'After', render: row => row.resulting_stock },
        { title: 'Reason', render: row => display(row.reason) },
        { title: 'User', render: row => row.user_id },
      ]} />
      <Pagination result={result.data} navigate={setPage} />
    </div>}
    {editing && <Dialog title="Record movement" close={() => { if (!pending) setEditing(null) }}>
      <form className="grid gap-4" onSubmit={save}>
        <Banner error={error} />
        <RecordPicker title="Product" base={base} resource="products" value={selected} onChange={setSelected} />
        <Field title="Movement type"><select value={type} onChange={event => setType(event.target.value)} className={input}>
          <option value="received">Stock received</option><option value="adjustment">Adjustment</option><option value="damaged">Damaged goods</option>
          <option value="supplier_return">Supplier return</option><option value="customer_return">Customer return without a sale record</option>
        </select></Field>
        <Field title="Quantity change"><input className={input} type="number" name="quantity" step="0.001" required /></Field>
        <p className="-mt-2 text-sm opacity-75">Enter a negative quantity to remove stock and a positive quantity to add it.</p>
        <Field title="Reason"><textarea className={input} name="reason" required maxLength={1000} /></Field>
        <button className={primary} type="submit" disabled={pending}>{pending ? 'Recording…' : 'Record movement'}</button>
      </form>
    </Dialog>}
  </>
}
