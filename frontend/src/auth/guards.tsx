import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth, homePathFor } from './AuthContext'

function FullScreenLoader() {
  return (
    <div className="min-h-screen flex items-center justify-center text-gray-500">
      Loading…
    </div>
  )
}

/** Routes only available to NOT-logged-in users (e.g. /login, /register). */
export function PublicRoute() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  if (user) return <Navigate to={homePathFor(user)} replace />
  return <Outlet />
}

/** Routes that require any authenticated user. Sends to /login otherwise,
 *  remembering the originally-requested URL in router state. */
export function AuthRoute() {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

/** Routes restricted to role=admin. Logged-in non-admins are bounced to
 *  their normal dashboard. Anonymous users go through AuthRoute first. */
export function AdminRoute() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace />
  if (user.role !== 'admin') return <Navigate to="/dashboard" replace />
  return <Outlet />
}

/** Routes that only regular users see — admins are bounced to the admin
 *  console. Used to keep admins out of the fund-request forms. */
export function UserOnlyRoute() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace />
  if (user.role === 'admin') return <Navigate to="/admin/approvals" replace />
  return <Outlet />
}

/** "/" redirector: send to dashboard/admin if logged in, else to /login. */
export function RootRedirect() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  return <Navigate to={user ? homePathFor(user) : '/login'} replace />
}
