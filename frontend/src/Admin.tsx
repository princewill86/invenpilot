import { useState, type FormEvent, type ReactNode } from 'react'
import { Activity, Building2, Globe2, LayoutDashboard, LogOut, Mail, Settings2, ShieldCheck, Users } from 'lucide-react'
import { api, errorMessage, type Account, type Page } from './api'
import type { Platform } from './platform'
import { HeaderMenuButton } from './HeaderMenuButton'
import { useMobileMenu } from './useMobileMenu'
import { PlatformActivity, PlatformDirectory } from './PlatformDirectory'
import { Banner, Field, Heading, Pagination, Table, button, input, primary, section, useData } from './workspace-ui'

type AdminSettings = Platform & {
  invitation_days: number; mail_mode: string; mail_from_address: string | null; mail_from_name: string | null;
  smtp_host: string | null; smtp_port: number; smtp_scheme: string; smtp_username: string | null; smtp_password_set: boolean;
}
type Overview = { business_count: number; user_count: number; businesses: Page<{id: number; name: string; currency: string; created_at: string}> }
const base = '/platform-admin'

export default function Admin({ account }: { account: Account }) {
  const menu = useMobileMenu()
  const path = location.pathname.split('/')[2]
  const active = ['settings', 'users', 'businesses', 'activity'].includes(path) ? path : 'overview'
  const settings = useData<AdminSettings>(active === 'settings' ? base + '/settings' : null)
  const [page, setPage] = useState(1)
  const overview = useData<Overview>(active === 'overview' ? base + '/overview?page=' + page : null)
  const [error, setError] = useState('')
  return <div className="admin-shell">
    <aside ref={menu.container} className="admin-sidebar print:hidden">
      <HeaderMenuButton menu={menu} controls="admin-navigation" label="Toggle admin navigation" />
      <div id="admin-navigation" className={'admin-nav-panel' + (menu.open ? ' mobile-menu-open' : '')}>
      <nav aria-label="Platform administration" onClick={event => { if ((event.target as HTMLElement).closest('a')) menu.setOpen(false) }}>
        <a aria-current={active === 'overview' ? 'page' : undefined} href="/admin"><LayoutDashboard size={17} />Overview</a>
        <a aria-current={active === 'users' ? 'page' : undefined} href="/admin/users"><Users size={17} />Users</a>
        <a aria-current={active === 'businesses' ? 'page' : undefined} href="/admin/businesses"><Building2 size={17} />Businesses</a>
        <a aria-current={active === 'activity' ? 'page' : undefined} href="/admin/activity"><Activity size={17} />Activity log</a>
        <a aria-current={active === 'settings' ? 'page' : undefined} href="/admin/settings"><Settings2 size={17} />Platform settings</a>
        {account.businesses.length > 0 && <a href="/workspace"><Building2 size={17} />Business workspace</a>}
      </nav>
      <div className="sidebar-account"><strong>{account.user.name}</strong><span>Platform administrator</span><button type="button" onClick={async () => {
        try { await api('/logout', {}); location.assign('/login') } catch (failure) { setError(errorMessage(failure)) }
      }}><LogOut size={16} />Sign out</button></div>
      </div>
    </aside>
    <main className="admin-content">
      <Banner error={error || settings.error || overview.error} />
      {active === 'users' || active === 'businesses' ? <PlatformDirectory kind={active} /> : active === 'activity' ? <PlatformActivity /> : active === 'settings' ? settings.data ? <Settings settings={settings.data} /> : !settings.error && <p role="status">Loading platform settings…</p> : <>
        <Heading title="Platform overview" description="Monitor businesses and users across this installation." actions={<a href="/admin/settings" className={primary}>Manage settings</a>} />
        {overview.data ? <>
          <div className="admin-metrics"><a className={section} href="/admin/businesses"><Building2 size={20} /><span>Businesses</span><strong>{overview.data.business_count}</strong></a><a className={section} href="/admin/users"><Users size={20} /><span>User accounts</span><strong>{overview.data.user_count}</strong></a></div>
          <div className={section}><Table items={overview.data.businesses.data} columns={[
            { title:'Business',render: row => <a className="font-semibold underline" href={'/admin/businesses?record=' + row.id}>{row.name}</a> },
            { title:'Currency',render: row => row.currency },
            { title:'Created',render: row => new Date(row.created_at).toLocaleDateString() },
          ]} /><Pagination result={overview.data.businesses} navigate={setPage} /></div>
        </> : !overview.error && <p role="status">Loading activity…</p>}
      </>}
    </main>
  </div>
}

function Settings({ settings }: { settings: AdminSettings }) {
  const [data, setData] = useState(settings)
  const [mail, setMail] = useState(settings.mail_mode)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setPending(true); setError(''); setNotice('')
    try {
      const updated = await api<AdminSettings>(base + '/settings', {
        ...Object.fromEntries(form),
        currencies: String(form.get('currencies')).split(/[\s,]+/).filter(Boolean).map(code => code.toUpperCase()),
        default_currency: String(form.get('default_currency')).toUpperCase(),
        registration_enabled: form.has('registration_enabled'), search_indexing: form.has('search_indexing'),
        password_reset_enabled: form.has('password_reset_enabled'), clear_smtp_password: form.has('clear_smtp_password'),
      }, 'PUT')
      setData(updated); setNotice('Platform settings saved.')
      const password = document.querySelector<HTMLInputElement>('input[name=smtp_password]')
      if (password) password.value = ''
    } catch (failure) { setError(errorMessage(failure)) }
    finally { setPending(false) }
  }

  async function asset(kind: 'logo' | 'favicon', image?: File) {
    setPending(true); setError(''); setNotice('')
    try {
      const form = new FormData()
      if (image) form.append('image', image)
      setData(await api<AdminSettings>(base + '/assets/' + kind, image ? form : undefined, image ? 'POST' : 'DELETE'))
      setNotice((kind === 'logo' ? 'Logo' : 'Favicon') + ' updated.')
    } catch (failure) { setError(errorMessage(failure)) }
    finally { setPending(false) }
  }

  return <>
    <Heading title="Platform settings" description="Manage the public site, registrations, security, and mail delivery." />
    <nav aria-label="Settings sections" className="settings-tabs">{[['branding','Branding'],['seo','SEO'],['security','Security'],['email','Email'],['policies','Policies']].map(([href, label]) => <a key={href} href={'#' + href}>{label}</a>)}</nav>
    <Banner error={error} />
    {notice && <p role="status" className="status-success">{notice}</p>}
    <form onSubmit={save} className="grid gap-5">
      <Group id="branding" title="Branding and registration" icon={<Building2 size={19} />}>
        <div className="form-grid">
          <Field title="Platform name"><input className={input} name="name" defaultValue={data.name} maxLength={60} required /></Field>
          <Field title="Support email"><input className={input} type="email" name="support_email" defaultValue={data.support_email ?? ''} /></Field>
        </div>
        <Field title="Landing headline"><input className={input} name="headline" defaultValue={data.headline} maxLength={130} required /></Field>
        <Field title="Landing description"><textarea className={input} name="description" defaultValue={data.description} maxLength={450} rows={3} required /></Field>
        <div className="form-grid">{(['logo','favicon'] as const).map(kind => <div className="asset-control" key={kind}>
          <strong>{kind === 'logo' ? 'Logo' : 'Favicon'}</strong>
          {data[kind + '_url' as 'logo_url' | 'favicon_url'] && <img alt={kind} src={data[kind + '_url' as 'logo_url' | 'favicon_url'] ?? ''} />}
          <Field title={kind === 'logo' ? 'Upload logo' : 'Upload favicon'}><input className={input} type="file" accept="image/png,image/jpeg,image/webp" disabled={pending} onChange={event => { const image = event.currentTarget.files?.[0]; if (image) void asset(kind,image); event.currentTarget.value = '' }} /></Field>
          {data[kind + '_url' as 'logo_url' | 'favicon_url'] && <button type="button" className={button} disabled={pending} onClick={() => asset(kind)}>Remove {kind}</button>}
        </div>)}</div>
        <div className="form-grid"><Field title="Default currency"><input className={input} name="default_currency" defaultValue={data.default_currency} maxLength={3} required /></Field><Field title="Allowed currencies"><input className={input} name="currencies" defaultValue={data.currencies.join(', ')} required /></Field></div>
      </Group>
      <Group id="seo" title="Search appearance" icon={<Globe2 size={19} />}>
        <Field title="SEO page title"><input className={input} name="seo_title" defaultValue={data.seo_title} maxLength={100} required /></Field>
        <Field title="SEO description"><textarea className={input} name="seo_description" defaultValue={data.seo_description} maxLength={300} required /></Field>
        <label className="check-control"><input type="checkbox" name="search_indexing" defaultChecked={data.search_indexing} /> Allow indexing of public pages</label>
      </Group>
      <Group id="security" title="Account security" icon={<ShieldCheck size={19} />}>
        <label className="check-control"><input type="checkbox" name="registration_enabled" defaultChecked={data.registration_enabled} /> Allow public business registration</label>
        <label className="check-control"><input type="checkbox" name="password_reset_enabled" defaultChecked={data.password_reset_enabled} /> Allow password reset by email</label>
        <div className="form-grid"><Field title="Minimum password length"><input className={input} name="password_minimum" type="number" min="12" max="40" defaultValue={data.password_minimum} required /></Field><Field title="Invitation expiry in days"><input className={input} name="invitation_days" type="number" min="1" max="30" defaultValue={data.invitation_days} required /></Field></div>
      </Group>
      <Group id="email" title="Email delivery" icon={<Mail size={19} />}>
        <Field title="Delivery method"><select className={input} name="mail_mode" value={mail} onChange={event => setMail(event.target.value)}><option value="environment">Server environment</option><option value="php_mail">PHP mail / local sendmail</option><option value="smtp">SMTP</option></select></Field>
        <div className="form-grid"><Field title="Sender name"><input className={input} name="mail_from_name" defaultValue={data.mail_from_name ?? ''} /></Field><Field title="Sender address"><input className={input} name="mail_from_address" type="email" defaultValue={data.mail_from_address ?? ''} required={mail !== 'environment'} /></Field></div>
        {mail === 'php_mail' && <p className="settings-hint">PHP mail uses the server’s configured sendmail command. The server needs a working mail transport.</p>}
        <div hidden={mail !== 'smtp'} className="grid gap-4">
          <div className="form-grid"><Field title="SMTP host"><input className={input} name="smtp_host" defaultValue={data.smtp_host ?? ''} required={mail === 'smtp'} /></Field><Field title="SMTP port"><input className={input} type="number" name="smtp_port" min="1" max="65535" defaultValue={data.smtp_port} required /></Field></div>
          <div className="form-grid"><Field title="SMTP connection"><select className={input} name="smtp_scheme" defaultValue={data.smtp_scheme}><option value="smtp">SMTP with STARTTLS when available</option><option value="smtps">Implicit TLS</option></select></Field><Field title="SMTP username"><input className={input} name="smtp_username" defaultValue={data.smtp_username ?? ''} autoComplete="off" /></Field></div>
          <Field title="SMTP password"><input className={input} name="smtp_password" type="password" autoComplete="new-password" placeholder={data.smtp_password_set ? 'Password saved' : ''} /></Field>
          {data.smtp_password_set && <label className="check-control"><input type="checkbox" name="clear_smtp_password" /> Remove the saved SMTP password</label>}
        </div>
      </Group>
      <Group id="policies" title="Legal policies" icon={<ShieldCheck size={19} />}>
        <Field title="Terms of service"><textarea className={input} name="terms" defaultValue={data.terms} rows={8} maxLength={15000} required /></Field>
        <Field title="Privacy policy"><textarea className={input} name="privacy" defaultValue={data.privacy} rows={8} maxLength={15000} required /></Field>
      </Group>
      <div><button type="submit" className={primary} disabled={pending}>{pending ? 'Saving…' : 'Save platform settings'}</button></div>
    </form>
  </>
}

function Group({ id, title, icon, children }: { id: string; title: string; icon: ReactNode; children: ReactNode }) {
  return <section className={section + ' settings-group'} id={id}><header>{icon}<h2>{title}</h2></header>{children}</section>
}
