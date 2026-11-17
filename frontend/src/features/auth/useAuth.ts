import { useContext } from 'react'
import { AuthContext, type AuthContextValue } from './session'

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}

/** The signed-in account; only call below <RequireAuth>. */
export function useAccount() {
  const { account } = useAuth()
  if (!account) throw new Error('useAccount must be used below <RequireAuth>')
  return account
}
