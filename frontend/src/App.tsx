import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { AdminRoute, AuthRoute, PublicRoute, RootRedirect } from './auth/guards'
import AppLayout from './components/AppLayout'
import LoginPage from './routes/LoginPage'
import RegisterPage from './routes/RegisterPage'
import Dashboard from './routes/Dashboard'
import Trade from './routes/Trade'
import History from './routes/History'
import Wallet from './routes/Wallet'
import AdminLayout from './routes/admin/AdminLayout'
import Overview from './routes/admin/Overview'
import Users from './routes/admin/Users'
import Trades from './routes/admin/Trades'
import Approvals from './routes/admin/Approvals'
import NotFound from './routes/NotFound'

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          {/* Anonymous-only */}
          <Route element={<PublicRoute />}>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
          </Route>

          {/* Authenticated users — share the sidebar shell */}
          <Route element={<AuthRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/dashboard" element={<Dashboard />} />
              <Route path="/trade" element={<Trade />} />
              <Route path="/history" element={<History />} />
              <Route path="/wallet" element={<Wallet />} />
            </Route>

            {/* Admin-only — its own chrome */}
            <Route element={<AdminRoute />}>
              <Route path="/admin" element={<AdminLayout />}>
                <Route index element={<Overview />} />
                <Route path="users" element={<Users />} />
                <Route path="trades" element={<Trades />} />
                <Route path="approvals" element={<Approvals />} />
              </Route>
            </Route>
          </Route>

          <Route path="/" element={<RootRedirect />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
