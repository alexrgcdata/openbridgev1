import { useEffect, useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import './modal.css'

/** Native modal behavior supplies focus containment, Escape and focus restoration. */
export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  const startedOutside = useRef(false)
  useEffect(() => {
    const element = dialog.current!
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const previousOverflow = document.body.style.overflow
    element.showModal()
    document.body.style.overflow = 'hidden'
    return () => {
      element.close()
      document.body.style.overflow = previousOverflow
      if (opener?.isConnected) opener.focus({ preventScroll: true })
    }
  }, [])
  const outside = (x: number, y: number) => {
    const rect = dialog.current!.getBoundingClientRect()
    return x < rect.left || x > rect.right || y < rect.top || y > rect.bottom
  }
  return createPortal(
    <dialog ref={dialog} className="app-dialog" aria-labelledby={titleId}
      onCancel={(event) => { event.preventDefault(); onClose() }}
      onPointerDown={(event) => { startedOutside.current = event.target === event.currentTarget && outside(event.clientX, event.clientY) }}
      onClick={(event) => {
        if (startedOutside.current && event.target === event.currentTarget && outside(event.clientX, event.clientY)) onClose()
        startedOutside.current = false
      }}>
      <header className="app-dialog-header">
        <h2 id={titleId}>{title}</h2>
        <button type="button" className="app-dialog-close" aria-label="Close dialog" onClick={onClose}>×</button>
      </header>
      <div className="app-dialog-body">{children}</div>
    </dialog>, document.body,
  )
}
