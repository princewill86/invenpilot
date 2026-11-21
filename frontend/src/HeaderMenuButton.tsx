import { createPortal } from 'react-dom'
import { Menu, X } from 'lucide-react'
import type { useMobileMenu } from './useMobileMenu'

export function HeaderMenuButton({ menu, controls, label }: {
  menu: ReturnType<typeof useMobileMenu>
  controls: string
  label: string
}) {
  const header = document.getElementById('app-header-menu')
  if (!header) return null

  return createPortal(
    <button
      type="button"
      className="icon-button app-menu-toggle"
      aria-label={label}
      aria-controls={controls}
      aria-expanded={menu.open}
      ref={menu.toggle}
      onClick={() => menu.setOpen(!menu.open)}
    >
      {menu.open ? <X size={19} aria-hidden="true" /> : <Menu size={19} aria-hidden="true" />}
    </button>,
    header,
  )
}
