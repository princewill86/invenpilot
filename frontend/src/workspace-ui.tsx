import { Children, cloneElement, isValidElement, useEffect, useId, useState, type ReactNode } from 'react'
import { api, errorMessage, type Page } from './api'

export const input = 'control-input'
export const button = 'control-button'
export const primary = 'control-primary'
export const section = 'content-surface'
export const caption = 'text-sm opacity-75'

export function useData<T>(url: string | null, version = 0) {
  const [state, setState] = useState<{ data?: T; error?: string; url?: string; version?: number }>({})
  useEffect(() => {
    if (!url) return
    const controller = new AbortController()
    api<T>(url, undefined, 'GET', controller.signal).then(
      data => setState({ data, url, version }),
      error => { if (!controller.signal.aborted) setState({ error: errorMessage(error), url, version }) },
    )
    return () => controller.abort()
  }, [url, version])
  return state.url === url && state.version === version ? state : {}
}

export function Banner({ error }: { error?: string }) {
  return error ? <p role="alert" className="rounded-md border border-red-500/50 bg-red-500/10 p-3 text-sm">{error}</p> : null
}

export function Heading({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return <header className="page-heading">
    <div>
      <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{title}</h1>
      {description && <p className={'mt-2 ' + caption}>{description}</p>}
    </div>
    {actions}
  </header>
}

export function Field({ title, children }: { title: string; children: ReactNode }) {
  const controlId = useId()
  return <div className="grid gap-1.5 text-sm font-semibold">
    <label htmlFor={controlId}>{title}</label>
    {Children.map(children, (child, index) => index === 0 && isValidElement<{ id?: string }>(child)
      ? cloneElement(child, { id: controlId })
      : child)}
  </div>
}

export function Table<T>({ items, columns, empty = 'No records to display.' }: {
  items?: T[]
  columns: { title: string; render: (item: T) => ReactNode }[]
  empty?: string
}) {
  if (!items) return <p role="status" className="p-5 text-sm">Loading…</p>
  if (!items.length) return <div className="table-empty"><span aria-hidden="true">—</span><p>{empty}</p></div>
  return <div className="workspace-table overflow-x-auto">
    <table className="w-full border-collapse text-left text-sm">
      <thead className="bg-blue-500/5">
        <tr>{columns.map(column =>
          <th scope="col" className="h-11 whitespace-nowrap px-4 font-semibold" key={column.title}>{column.title}</th>
        )}</tr>
      </thead>
      <tbody className="divide-y divide-current/10">
        {items.map((item, index) => <tr key={index}>
          {columns.map(column => <td className="px-4 py-3 align-middle" key={column.title}>{column.render(item)}</td>)}
        </tr>)}
      </tbody>
    </table>
  </div>
}

export function Pagination({ result, navigate }: { result?: Page<unknown>; navigate: (page: number) => void }) {
  if (!result || result.last_page < 2) return null
  return <nav aria-label="Pagination" className="flex items-center justify-between gap-3 border-t border-current/15 p-3 text-sm">
    <button className={button} onClick={() => navigate(result.current_page - 1)} disabled={result.current_page < 2}>Previous</button>
    <span>Page {result.current_page} of {result.last_page}</span>
    <button className={button} onClick={() => navigate(result.current_page + 1)} disabled={result.current_page >= result.last_page}>Next</button>
  </nav>
}

export function currency(value: unknown, code: string) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(Number(value ?? 0))
}

export function display(value: unknown): ReactNode {
  if (value === null || value === undefined || value === '') return '—'
  return String(value).replaceAll('_', ' ')
}

export function Dialog({ title, close, children }: { title: string; close: () => void; children: ReactNode }) {
  return <dialog
    ref={dialog => { if (dialog && !dialog.open) dialog.showModal() }}
    aria-label={title}
    onCancel={event => { event.preventDefault(); close() }}
    className="workspace-dialog fixed inset-0 m-auto max-h-[90dvh] w-[min(94vw,640px)] overflow-auto rounded-xl border border-current/20 bg-white p-5 text-slate-900 backdrop:bg-slate-950/55 dark:bg-slate-900 dark:text-slate-100 sm:p-7"
  >
    <header className="mb-5 flex items-center justify-between gap-4">
      <h2 className="text-xl font-bold">{title}</h2>
      <button type="button" className={button} onClick={close} aria-label="Close dialog">Close</button>
    </header>
    {children}
  </dialog>
}
