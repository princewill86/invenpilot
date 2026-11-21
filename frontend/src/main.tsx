import { StrictMode, useEffect, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { Menu, Moon, Sun, X } from 'lucide-react'
import { Auth, type AuthPage } from './Auth'
import { Brand, Landing, Policy, SiteFooter } from './PublicPages'
import { useData } from './workspace-ui'
import type { Platform } from './platform'
import { useMobileMenu } from './useMobileMenu'
import './styles.css'

function App() {
  const [dark, setDark] = useState(() => localStorage.getItem('invenpilot-theme') === 'dark')
  const menu = useMobileMenu()
  const { data: platform } = useData<Platform>('/platform')
  const page = location.pathname.replace(/\/$/, '') || '/'
  const internal = /^\/(workspace|admin)(\/|$)/.test(page)
  const authPage = (internal ? page.startsWith('/admin') ? '/admin' : '/workspace'
    : page === '/reset-password' || page.startsWith('/reset-password/') ? '/reset-password'
    : ['/login', '/register', '/join', '/forgot-password'].includes(page) ? page : null) as AuthPage | null

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark)
    localStorage.setItem('invenpilot-theme', dark ? 'dark' : 'light')
  }, [dark])

  useEffect(() => {
    if (!platform) return
    document.title = page === '/' ? platform.seo_title : platform.name
    document.querySelector('meta[name=description]')?.setAttribute('content', platform.seo_description)
    document.querySelector('#platform-favicon')?.setAttribute('href', platform.favicon_url ?? '/favicon.svg')
  }, [platform, page])

  return <>
    <header ref={menu.container} className={'site-header print:hidden ' + (internal ? 'site-header-internal' : '')}>
      <div className={internal ? 'app-header-inner' : 'site-width site-header-inner'}>
        <Brand platform={platform} href={internal ? authPage === '/admin' ? '/admin' : '/workspace' : '/'} />

        {internal && <span className="app-header-context">{authPage === '/admin' ? 'Platform administration' : 'Business workspace'}</span>}
        <div className="site-header-actions">
          <button className="icon-button" type="button" aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'} onClick={() => setDark(!dark)}>{dark ? <Sun size={18} /> : <Moon size={18} />}</button>
          {internal && <span id="app-header-menu" />}
          {!internal && <><a className="site-sign-in" href="/login">Sign in</a>{platform?.registration_enabled !== false && <a className="action-primary header-cta" href="/register">Get started</a>}<button className="icon-button site-menu-toggle" type="button" aria-label="Toggle site navigation" aria-controls="site-navigation" ref={menu.toggle} aria-expanded={menu.open} onClick={() => menu.setOpen(!menu.open)}>{menu.open ? <X size={19} /> : <Menu size={19} />}</button></>}
        </div>
        {!internal && <nav id="site-navigation" className={menu.open ? 'site-nav open' : 'site-nav'} onClick={event => { if ((event.target as HTMLElement).closest('a')) menu.setOpen(false) }} aria-label="Main navigation"><a href="/#features">Features</a><a href="/#workflow">How it works</a><a href="/#faq">FAQ</a><div className="mobile-site-account"><a href="/login">Sign in</a>{platform?.registration_enabled !== false && <a href="/register" className="action-primary">Get started</a>}</div></nav>}
      </div>
    </header>
    {authPage ? <Auth page={authPage} dark={dark} platform={platform} /> : page === '/privacy' || page === '/terms' ? <Policy privacy={page === '/privacy'} platform={platform} /> : <Landing platform={platform} />}
    {!internal && <SiteFooter platform={platform} />}
  </>
}

createRoot(document.getElementById('root')!).render(<StrictMode><App /></StrictMode>)
