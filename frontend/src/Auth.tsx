import { lazy, Suspense, useEffect, useState, type FormEvent } from 'react'
import { api, ApiError, errorMessage, type Account } from './api'
import { Boxes, CircleCheck, KeyRound, PackageCheck, ShieldCheck } from 'lucide-react'
import type { Platform } from './platform'
import { Banner, Field, input, primary } from './workspace-ui'

const Workspace = lazy(() => import('./Workspace'))
const Admin = lazy(() => import('./Admin'))
export type AuthPage = '/login' | '/register' | '/workspace' | '/join' | '/admin' | '/forgot-password' | '/reset-password'

export function Auth({ page, dark, platform }: { page: AuthPage; dark: boolean; platform?: Platform }) {
  const [account, setAccount] = useState<Account | null>(null)
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const [loading, setLoading] = useState(true)
  const [version, setVersion] = useState(0)
  const [notice, setNotice] = useState('')
  const reset = new URLSearchParams(location.hash.slice(1))
  const recovery = page === '/forgot-password' || page === '/reset-password'
  const internal = page === '/workspace' || page === '/admin'
  const minimum = platform?.password_minimum ?? 12
  const register = page === '/register'
  const token = /^[A-Za-z0-9]{64}$/.test(location.hash.slice(1)) ? location.hash.slice(1) : ''
  const invitation = token ? '#' + token : ''

  useEffect(() => {
    const controller = new AbortController()
    api<Account>('/account', undefined, 'GET', controller.signal).then(
      result => setAccount(result),
      failure => {
        if (controller.signal.aborted) return
        if (!(failure instanceof ApiError) || failure.status !== 401) setError(errorMessage(failure))
        else setAccount(null)
      },
    ).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [version])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setPending(true)
    const values = Object.fromEntries(new FormData(event.currentTarget))
    try {
      if (recovery) {
        await api(page, { ...values, ...(page === '/reset-password' ? { token: reset.get('token') ?? location.pathname.split('/').pop() } : {}) })
        setNotice(page === '/forgot-password' ? 'If an account exists, a reset link will be sent to that address.' : 'Password updated. You can now sign in.')
        setPending(false)
        return
      }
      const result = await api<Account>(page, {
        ...values,
        ...(register ? { legal_version: platform?.legal_version, ...(token ? { token } : {}) } : {}),
      })
      const destination = new URLSearchParams(location.search).get('next') === 'admin' && result.user.platform_admin ? '/admin' : result.user.platform_admin && result.businesses.length === 0 ? '/admin' : '/workspace'
      location.assign(!register && token ? '/join' + invitation : destination)
    } catch (failure) {
      setError(errorMessage(failure))
      setPending(false)
    }
  }

  async function accept() {
    setError('')
    setPending(true)
    try {
      await api('/invitations/accept', { token })
      location.replace('/workspace')
    } catch (failure) {
      setError(errorMessage(failure))
      setPending(false)
    }
  }

  if (!loading && account && page !== '/join') {
    if (!internal) {
      return <main className="mx-auto max-w-lg px-6 py-16">
        <h1 className="text-2xl font-bold">You’re signed in</h1>
        <a className={'mt-6 inline-block ' + primary} href={token ? '/join' + invitation : account.user.platform_admin && !account.businesses.length ? '/admin' : '/workspace'}>{token ? 'Continue to invitation' : 'Open workspace'}</a>
      </main>
    }
    return <Suspense fallback={<main className="p-6"><p role="status">Loading workspace…</p></main>}>
      {page === '/admin' ? account.user.platform_admin ? <Admin account={account} /> :
        <main className="mx-auto max-w-lg p-8"><h1 className="text-2xl font-bold">Platform access required</h1><a className="mt-4 inline-block underline" href="/workspace">Return to workspace</a></main>
        : <Workspace account={account} dark={dark} refresh={() => setVersion(previous => previous + 1)} />}
    </Suspense>
  }

  return (
    <main className="site-width auth-layout">
      <aside className="auth-aside">
        <div className="auth-symbol"><Boxes size={27} /></div>
        <h2>{platform?.name ?? 'InvenPilot'} brings your stock into focus.</h2>
        <p>Follow inventory from the first delivery to the final sale.</p>
        <ul><li><PackageCheck size={18} /> Track stock across the entire purchase and sales workflow</li><li><ShieldCheck size={18} /> Keep business records and permissions separate</li><li><CircleCheck size={18} /> Review every stock movement</li></ul>
      </aside>
      <div className="auth-panel">
      <Banner error={error} />
      {loading ? <p role="status" className="mt-4">Checking your session…</p> : internal ? (
        <>
          <h1 className="mb-6 text-2xl font-bold">Sign in to your workspace</h1>
          <a className={primary} href={page === '/admin' ? '/login?next=admin' : '/login'}>Sign in</a>
        </>
      ) : recovery ? (
        <div className="grid gap-5">
          <KeyRound className="text-blue-600 dark:text-blue-300" size={26} />
          <h1 className="text-3xl font-bold">{page === '/forgot-password' ? 'Forgot password?' : 'Choose a new password'}</h1>
          {notice ? <p role="status">{notice}</p> : platform?.password_reset_enabled === false ? <p>Password reset is disabled for this installation.</p> :
            <form className="grid gap-4" onSubmit={submit}>
              <Field title="Email"><input className={input} type="email" name="email" autoComplete="email" defaultValue={reset.get('email') ?? new URLSearchParams(location.search).get('email') ?? ''} required /></Field>
              {page === '/reset-password' && <>
                <Field title="New password"><input className={input} type="password" name="password" autoComplete="new-password" minLength={minimum} required /></Field>
                <Field title="Confirm new password"><input className={input} type="password" name="password_confirmation" autoComplete="new-password" required /></Field>
              </>}
              <button className={primary} type="submit" disabled={pending}>{page === '/forgot-password' ? 'Send reset link' : 'Update password'}</button>
            </form>}
          <a className="text-sm font-semibold underline" href="/login">Back to sign in</a>
        </div>
      ) : page === '/join' ? (
        <div className="grid gap-4">
          <h1 className="text-3xl font-bold">Join a business</h1>
          {!token ? <p>This invitation link is invalid. Ask the business owner to create a new link.</p> : account ? <>
            <p>You’re signed in as {account.user.email}. Accept the invitation to add the business to your workspace.</p>
            <button className={primary + ' justify-self-start'} onClick={accept} disabled={pending} type="button">{pending ? 'Joining…' : 'Accept invitation'}</button>
          </> : <>
            <p>Use the email address the business owner invited.</p>
            <div className="flex flex-wrap items-center gap-4"><a href={'/login' + invitation} className={primary}>Sign in</a><a href={'/register' + invitation} className="font-semibold underline">Create an account</a></div>
          </>}
        </div>
      ) : register && !token && platform?.registration_enabled === false ? (
        <><h1 className="text-3xl font-bold">Registration is closed</h1><a className="mt-5 inline-block underline" href="/login">Sign in to an existing account</a></>
      ) : (
        <>
          <h1 className="mt-2 text-3xl font-bold tracking-tight">{register ? token ? 'Create your account' : 'Create your business account' : 'Sign in'}</h1>
          <form className="mt-8 grid gap-5" onSubmit={submit}>
            {register && <Field title="Full name"><input className={input} name="name" autoComplete="name" required maxLength={120} /></Field>}
            {register && !token && <>
              <Field title="Business name"><input className={input} name="business_name" autoComplete="organization" required maxLength={160} /></Field>
              <Field title="Currency"><select className={input} name="currency" key={platform?.default_currency} defaultValue={platform?.default_currency ?? 'USD'}>
                {(platform?.currencies ?? ['USD']).map(code => <option key={code} value={code}>{code}</option>)}
              </select></Field>
            </>}
            <Field title="Email"><input className={input} type="email" name="email" autoComplete="email" required maxLength={255} /></Field>
            <Field title="Password"><input className={input} type="password" name="password" autoComplete={register ? 'new-password' : 'current-password'} required minLength={register ? minimum : undefined} aria-describedby={register ? 'password-hint' : undefined} /></Field>
            {register && <>
              <p className="-mt-3 text-sm opacity-75" id="password-hint">Use at least {minimum} characters.</p>
              <Field title="Confirm password"><input className={input} type="password" name="password_confirmation" autoComplete="new-password" required /></Field>
              <div className="flex items-start gap-2.5 text-sm"><input type="checkbox" id="accept-legal" name="legal_accepted" value="1" required className="mt-1" /><label htmlFor="accept-legal">I agree to the <a className="underline" href="/terms" target="_blank" rel="noopener">Terms of service</a> and <a className="underline" href="/privacy" target="_blank" rel="noopener">Privacy policy</a>.</label></div>
            </>}
            {!register && platform?.password_reset_enabled !== false && <a className="justify-self-end text-sm font-semibold underline" href="/forgot-password">Forgot password?</a>}
            <button className={primary} disabled={pending || (register && !platform)} type="submit">
              {pending ? 'Please wait…' : register ? token ? 'Create account' : 'Create business' : 'Sign in'}
            </button>
          </form>
          <a className="mt-6 inline-block text-sm font-semibold underline" href={(register ? '/login' : '/register') + invitation}>
            {register ? 'Already have an account? Sign in' : 'Create an account'}
          </a>
        </>
      )}
      </div>
    </main>
  )
}
