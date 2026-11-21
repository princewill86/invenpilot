import { useState, type FormEvent } from 'react'
import { api, errorMessage, type Account, type Page } from './api'
import { Banner, Dialog, Field, Heading, Pagination, Table, button, display, input, primary, section, useData } from './workspace-ui'

type BusinessSettings = { name: string; currency: string; allow_negative_stock: boolean }
type Member = { id: number; name: string; email: string; role: string }
type Activity = { id: number; user_id: number; action: string; created_at: string }

export function BusinessSettingsView({ base, refresh }: { base: string; refresh: () => void }) {
  const [version, setVersion] = useState(0)
  const result = useData<BusinessSettings>(base + '/settings', version)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [pending, setPending] = useState(false)

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    setError('')
    setSaved(false)
    setPending(true)
    try {
      await api(base + '/settings', { name: values.get('name'), allow_negative_stock: values.has('allow_negative_stock') }, 'PUT')
      setVersion(version + 1)
      refresh()
      setSaved(true)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  return <>
    <Heading title="Business settings" description="Update the business profile and stock policy." />
    <Banner error={result.error || error} />
    {saved && <p role="status" className="text-sm text-green-700 dark:text-green-300">Business settings saved.</p>}
    {result.data && <form onSubmit={save} className={section + ' grid max-w-2xl gap-5 p-5'}>
      <Field title="Business name"><input className={input} name="name" defaultValue={result.data.name} maxLength={160} required /></Field>
      <Field title="Currency"><input className={input} value={result.data.currency} readOnly /></Field>
      <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="allow_negative_stock" defaultChecked={result.data.allow_negative_stock} /> Allow negative stock</label>
      <p className="text-sm opacity-75">Enabling this lets authorized transactions remove more stock than is currently on hand.</p>
      <button type="submit" className={primary + ' justify-self-start'} disabled={pending}>{pending ? 'Saving…' : 'Save settings'}</button>
    </form>}
    {!result.data && !result.error && <p role="status">Loading settings…</p>}
  </>
}

export function Team({ base }: { base: string }) {
  const [page, setPage] = useState(1)
  const [activityPage, setActivityPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [inviting, setInviting] = useState(false)
  const [invitation, setInvitation] = useState('')
  const [editing, setEditing] = useState<{ member: Member; remove: boolean } | null>(null)
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const [copied, setCopied] = useState(false)
  const members = useData<Page<Member>>(base + '/members?page=' + page, version)
  const activity = useData<Page<Activity>>(base + '/activity?page=' + activityPage, version)

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget))
    setError('')
    setPending(true)
    try {
      const response = await api<{ token: string }>(base + '/invitations', values)
      setInvitation(location.origin + '/join#' + response.token)
      setVersion(version + 1)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }

  async function saveMember(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    const values = Object.fromEntries(new FormData(event.currentTarget))
    setError('')
    setPending(true)
    try {
      await api(base + '/members/' + editing.member.id, editing.remove ? undefined : values, editing.remove ? 'DELETE' : 'PATCH')
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
    <Heading title="Users" description="Invite teammates and manage their access to this business."
      actions={<button className={primary} onClick={() => { setError(''); setInvitation(''); setCopied(false); setInviting(true) }}>Invite user</button>} />
    <Banner error={members.error} />
    {!members.error && <div className={section}>
      <Table items={members.data?.data} columns={[
        { title: 'Name', render: member => member.name },
        { title: 'Email', render: member => member.email },
        { title: 'Role', render: member => <span className="capitalize">{member.role}</span> },
        { title: 'Actions', render: member => member.role === 'owner' ? '—' : <div className="flex flex-wrap gap-2">
          <button className={button} onClick={() => { setError(''); setEditing({ member, remove: false }) }}>Change role</button>
          <button className={button} onClick={() => { setError(''); setEditing({ member, remove: true }) }}>Remove</button>
        </div> },
      ]} />
      <Pagination result={members.data} navigate={setPage} />
    </div>}
    <h2 className="text-lg font-bold">Business activity</h2>
    <Banner error={activity.error} />
    {!activity.error && <div className={section}>
      <Table items={activity.data?.data} columns={[
        { title: 'Time', render: item => item.created_at },
        { title: 'Activity', render: item => display(item.action.replaceAll('.', ' ')) },
        { title: 'User ID', render: item => item.user_id },
      ]} empty="No business activity has been recorded." />
      <Pagination result={activity.data} navigate={setActivityPage} />
    </div>}
    {inviting && <Dialog title="Invite a teammate" close={() => { if (!pending) setInviting(false) }}>
      <Banner error={error} />
      {invitation ? <div className="mt-3 grid gap-3">
        <p className="text-sm">Share this private link with your teammate. The link expires according to the platform invitation policy.</p>
        <Field title="Invitation link"><input className={input} value={invitation} readOnly onFocus={event => event.target.select()} /></Field>
        <button className={button} type="button" onClick={async () => {
          try { await navigator.clipboard.writeText(invitation); setCopied(true) }
          catch { setError('Select and copy the invitation link from the field.') }
        }}>{copied ? 'Copied' : 'Copy link'}</button>
      </div> : <form className="mt-3 grid gap-4" onSubmit={invite}>
        <Field title="Email"><input className={input} type="email" name="email" required maxLength={255} /></Field>
        <RoleControl />
        <button className={primary} type="submit" disabled={pending}>{pending ? 'Creating…' : 'Create invitation link'}</button>
      </form>}
    </Dialog>}
    {editing && <Dialog title={editing.remove ? 'Remove ' + editing.member.name + '?' : 'Change role for ' + editing.member.name} close={() => { if (!pending) setEditing(null) }}>
      <form className="grid gap-4" onSubmit={saveMember}>
        <Banner error={error} />
        {editing.remove ? <p>This user will lose access. Their historical transactions will be retained.</p> : <RoleControl role={editing.member.role} />}
        <button className={primary} type="submit" disabled={pending}>{pending ? 'Saving…' : editing.remove ? 'Remove access' : 'Save role'}</button>
      </form>
    </Dialog>}
  </>
}

function RoleControl({ role = 'staff' }: { role?: string }) {
  return <Field title="Role"><select className={input} name="role" defaultValue={role}>
    <option value="staff">Staff</option><option value="administrator">Administrator</option>
  </select></Field>
}

export function Profile({ user, refresh }: { user: Account['user']; refresh: () => void }) {
  return <>
    <Heading title="Your profile" description="Manage your name and sign-in credentials." />
    <ProfileForm user={user} refresh={refresh} />
    <ProfileForm user={user} refresh={refresh} password />
  </>
}

function ProfileForm({ user, refresh, password = false }: { user: Account['user']; refresh: () => void; password?: boolean }) {
  const [pending, setPending] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const values = Object.fromEntries(new FormData(form))
    setPending(true)
    setSaved(false)
    setError('')
    try {
      await api(password ? '/profile/password' : '/profile', values, password ? 'PUT' : 'PATCH')
      if (password) form.reset()
      else refresh()
      setSaved(true)
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setPending(false)
    }
  }
  return <form onSubmit={submit} className={section + ' grid max-w-2xl gap-4 p-5'}>
    <h2 className="text-lg font-bold">{password ? 'Change password' : 'Personal information'}</h2>
    <Banner error={error} />
    {saved && <p role="status" className="text-sm text-green-700 dark:text-green-300">{password ? 'Password updated.' : 'Profile saved.'}</p>}
    {password ? <>
      <Field title="Current password"><input className={input} name="current_password" type="password" autoComplete="current-password" required /></Field>
      <Field title="New password"><input className={input} name="password" type="password" autoComplete="new-password" minLength={12} required /></Field>
      <Field title="Confirm new password"><input className={input} name="password_confirmation" type="password" autoComplete="new-password" required /></Field>
    </> : <>
      <Field title="Name"><input className={input} name="name" autoComplete="name" defaultValue={user.name} maxLength={120} required /></Field>
      <Field title="Email"><input className={input} value={user.email} readOnly /></Field>
    </>}
    <button className={primary + ' justify-self-start'} type="submit" disabled={pending}>{pending ? 'Saving…' : password ? 'Update password' : 'Save profile'}</button>
  </form>
}
