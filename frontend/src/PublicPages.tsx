import { useState } from 'react'
import { Activity, BarChart3, Boxes, Check, ChevronDown, ClipboardList, Database, Layers3, LockKeyhole, PackageCheck, PackageSearch, ScanLine, ShoppingBag, Truck, Users } from 'lucide-react'
import type { Platform } from './platform'

export function Brand({ platform, href = '/' }: { platform?: Platform; href?: string }) {
  return <a href={href} className="brand">{platform?.logo_url ? <img src={platform.logo_url} alt="" /> : <span><Boxes size={21} /></span>}{platform?.name ?? 'InvenPilot'}</a>
}

const workflows = [
  { label: 'Overview', icon: BarChart3, title: 'Inventory overview', columns: ['Product', 'SKU', 'On hand', 'Status'], rows: [['Studio headphones', 'AU-104', '82', 'In stock'], ['USB-C travel hub', 'AC-203', '16', 'Low stock'], ['Mechanical keyboard', 'AC-125', '44', 'In stock']] },
  { label: 'Purchasing', icon: Truck, title: 'Purchase orders', columns: ['Supplier', 'Order', 'Items', 'Status'], rows: [['Westway Components', 'PO-104', '48', 'Received'], ['Northline Audio', 'PO-103', '20', 'Pending'], ['Oak Supplies', 'PO-102', '12', 'Received']] },
  { label: 'Sales', icon: ShoppingBag, title: 'Sales history', columns: ['Transaction', 'Reference', 'Units', 'Status'], rows: [['Card payment', 'SO-203', '6', 'Completed'], ['Bank transfer', 'SO-202', '9', 'Completed'], ['Card payment', 'SO-201', '2', 'Returned']] },
]

export function Preview() {
  const [selected, setSelected] = useState(0)
  const view = workflows[selected]
  return <div className="preview-frame">
    <div className="preview-bar"><div><i /><i /><i /></div><span>Interactive product preview · Sample data</span></div>
    <div className="preview-body">
      <nav aria-label="Product preview">{workflows.map(({label, icon: Icon}, i) => <button key={label} type="button" aria-pressed={selected === i} onClick={() => setSelected(i)}><Icon size={15} />{label}</button>)}</nav>
      <div className="preview-content">
        <div className="preview-heading"><div><span>Northline Supply</span><strong>{view.title}</strong></div><Activity size={20} /></div>
        <div className="preview-chart" aria-hidden="true">{[27, 35, 29, 49, 43, 62, 51, 58, 80, 67, 91, 76, 98, 88, 100].map((height, i) => <i key={i} style={{height: (selected === 1 && i % 2 ? height * .72 : height) + '%'}} />)}</div>
        <div className="overflow-x-auto"><table><thead><tr>{view.columns.map(col => <th key={col}>{col}</th>)}</tr></thead><tbody>{view.rows.map(row => <tr key={row[1]}>{row.map((value, i) => <td key={i}>{i === 3 ? <span className={'preview-pill ' + (value === 'Low stock' || value === 'Pending' ? 'preview-warning' : '')}>{value}</span> : value}</td>)}</tr>)}</tbody></table></div>
      </div>
    </div>
  </div>
}

const features = [
  {icon: PackageSearch, title: 'Always know what’s on hand', copy: 'Organize products by category, track SKUs and barcodes, and give your team a clear view of available stock.'},
  {icon: Truck, title: 'Receive stock at your own pace', copy: 'Create purchase orders, record partial deliveries, and track outstanding quantities with each supplier.'},
  {icon: ShoppingBag, title: 'Make every sale traceable', copy: 'Record sales, process returns, and review the stock changes behind each transaction.'},
  {icon: ScanLine, title: 'Restock before you run out', copy: 'Set reorder thresholds by product and turn stock alerts into purchase orders.'},
  {icon: BarChart3, title: 'See revenue, cost, and margin', copy: 'Review business performance and stock value, filter by date, and export data for your records.'},
  {icon: Users, title: 'Give teams the right access', copy: 'Invite teammates as staff or administrators and keep each company’s records in its own workspace.'},
]

export function Landing({ platform }: { platform?: Platform }) {
  const canRegister = platform?.registration_enabled !== false
  return <main className="marketing">
    <section className="hero site-width">
      <div className="hero-copy">
        <div className="hero-eyebrow"><span /> Inventory operations</div>
        <h1>{platform?.headline ?? 'Know what’s in stock. Know what’s next.'}</h1>
        <p>{platform?.description ?? 'Manage inventory, purchasing, and sales in one workspace. Follow every stock change from receiving to returns.'}</p>
        <div className="hero-actions">{canRegister && <a className="action-primary" href="/register">Create a business</a>}<a className="action-secondary" href={canRegister ? '#product' : '/login'}>{canRegister ? 'Explore the platform' : 'Sign in'}</a></div>
        <div className="hero-points"><span><Check size={15} /> Live stock balances</span><span><Check size={15} /> Auditable movements</span></div>
      </div>
      <Preview />
    </section>
    <section className="capabilities site-width" id="product" aria-label="Core capabilities">
      <span><Layers3 size={19} /> Product catalog</span><span><ClipboardList size={19} /> Purchase orders</span><span><ShoppingBag size={19} /> Sales and returns</span><span><BarChart3 size={19} /> Business reports</span>
    </section>
    <section className="site-width marketing-section" id="features">
      <div className="section-intro"><p>What you can do</p><h2>Keep day-to-day operations in sync</h2><span>Track the work that happens between a purchase order and a sale.</span></div>
      <div className="feature-grid">{features.map(({icon: Icon,title,copy}) => <article key={title}><span><Icon size={22} /></span><h3>{title}</h3><p>{copy}</p></article>)}</div>
    </section>
    <section className="workflow-band" id="workflow"><div className="site-width workflow-inner">
      <div className="section-intro"><p>How it works</p><h2>From warehouse to balance sheet</h2><span>Purchases, sales, and every stock movement stay tied to the same product records.</span></div>
      <ol><li><PackageCheck /><div><strong>Receive goods</strong><p>Verify deliveries against purchase orders and add the quantities that arrived.</p></div></li><li><Activity /><div><strong>Record what changes</strong><p>See who received, sold, adjusted, or returned each quantity.</p></div></li><li><BarChart3 /><div><strong>Review results</strong><p>Compare sales, purchasing costs, and stock value across reporting periods.</p></div></li></ol>
    </div></section>
    <section className="site-width marketing-section faq" id="faq">
      <div className="section-intro"><p>Common questions</p><h2>Before you get started</h2></div>
      <div>{[
        ['Can I manage more than one business?', 'Yes. If you are a member of multiple businesses, switch between them from the same login. Each business keeps its own products, transactions, and team permissions.'],
        ['How are stock changes tracked?', 'The inventory ledger records the product, quantity change, reason, and person who made each movement. Purchase receipts, sales, and returns also create ledger entries.'],
        ['Can I export data?', 'Yes. Reports support date and record filters, CSV downloads, and printable views.'],
        ['Can my staff see sensitive settings?', 'Staff, business administrators, and business owners have different permissions. Platform-level settings are available to the installation administrator.'],
      ].map(([question, answer]) => <details key={question}><summary>{question}<ChevronDown size={19} /></summary><p>{answer}</p></details>)}</div>
    </section>
    <section className="site-width"><div className="closing"><Database size={30} /><div><h2>Put your inventory in order</h2><p>Set up your business and start tracking stock as it moves.</p></div><a className="action-primary" href={canRegister ? '/register' : '/login'}>{canRegister ? 'Create a business' : 'Sign in'}</a></div></section>
  </main>
}

export function Policy({ privacy, platform }: { privacy: boolean; platform?: Platform }) {
  return <main className="site-width policy-page">
    <div className="policy-icon"><LockKeyhole size={26} /></div>
    <h1>{privacy ? 'Privacy policy' : 'Terms of service'}</h1>
    {platform ? (privacy ? platform.privacy : platform.terms).split(/\n\s*\n/).map((entry, i) => <p key={i}>{entry}</p>) : <p role="status">Loading policy…</p>}
    {platform?.support_email && <a href={'mailto:' + platform.support_email}>{platform.support_email}</a>}
  </main>
}

export function SiteFooter({ platform }: { platform?: Platform }) {
  return <footer className="site-footer"><div className="site-width">
    <div className="footer-grid">
      <div><Brand platform={platform} /><p>Inventory, purchasing, and sales for growing businesses.</p></div>
      <nav aria-label="Product"><strong>Product</strong><a href="/#features">Features</a><a href="/#workflow">How it works</a><a href="/#faq">FAQ</a></nav>
      <nav aria-label="Account"><strong>Account</strong><a href="/login">Sign in</a>{platform?.registration_enabled !== false && <a href="/register">Create a business</a>}{platform?.password_reset_enabled !== false && <a href="/forgot-password">Reset password</a>}</nav>
      <nav aria-label="Resources"><strong>Resources</strong><a href="https://github.com/princewill86/invenpilot">GitHub repository</a><a href="/privacy">Privacy policy</a><a href="/terms">Terms of service</a>{platform?.support_email && <a href={'mailto:' + platform.support_email}>Contact support</a>}</nav>
    </div>
    <div className="footer-base"><span>© {new Date().getFullYear()} {platform?.name ?? 'InvenPilot'}</span><span>Created by <a className="font-semibold underline underline-offset-2" href="https://github.com/princewill86">princewill86</a></span></div>
  </div></footer>
}
