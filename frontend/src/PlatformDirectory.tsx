import { useState, type FormEvent } from 'react'
import { Building2, ShieldCheck, UserRound, UserRoundPlus } from 'lucide-react'
import { api, errorMessage, query, type Page } from './api'
import { Banner, Dialog, Field, Heading, Pagination, Table, button, input, primary, section, useData } from './workspace-ui'

const base = '/platform-admin'
type RecordKind = 'users' | 'businesses'
type DirectoryRecord = {
  id: number
  name: string
  email?: string
  currency?: string
  suspended_at: string | null
  platform_admin?: boolean | number
  member_count?: number
  membership_count?: number
  created_at: string
}
type Member = { id: number; name: string; email: string; role: string; suspended_at: string | null }
type Membership = DirectoryRecord & { pivot: { role: string } }
type Detail = DirectoryRecord & { members?: Page<Member>; memberships?: Page<Membership> }
type Confirmation = { title: string; note: string; label: string; save: (reason: string) => Promise<unknown> }
type Activity = {
  id: number; actor_name: string; action: string; target_type: 'user' | 'business'; target_id: number; reason: string; created_at: string;
  details: string | { user_id?: number; role?: string | null; previous_role?: string | null }
}

function Status({ suspended }: { suspended: boolean }) {
  return <span className={'directory-status ' + (suspended ? 'directory-status-suspended' : '')}>{suspended ? 'Suspended' : 'Active'}</span>
}

export function PlatformDirectory({ kind }: { kind: RecordKind }) {
  const users = kind === 'users'
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [detailPage, setDetailPage] = useState(1)
  const [version, setVersion] = useState(0)
  const [selected, setSelected] = useState<number | null>(() => Number(new URLSearchParams(location.search).get('record')) || null)
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null)
  const [addMember, setAddMember] = useState(false)
  const [notice, setNotice] = useState('')
  const list = useData<Page<DirectoryRecord>>(base + '/' + kind + query({ search, status, page }), version)
  const detail = useData<Detail>(selected ? base + '/' + kind + '/' + selected + query({ page: detailPage }) : null, version)

  function resetAction() {
    setConfirmation(null)
    setAddMember(false)
    setNotice('')
  }

  function select(id: number | null) {
    resetAction()
    setSelected(id)
    setDetailPage(1)
  }

  function changeStatus(row: DirectoryRecord) {
    const suspended = !row.suspended_at
    setConfirmation({
      title: (suspended ? 'Suspend ' : 'Reactivate ') + row.name + '?',
      note: suspended
        ? users ? 'The user will be signed out and prevented from signing in.' : 'Members will lose access to this business. Its records and memberships will be retained.'
        : users ? 'The user can sign in again.' : 'Active members will regain access to this workspace.',
      label: suspended ? 'Suspend' : 'Reactivate',
      save: reason => api(base + '/' + kind + '/' + row.id + '/status', { suspended, reason }, 'PUT'),
    })
  }

  function changeMember(row: Member, role: string | null) {
    const transferring = role === 'owner'
    setConfirmation({
      title: transferring ? 'Transfer ownership to ' + row.name + '?' : role ? 'Change role for ' + row.name + '?' : 'Remove ' + row.name + '?',
      note: transferring ? 'The current owner will become a business administrator.' : role ? 'Their business permissions will change immediately.' : 'The user will lose access to this business.',
      label: transferring ? 'Transfer ownership' : role ? 'Update role' : 'Remove membership',
      save: reason => api(base + '/businesses/' + selected + '/members/' + row.id, role ? { role, reason } : { reason }, role ? 'PUT' : 'DELETE'),
    })
  }

  function saved() {
    resetAction()
    setVersion(previous => previous + 1)
    setDetailPage(1)
    setNotice('Access settings saved.')
  }

  return <>
    <Heading title={users ? 'Users' : 'Businesses'} description={users ? 'Review accounts, business memberships, and sign-in access.' : 'Manage tenant access, memberships, and ownership.'} />
    <div className={section + ' directory-filters'}>
      <Field title={users ? 'Search by name or email' : 'Search by business name'}><input type="search" className={input} value={search} maxLength={160} onChange={event => { setPage(1); setSearch(event.target.value) }} /></Field>
      <Field title="Status"><select className={input} value={status} onChange={event => { setPage(1); setStatus(event.target.value) }}><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select></Field>
    </div>
    <Banner error={list.error} />
    {notice && !selected && <p role="status" className="status-success">{notice}</p>}
    {!list.error && <div className={section}>
      <Table items={list.data?.data} empty={users ? 'No users match these filters.' : 'No businesses match these filters.'} columns={[
        { title: users ? 'User' : 'Business', render: row => <button className="directory-record" onClick={() => select(row.id)}>{users ? <UserRound size={17} /> : <Building2 size={17} />}<span><strong>{row.name}</strong><small>{users ? row.email : row.currency}</small></span></button> },
        { title: 'Status', render: row => <Status suspended={!!row.suspended_at} /> },
        { title: users ? 'Memberships' : 'Members', render: row => users ? row.membership_count : row.member_count },
        ...(users ? [{ title: 'Platform access', render: (row: DirectoryRecord) => row.platform_admin ? 'Administrator' : 'Standard account' }] : []),
        { title: 'Created', render: row => row.created_at ? new Date(row.created_at).toLocaleDateString() : '—' },
        { title: 'Actions', render: row => <button className={button} onClick={() => select(row.id)}>Manage</button> },
      ]} />
      <Pagination result={list.data} navigate={setPage} />
    </div>}
    {selected && !confirmation && !addMember && <Dialog title={detail.data?.name ?? (users ? 'User details' : 'Business details')} close={() => select(null)}>
      <Banner error={detail.error} />
      {!detail.error && !detail.data && <p role="status">Loading details…</p>}
      {detail.data && <div className="grid gap-4">
        <div className="flex flex-wrap items-center gap-3"><Status suspended={!!detail.data.suspended_at} /><span>{detail.data.email ?? detail.data.currency}</span>
          {detail.data.platform_admin ? <span className="inline-flex items-center gap-1.5 text-sm"><ShieldCheck size={16} /> Platform administrator</span>
            : <button type="button" className={button} onClick={() => changeStatus(detail.data!)}>{detail.data.suspended_at ? 'Reactivate' : 'Suspend'}</button>}
        </div>
        {notice && <p role="status" className="status-success">{notice}</p>}
        {users ? <>
          <h3 className="font-bold">Business memberships</h3>
          <Table items={detail.data.memberships?.data} empty="This user does not belong to any business." columns={[
            { title: 'Business', render: row => <a className="font-semibold underline" href={'/admin/businesses?record=' + row.id}>{row.name}</a> },
            { title: 'Status', render: row => <Status suspended={!!row.suspended_at} /> },
            { title: 'Role', render: row => <span className="capitalize">{row.pivot.role}</span> },
          ]} />
          <Pagination result={detail.data.memberships} navigate={setDetailPage} />
        </> : <>
          <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">Business members</h3>
            <button type="button" className={primary} onClick={() => { resetAction(); setAddMember(true) }}><UserRoundPlus className="mr-1.5" size={16} />Add existing user</button>
          </div>
          <Table items={detail.data.members?.data} empty="This business does not have any members." columns={[
            { title: 'Member', render: row => <a className="font-semibold underline" href={'/admin/users?record=' + row.id}>{row.name}</a> },
            { title: 'Role', render: row => <span className="capitalize">{row.role}</span> },
            { title: 'Status', render: row => <Status suspended={!!row.suspended_at} /> },
            { title: 'Actions', render: row => row.role === 'owner' ? '—' : <div className="flex flex-wrap gap-2">
              {!row.suspended_at && <>
                <button type="button" className={button} onClick={() => changeMember(row, row.role === 'staff' ? 'administrator' : 'staff')}>{row.role === 'staff' ? 'Make administrator' : 'Make staff'}</button>
                <button type="button" className={button} onClick={() => changeMember(row, 'owner')}>Transfer ownership</button>
              </>}
              <button type="button" className={button} onClick={() => changeMember(row, null)}>Remove</button>
            </div> },
          ]} />
          <Pagination result={detail.data.members} navigate={setDetailPage} />
        </>}
      </div>}
    </Dialog>}
    {confirmation && <ActionDialog action={confirmation} done={saved} close={() => setConfirmation(null)} />}
    {addMember && selected && <MemberDialog business={selected} done={saved} close={() => setAddMember(false)} />}
  </>
}

function ActionDialog({ action, close, done }: { action: Confirmation; close: () => void; done: () => void }) {
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const reason = String(new FormData(event.currentTarget).get('reason'))
    setPending(true); setError('')
    try { await action.save(reason); done() }
    catch (failure) { setError(errorMessage(failure)); setPending(false) }
  }
  return <Dialog title={action.title} close={() => { if (!pending) close() }}>
    <form className="grid gap-4" onSubmit={submit}>
      <p>{action.note}</p><Banner error={error} />
      <Field title="Reason"><textarea className={input} name="reason" rows={3} maxLength={1000} required /></Field>
      <button type="submit" className={primary} disabled={pending}>{pending ? 'Saving…' : action.label}</button>
    </form>
  </Dialog>
}

function MemberDialog({ business, close, done }: { business: number; close: () => void; done: () => void }) {
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState('')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const users = useData<Page<DirectoryRecord>>(search.trim().length > 1 ? base + '/users' + query({ search: search.trim(), status: 'active' }) : null)
  const choices = users.data?.data.filter(row => !row.platform_admin) ?? []
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPending(true); setError('')
    try { await api(base + '/businesses/' + business + '/members/' + selected, Object.fromEntries(new FormData(event.currentTarget)), 'PUT'); done() }
    catch (failure) { setError(errorMessage(failure)); setPending(false) }
  }
  return <Dialog title="Add an existing user" close={() => { if (!pending) close() }}>
    <form className="grid gap-4" onSubmit={submit}>
      <Banner error={error || users.error} />
      <Field title="Find a user"><input type="search" className={input} value={search} onChange={event => { setSearch(event.target.value); setSelected('') }} placeholder="Name or email" maxLength={160} /></Field>
      <Field title="User"><select className={input} value={selected} required onChange={event => setSelected(event.target.value)}><option value="">{choices.length ? 'Select an account' : 'Search for an active account'}</option>{choices.map(row => <option key={row.id} value={row.id}>{row.name} · {row.email}</option>)}</select></Field>
      <Field title="Business role"><select className={input} name="role"><option value="staff">Staff</option><option value="administrator">Administrator</option></select></Field>
      <Field title="Reason"><textarea name="reason" className={input} rows={3} maxLength={1000} required /></Field>
      <button type="submit" className={primary} disabled={!selected || pending}>{pending ? 'Saving…' : 'Add user'}</button>
    </form>
  </Dialog>
}

export function PlatformActivity() {
  const [page, setPage] = useState(1)
  const activity = useData<Page<Activity>>(base + '/activity?page=' + page)
  return <>
    <Heading title="Platform activity" description="Review who changed access, memberships, and ownership, and why." />
    <Banner error={activity.error} />
    {!activity.error && <div className={section}><Table items={activity.data?.data} empty="No administrative actions have been recorded." columns={[
      { title: 'Action', render: row => <span className="capitalize">{row.action.replace(/[._]/g, ' ')}</span> },
      { title: 'Target', render: row => <a href={'/admin/' + (row.target_type === 'user' ? 'users' : 'businesses') + '?record=' + row.target_id} className="font-semibold underline">{row.target_type === 'user' ? 'User' : 'Business'} #{row.target_id}</a> },
      { title: 'Administrator', render: row => row.actor_name },
      { title: 'Reason', render: row => row.reason },
      { title: 'Time', render: row => new Date(row.created_at).toLocaleString() },
    ]} /><Pagination result={activity.data} navigate={setPage} /></div>}
  </>
}
