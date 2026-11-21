import { lazy, Suspense, useState } from 'react'
import { Activity, BarChart3, Box, ClipboardList, LayoutDashboard, ListTree, LogOut, PackageMinus, Settings2, Search, ShieldCheck, ShoppingBag, Truck, UserRound, Users, Warehouse, type LucideIcon } from 'lucide-react'
import { api, errorMessage, type Account } from './api'
import { Catalog } from './Catalog'
import { Inventory } from './Inventory'
import { Orders } from './Orders'
import { BusinessSettingsView, Profile, Team } from './Settings'
import { HeaderMenuButton } from './HeaderMenuButton'
import { useMobileMenu } from './useMobileMenu'
import { Banner, input } from './workspace-ui'

const Dashboard = lazy(() => import('./ReportViews').then(module => ({ default: module.Dashboard })))
const Reports = lazy(() => import('./ReportViews').then(module => ({ default: module.Reports })))

const groups: { name: string; links: { key: string; name: string; icon: LucideIcon; manager?: boolean; owner?: boolean }[] }[] = [
  { name: 'Workspace', links: [
    { key: '', name: 'Dashboard', icon: LayoutDashboard, manager: true },
    { key: 'products', name: 'Products', icon: Box },
    { key: 'categories', name: 'Categories', icon: ListTree },
    { key: 'inventory', name: 'Inventory', icon: Warehouse },
    { key: 'movements', name: 'Stock movements', icon: Activity },
    { key: 'low-stock', name: 'Low stock', icon: PackageMinus },
  ] },
  { name: 'Trading', links: [
    { key: 'suppliers', name: 'Suppliers', icon: Truck, manager: true },
    { key: 'purchases', name: 'Purchases', icon: ClipboardList, manager: true },
    { key: 'sales', name: 'Sales', icon: ShoppingBag },
    { key: 'reports', name: 'Reports', icon: BarChart3, manager: true },
  ] },
  { name: 'Settings', links: [
    { key: 'users', name: 'Users', icon: Users, owner: true },
    { key: 'settings', name: 'Business', icon: Settings2, owner: true },
    { key: 'profile', name: 'Profile', icon: UserRound },
  ] },
]

export default function Workspace({ account, refresh, dark }: { account: Account; refresh: () => void; dark: boolean }) {
  const [selected, setSelected] = useState(() => Number(localStorage.getItem('invenpilot-business-' + account.user.id)))
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const menu = useMobileMenu()
  const business = account.businesses.find(item => item.id === selected) ?? account.businesses[0]
  const owner = business?.role === 'owner'
  const manager = owner || business?.role === 'administrator'
  const key = location.pathname.split('/')[2] ?? ''
  const allowed = groups.flatMap(group => group.links).find(link => link.key === key && (!link.owner || owner) && (!link.manager || manager))
  const page = allowed?.key ?? (manager ? '' : 'products')
  const base = '/businesses/' + business?.id
  const code = business?.currency ?? 'USD'

  async function logout() {
    setError('')
    setPending(true)
    try {
      await api('/logout', {})
      location.assign('/login')
    } catch (failure) {
      setError(errorMessage(failure))
      setPending(false)
    }
  }

  function chooseBusiness(value: number) {
    localStorage.setItem('invenpilot-business-' + account.user.id, String(value))
    setSelected(value)
  }

  function content() {
    if (page === 'profile') return <Profile user={account.user} refresh={refresh} />
    if (!business) return <><h1 className="text-2xl font-bold">Your businesses</h1><p>You do not belong to a business yet. Ask a business owner to share an invitation link.</p></>
    if (business.suspended_at) return <div className="content-surface p-6">
      <h1 className="text-2xl font-bold">Business suspended</h1>
      <p className="mt-2">Contact your platform administrator about access to {business.name}.</p>
    </div>
    switch (page) {
      case '': return <Dashboard base={base} code={code} dark={dark} business={business.name} />
      case 'products':
      case 'categories':
      case 'suppliers':
        return <Catalog base={base} code={code} resource={page} canEdit={manager} />
      case 'inventory':
        return <Catalog base={base} code={code} resource="products" canEdit={false} inventory />
      case 'low-stock':
        return <Catalog base={base} code={code} resource="products" canEdit={manager} lowStock />
      case 'movements':
        return <Inventory base={base} canEdit={manager} />
      case 'purchases':
      case 'sales':
        return <Orders base={base} code={code} type={page} privileged={manager} />
      case 'reports': return <Reports base={base} code={code} />
      case 'settings': return <BusinessSettingsView base={base} refresh={refresh} />
      case 'users': return <Team base={base} />
      default: return null
    }
  }

  return <div className="workspace-shell print:block">
    <aside ref={menu.container} className="workspace-sidebar print:hidden">
      <HeaderMenuButton menu={menu} controls="workspace-nav" label="Toggle workspace navigation" />
      <nav id="workspace-nav" aria-label="Workspace" className={'workspace-nav-panel px-3 pb-4' + (menu.open ? ' mobile-menu-open' : '')} onClick={event => { if ((event.target as HTMLElement).closest('a')) menu.setOpen(false) }}>
        {business && <label className="workspace-business-picker"><span className="sr-only">Current business</span>
          <select className={input + ' business-select font-semibold'} value={business.id} onChange={event => { chooseBusiness(Number(event.target.value)); menu.setOpen(false) }}>
            {account.businesses.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </label>}
        {groups.map(group => {
          const links = group.links.filter(link => (business || link.key === 'profile') && (!link.owner || owner) && (!link.manager || manager))
          if (!links.length) return null
          return <div key={group.name} className="mb-4">
            <p className="sidebar-label">{group.name}</p>
            {links.map(link => <a
              href={'/workspace' + (link.key ? '/' + link.key : '')}
              key={link.key}
              aria-current={link.key === page ? 'page' : undefined}
              className="workspace-link"
            ><link.icon className="size-4" aria-hidden="true" />{link.name}</a>)}
          </div>
        })}
        {account.user.platform_admin && <a className="workspace-link" href="/admin"><ShieldCheck size={16} /> Platform administration</a>}
        <div className="sidebar-account">
          <span className="account-avatar" aria-hidden="true">{account.user.name.trim().slice(0, 1).toUpperCase()}</span>
          <p className="text-sm font-semibold">{account.user.name}</p>
          {business && <p className="mt-0.5 text-sm capitalize opacity-70">{business.role}</p>}
          <button type="button" className="mt-2 flex items-center gap-2 py-1 text-sm font-semibold hover:underline" onClick={logout} disabled={pending}>
            <LogOut className="size-4" aria-hidden="true" />{pending ? 'Signing out…' : 'Sign out'}
          </button>
        </div>
      </nav>
    </aside>
    <main className="workspace-main min-w-0" id="workspace-content">
      <div className="workspace-toolbar print:hidden">
        <span>{business?.name ?? 'Workspace'} <span aria-hidden="true">/</span> <strong>{groups.flatMap(group => group.links).find(link => link.key === page)?.name ?? 'Overview'}</strong></span>
        {business && <form action="/workspace/products" role="search"><Search size={16} /><input type="search" name="search" aria-label="Find a product" placeholder="Find a product" /></form>}
      </div>
      <div className="mx-auto grid max-w-[1320px] grid-cols-1 gap-5" key={base + '/' + page}>
        <Banner error={error} />
        <Suspense fallback={<p role="status">Loading workspace…</p>}>{content()}</Suspense>
      </div>
    </main>
  </div>
}
