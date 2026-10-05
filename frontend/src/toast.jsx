import { createContext, useCallback, useContext, useState } from 'react'

const ToastContext = createContext(() => {})

export function ToastProvider({ children }) {
  const [toast, setToast] = useState(null)

  const notify = useCallback((message, type = 'ok') => {
    setToast({ message, type })
    setTimeout(() => setToast(null), 3200)
  }, [])

  return (
    <ToastContext.Provider value={notify}>
      {children}
      {toast && <div className={`toast ${toast.type}`}>{toast.message}</div>}
    </ToastContext.Provider>
  )
}

export function useToast() {
  return useContext(ToastContext)
}
