import { useState, type FormEvent } from 'react'
import { Plus } from 'lucide-react'
import { api, errorMessage, query, type Page } from './api'
import { RecordPicker } from './RecordPicker'
import { Banner, Dialog, Field, Heading, Pagination, Table, button, currency, display, input, primary, section, useData } from './workspace-ui'

export type CatalogRow = {
  id: number
  name: string
  sku?: string
  barcode?: string
  description?: string
  unit?: string
  category_id?: number
  purchase_cost?: string
  selling_price?: string
  stock?: string
  minimum_stock?: string
  archived_at?: string
  contact_person?: string
  email?: string
  phone?: string
  address?: string
  notes?: string
  image_path?: string
}

type Resource = 'products' | 'categories' | 'suppliers'
type Props = { base: string; code: string; canEdit: boolean; resource: Resource; lowStock?: boolean; inventory?: boolean }

const names = { products: 'product', suppliers: 'supplier', categories: 'category' }
const descriptions = {
  products: 'Find, organize, and maintain the items you stock.',
  categories: 'Group products for easier browsing and reporting.',
  suppliers: 'Maintain supplier contact details and review purchasing history.',
}

export function Catalog({ base, code, canEdit, resource, lowStock, inventory }: Props) {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState(new URLSearchParams(location.search).get('search') ?? '')
  const [stock, setStock] = useState(lowStock ? 'affected' : '')
  const [category, setCategory] = useState<number | null>(Number(new URLSearchParams(location.search).get('category')) || null)
  const [productCategory, setProductCategory] = useState<number | null>(null)
  const [sort, setSort] = useState('name')
  const [archived, setArchived] = useState(false)
  const [version, setVersion] = useState(0)
  const [edit, setEdit] = useState<Partial<CatalogRow> | null>(null)
  const [details, setDetails] = useState<CatalogRow | null>(null)
  const [remove, setRemove] = useState<CatalogRow | null>(null)
  const [pending, setPending] = useState(false)
  const [failure, setFailure] = useState('')
  const url = base + '/' + resource
  const listing = useData<Page<CatalogRow>>(url + query({ search, page, sort, category_id: category ?? undefined, stock_status: stock, archived: archived ? 1 : undefined }), version)
  const product = resource === 'products'
  const supplier = resource === 'suppliers'
  const singular = names[resource]

  function open(row: Partial<CatalogRow>) {
    setFailure('')
    setEdit(row)
    setProductCategory(row.category_id ?? null)
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const image = form.get('image')
    form.delete('image')
    const values = { ...Object.fromEntries(form.entries()), ...(product ? { category_id: productCategory } : {}) }
    setPending(true)
    setFailure('')
    try {
      const updated = await api<CatalogRow>(url + (edit?.id ? '/' + edit.id : ''), values, edit?.id ? 'PUT' : 'POST')
      if (image instanceof File && image.size > 0) {
        setEdit(updated)
        const upload = new FormData()
        upload.append('image', image)
        await api(url + '/' + updated.id + '/image', upload)
      }
      setEdit(null)
      setVersion(version + 1)
    } catch (error) {
      setFailure(errorMessage(error))
      setVersion(version + 1)
    } finally {
      setPending(false)
    }
  }

  async function deleteRecord() {
    if (!remove) return
    setPending(true)
    setFailure('')
    try {
      await api(url + '/' + remove.id, undefined, 'DELETE')
      setRemove(null)
      setPage(1)
      setVersion(version + 1)
    } catch (error) {
      setFailure(errorMessage(error))
    } finally {
      setPending(false)
    }
  }

  const columns = [
    { title: 'Name', render: (row: CatalogRow) => <button className="font-semibold text-blue-700 hover:underline dark:text-blue-300" onClick={() => setDetails(row)}>{row.name}</button> },
    ...(product ? [
      { title: 'SKU', render: (row: CatalogRow) => display(row.sku) },
      { title: 'Stock', render: (row: CatalogRow) => <span className={Number(row.stock) <= Number(row.minimum_stock) ? 'font-semibold text-orange-700 dark:text-orange-300' : ''}>{display(row.stock)} {row.unit}</span> },
      { title: 'Minimum', render: (row: CatalogRow) => display(row.minimum_stock) },
      { title: 'Selling price', render: (row: CatalogRow) => currency(row.selling_price, code) },
    ] : supplier ? [
      { title: 'Contact', render: (row: CatalogRow) => display(row.contact_person) },
      { title: 'Email', render: (row: CatalogRow) => display(row.email) },
      { title: 'Phone', render: (row: CatalogRow) => display(row.phone) },
    ] : [{ title: 'Description', render: (row: CatalogRow) => display(row.description) }]),
    ...((canEdit && !archived) ? [{
      title: 'Actions',
      render: (row: CatalogRow) => <div className="flex flex-wrap gap-2">
        {lowStock ? <a className={button} href={'/workspace/purchases?product=' + row.id}>Restock</a> : <>
          <button className={button} onClick={() => open(row)}>Edit</button>
          <button className={button} onClick={() => { setFailure(''); setRemove(row) }}>{resource === 'categories' ? 'Delete' : 'Archive'}</button>
        </>}
      </div>,
    }] : []),
  ]

  return <>
    <Heading title={lowStock ? 'Low stock' : inventory ? 'Inventory' : resource[0].toUpperCase() + resource.slice(1)} description={lowStock ? 'Review products at or below their reorder threshold.' : inventory ? 'Review current balances across your products.' : descriptions[resource]}
      actions={canEdit && !lowStock && <button className={'inline-flex items-center gap-2 ' + primary} onClick={() => open({})}><Plus className="size-4" />Add {singular}</button>} />
    <div className="flex flex-wrap gap-3">
      <label className="max-w-xs flex-1"><span className="sr-only">Search {resource}</span><input placeholder={'Search ' + resource} className={input} type="search" value={search} onChange={event => { setPage(1); setSearch(event.target.value) }} /></label>
      {product && <>
        <label><span className="sr-only">Stock status</span><select className={input} value={stock} onChange={event => { setPage(1); setStock(event.target.value) }}>
          <option value={lowStock ? 'affected' : ''}>{lowStock ? 'All alerts' : 'All stock levels'}</option><option value="low">Low stock</option><option value="out">Out of stock</option>{!lowStock && <option value="in_stock">In stock</option>}
        </select></label>
        <div className="w-44"><RecordPicker base={base} resource="categories" title="Category filter" value={category} required={false} onChange={id => { setPage(1); setCategory(id) }} /></div>
      </>}
      {!lowStock && <>
        <label><span className="sr-only">Sort</span><select className={input} value={sort} onChange={event => { setPage(1); setSort(event.target.value) }}><option value="name">Name</option><option value="recent">Newest</option></select></label>
        {resource !== 'categories' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={archived} onChange={event => { setPage(1); setArchived(event.target.checked) }} /> Archived</label>}
      </>}
    </div>
    <Banner error={listing.error} />
    {!listing.error && <div className={section}>
      <Table items={listing.data?.data} columns={columns} empty={lowStock ? 'No products need attention.' : 'No ' + resource + ' match these filters.'} />
      <Pagination result={listing.data} navigate={setPage} />
    </div>}
    {edit && <Dialog title={edit.id ? 'Edit ' + singular : 'Add ' + singular} close={() => { if (!pending) setEdit(null) }}>
      <form className="grid gap-4" onSubmit={save} key={edit.id ?? 'new'}>
        <Banner error={failure} />
        <Field title="Name"><input className={input} name="name" defaultValue={edit.name} maxLength={product || supplier ? 160 : 120} required /></Field>
        {product && <>
          <div className="grid grid-cols-2 gap-3">
            <Field title="SKU"><input className={input} name="sku" defaultValue={edit.sku} maxLength={100} required /></Field>
            <Field title="Barcode"><input className={input} name="barcode" defaultValue={edit.barcode} maxLength={100} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <RecordPicker base={base} resource="categories" title="Category" value={productCategory} required={false} onChange={setProductCategory} />
            <Field title="Unit of measure"><input name="unit" className={input} list="units" defaultValue={edit.unit ?? 'pieces'} required maxLength={40} />
              <datalist id="units">{['pieces', 'boxes', 'cartons', 'kilograms', 'litres', 'packs', 'metres'].map(unit => <option key={unit} value={unit} />)}</datalist></Field>
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field title="Purchase cost"><input className={input} type="number" step="0.0001" min="0" name="purchase_cost" defaultValue={edit.purchase_cost ?? '0'} required /></Field>
            <Field title="Selling price"><input className={input} type="number" step="0.0001" min="0" name="selling_price" defaultValue={edit.selling_price ?? '0'} required /></Field>
            <Field title="Minimum stock"><input className={input} type="number" step="0.001" min="0" name="minimum_stock" defaultValue={edit.minimum_stock ?? '0'} required /></Field>
          </div>
          <Field title="Product image"><input className={input} type="file" name="image" accept="image/jpeg,image/png,image/webp" /></Field>
        </>}
        {supplier ? <>
          <Field title="Contact person"><input className={input} name="contact_person" defaultValue={edit.contact_person} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field title="Email"><input className={input} name="email" type="email" defaultValue={edit.email} /></Field>
            <Field title="Phone"><input className={input} type="tel" name="phone" defaultValue={edit.phone} /></Field>
          </div>
          <Field title="Address"><textarea className={input} name="address" defaultValue={edit.address} /></Field>
          <Field title="Notes"><textarea className={input} name="notes" defaultValue={edit.notes} /></Field>
        </> : <Field title="Description"><textarea className={input} name="description" defaultValue={edit.description} /></Field>}
        <button type="submit" className={primary} disabled={pending}>{pending ? 'Saving…' : 'Save ' + singular}</button>
      </form>
    </Dialog>}
    {remove && <Dialog title={(resource === 'categories' ? 'Delete' : 'Archive') + ' ' + remove.name + '?'} close={() => { if (!pending) setRemove(null) }}>
      <div className="grid gap-4">
        <Banner error={failure} />
        <p>{resource === 'categories' ? 'Deleting a category permanently removes it from your catalog.' : 'Archived records are removed from active lists. Transaction history is retained.'}</p>
        <button type="button" className="rounded-md bg-red-700 px-4 py-2 font-semibold text-white" onClick={deleteRecord} disabled={pending}>{pending ? 'Working…' : resource === 'categories' ? 'Delete category' : 'Archive ' + singular}</button>
      </div>
    </Dialog>}
    {details && <Dialog title={details.name} close={() => setDetails(null)}>
      <div className="grid gap-4">
        {product && details.image_path && <img className="max-h-52 rounded-md object-contain" src={'/api/v1' + url + '/' + details.id + '/image'} alt={details.name} />}
        {details.description && <p className="whitespace-pre-wrap">{details.description}</p>}
        {product && <>
          <dl className="grid grid-cols-2 gap-4 text-sm">
            <div><dt className="opacity-70">SKU</dt><dd>{details.sku}</dd></div>
            <div><dt className="opacity-70">Barcode</dt><dd>{display(details.barcode)}</dd></div>
            {details.purchase_cost !== undefined && <div><dt className="opacity-70">Purchase cost</dt><dd>{currency(details.purchase_cost, code)}</dd></div>}
            <div><dt className="opacity-70">Selling price</dt><dd>{currency(details.selling_price, code)}</dd></div>
            <div><dt className="opacity-70">Stock</dt><dd>{display(details.stock)} {details.unit}</dd></div>
            <div><dt className="opacity-70">Minimum</dt><dd>{display(details.minimum_stock)}</dd></div>
          </dl>
          <a className="font-semibold underline" href={'/workspace/movements?product=' + details.id}>View stock history</a>
        </>}
        {resource === 'categories' && <a className="font-semibold underline" href={'/workspace/products?category=' + details.id}>View products</a>}
        {supplier && <>
          <dl className="grid grid-cols-2 gap-4 text-sm">{(['contact_person', 'email', 'phone', 'address', 'notes'] as const).map(field =>
            <div key={field}><dt className="capitalize opacity-70">{display(field)}</dt><dd>{display(details[field])}</dd></div>
          )}</dl>
          {canEdit && <>
            <SupplierProducts base={base} supplier={details.id} />
            <a className="font-semibold underline" href={'/workspace/purchases?supplier=' + details.id}>View purchase history</a>
          </>}
        </>}
      </div>
    </Dialog>}
  </>
}

function SupplierProducts({ base, supplier }: { base: string; supplier: number }) {
  const [page, setPage] = useState(1)
  const listing = useData<Page<{ id: number; name: string; sku: string }>>(base + '/suppliers/' + supplier + '/products?page=' + page)
  return <section>
    <h3 className="mb-2 font-semibold">Associated products</h3>
    <Banner error={listing.error} />
    {!listing.error && <div className={section}>
      <Table items={listing.data?.data} columns={[
        { title: 'Product', render: row => <a className="font-semibold underline" href={'/workspace/products?search=' + encodeURIComponent(row.sku)}>{row.name}</a> },
        { title: 'SKU', render: row => row.sku },
      ]} empty="This supplier has no products on its purchase orders." />
      <Pagination result={listing.data} navigate={setPage} />
    </div>}
  </section>
}
