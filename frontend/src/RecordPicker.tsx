import { useState } from 'react'
import { query, type Page } from './api'
import type { CatalogRow } from './Catalog'
import { Banner, Field, input, useData } from './workspace-ui'

export function RecordPicker({ base, resource, title, value, onChange, required = true }: {
  base: string
  resource: 'products' | 'suppliers' | 'categories'
  title: string
  value: number | null
  onChange: (id: number | null, row?: CatalogRow) => void
  required?: boolean
}) {
  const [search, setSearch] = useState('')
  const options = useData<Page<CatalogRow>>(base + '/' + resource + query({ search }))
  const selection = useData<CatalogRow>(value ? base + '/' + resource + '/' + value : null)
  const rows = [...(options.data?.data ?? [])]
  if (selection.data && !rows.some(row => row.id === selection.data?.id)) rows.push(selection.data)
  return <div className="grid gap-2">
    <label><span className="sr-only">Search {resource}</span>
      <input className={input} type="search" placeholder={'Find ' + resource + ' by name' + (resource === 'products' ? ' or SKU' : '')} value={search} onChange={event => setSearch(event.target.value)} />
    </label>
    <Field title={title}><select className={input} required={required} value={value ?? ''} onChange={event => {
      const selection = rows.find(row => row.id === Number(event.target.value))
      onChange(selection?.id ?? null, selection)
    }}>
      <option value="">{options.data ? 'Select ' + title.toLowerCase() : 'Loading…'}</option>
      {rows.map(row => <option value={row.id} key={row.id}>{row.name}{row.sku ? ' · ' + row.sku : ''}</option>)}
    </select></Field>
    <Banner error={options.error || selection.error} />
  </div>
}
