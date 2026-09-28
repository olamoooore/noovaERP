import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

export function ResetPasswordPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const invalidLink = !token || !email
  const passwordsMatch = password.length > 0 && password === confirmPassword
  const canSubmit = !invalidLink && password.length >= 8 && passwordsMatch

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!canSubmit) return
    setLoading(true)
    setError(null)
    try {
      await api.post('/auth/reset-password', {
        email,
        token,
        password,
        password_confirmation: confirmPassword,
      })
      setDone(true)
    } catch (err: any) {
      const message =
        err?.response?.data?.errors?.token?.[0] ||
        err?.response?.data?.errors?.password?.[0] ||
        err?.response?.data?.message ||
        'Unable to update your password. Please request a new link.'
      setError(message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen w-full flex-col bg-white px-6 py-5 text-slate-950 sm:px-10 lg:px-14">
      <header className="flex items-center justify-between">
        <Link to="/" className="flex items-center">
          <img src={NOOVAERP_LOGO_URL} alt="Noovaerp" className="h-9 w-auto object-contain" />
        </Link>
        <Link
          to="/login"
          className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900"
        >
          <Icon icon="solar:arrow-left-linear" className="h-3.5 w-3.5" />
          Back to sign in
        </Link>
      </header>

      <div className="flex flex-1 items-center justify-center">
        <div className="w-full max-w-[420px]">
          {invalidLink ? (
            <>
              <div className="mb-6 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400">
                <Icon icon="solar:link-broken-bold-duotone" className="h-6 w-6" />
              </div>
              <h1 className="text-[28px] font-semibold leading-tight tracking-tight">This link is incomplete</h1>
              <p className="mt-2 text-sm leading-6 text-slate-500">
                The password link is missing its token or email. Request a fresh link to continue.
              </p>
              <Button
                className="mt-6 h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                onClick={() => navigate('/forgot-password')}
              >
                Request a new link
                <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
              </Button>
            </>
          ) : done ? (
            <>
              <div className="mb-6 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                <Icon icon="solar:check-circle-bold-duotone" className="h-6 w-6" />
              </div>
              <h1 className="text-[28px] font-semibold leading-tight tracking-tight">Password updated</h1>
              <p className="mt-2 text-sm leading-6 text-slate-500">
                Your password has been changed and any previous sessions were signed out. You can sign in now.
              </p>
              <Button
                className="mt-6 h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                onClick={() => navigate('/login')}
              >
                Sign in
                <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
              </Button>
            </>
          ) : (
            <>
              <div className="mb-6 space-y-1.5">
                <h1 className="text-[28px] font-semibold leading-tight tracking-tight">Choose a new password</h1>
                <p className="text-sm leading-6 text-slate-500">
                  Setting the password for <span className="font-medium text-slate-900">{email}</span>.
                </p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="password" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    New password
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:lock-password-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="password"
                      type={showPassword ? 'text' : 'password'}
                      placeholder="At least 8 characters"
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      required
                      minLength={8}
                      autoComplete="new-password"
                      className="h-11 rounded-lg border-slate-200 bg-white pl-10 pr-11 text-[15px] text-slate-950 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] transition focus-visible:border-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900/10"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((current) => !current)}
                      className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition hover:text-slate-900"
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                    >
                      <Icon icon={showPassword ? 'solar:eye-closed-bold' : 'solar:eye-bold'} className="h-[18px] w-[18px]" />
                    </button>
                  </div>
                  {password.length > 0 && password.length < 8 && (
                    <p className="text-xs text-amber-600 dark:text-amber-400">Use at least 8 characters.</p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="confirm-password" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Confirm password
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:lock-keyhole-minimalistic-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="confirm-password"
                      type={showPassword ? 'text' : 'password'}
                      placeholder="Repeat the password"
                      value={confirmPassword}
                      onChange={(event) => setConfirmPassword(event.target.value)}
                      required
                      autoComplete="new-password"
                      className="h-11 rounded-lg border-slate-200 bg-white pl-10 text-[15px] text-slate-950 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] transition focus-visible:border-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900/10"
                    />
                  </div>
                  {confirmPassword.length > 0 && !passwordsMatch && (
                    <p className="text-xs text-red-600 dark:text-red-400">Passwords do not match.</p>
                  )}
                </div>

                {error && (
                  <p className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                    <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {error}
                  </p>
                )}

                <Button
                  type="submit"
                  className="h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                  disabled={!canSubmit || loading}
                >
                  {loading ? (
                    <>
                      <Icon icon="solar:restart-circle-bold" className="mr-2 h-[18px] w-[18px] animate-spin" />
                      Updating password
                    </>
                  ) : (
                    <>
                      Update password
                      <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
                    </>
                  )}
                </Button>
              </form>
            </>
          )}
        </div>
      </div>

      <footer className="text-[11px] text-slate-400">
        <span>© {new Date().getFullYear()} NoovaERP</span>
      </footer>
    </div>
  )
}

export default ResetPasswordPage