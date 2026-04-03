import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { adminApi } from '@/lib/admin-api/client'
import { useAuthStore, type AdminUser } from '@/lib/store/auth'

interface LoginResponse {
  token: string
  user: AdminUser
}

export function AdminLoginPage() {
  const navigate = useNavigate()
  const setAuth = useAuthStore((s) => s.setAuth)

  const [email, setEmail]       = useState('')
  const [password, setPassword] = useState('')
  const [error, setError]       = useState<string | null>(null)
  const [loading, setLoading]   = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)

    try {
      const { data } = await adminApi.post<LoginResponse>('/auth/login', {
        email,
        password,
      })

      setAuth(data.token, data.user)
      navigate('/', { replace: true })
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const validationErrors = axiosError.response?.data?.errors
      if (validationErrors) {
        const first = Object.values(validationErrors)[0]
        setError(Array.isArray(first) ? first[0] : String(first))
      } else {
        setError(axiosError.response?.data?.message ?? 'Login failed. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-950 via-brand-800 to-brand-600 p-4">
      <div className="w-full max-w-md">
        {/* Card */}
        <div className="rounded-2xl bg-white p-8 shadow-xl">
          {/* Header */}
          <div className="mb-8 flex flex-col items-center gap-3">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 shadow-lg">
              <Icon icon="solar:shield-star-bold-duotone" className="text-white" width={28} />
            </div>
            <div className="text-center">
              <h1 className="text-xl font-bold text-gray-900">NoovaERP</h1>
              <p className="text-sm text-gray-500">Superadmin Control Plane</p>
            </div>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-gray-700">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                placeholder="admin@noovaerp.com"
              />
            </div>

            <div>
              <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-700">
                Password
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                placeholder="••••••••"
              />
            </div>

            {error && (
              <div className="flex items-start gap-2 rounded-lg bg-red-50 px-3.5 py-2.5 text-sm text-red-700">
                <Icon icon="solar:danger-circle-bold-duotone" width={16} className="mt-0.5 shrink-0" />
                {error}
              </div>
            )}

            <button
              type="submit"
              disabled={loading}
              className="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? (
                <>
                  <Icon icon="solar:refresh-bold-duotone" className="animate-spin" width={16} />
                  Signing in…
                </>
              ) : (
                <>
                  <Icon icon="solar:login-3-bold-duotone" width={16} />
                  Sign in
                </>
              )}
            </button>
          </form>
        </div>

        <p className="mt-4 text-center text-xs text-white/50">
          Platform access only. Not a tenant login.
        </p>
      </div>
    </div>
  )
}
