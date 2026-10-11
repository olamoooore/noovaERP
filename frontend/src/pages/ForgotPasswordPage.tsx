import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

export function ForgotPasswordPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setLoading(true)
    setError(null)
    try {
      await api.post('/auth/forgot-password', { email })
      setSent(true)
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Unable to send the reset link. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen w-full flex-col bg-white px-6 py-5 text-slate-950 sm:px-10 lg:px-14">
      <header className="flex items-center justify-between">
        <Link to="/" className="flex items-center">
          <img src={NOOVAERP_LOGO_URL} alt="Noovaerp" className="h-8 w-auto object-contain" />
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
          {sent ? (
            <>
              <div className="mb-6 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                <Icon icon="solar:letter-opened-bold-duotone" className="h-6 w-6" />
              </div>
              <h1 className="text-[28px] font-semibold leading-tight tracking-tight">Check your inbox</h1>
              <p className="mt-2 text-sm leading-6 text-slate-500">
                If <span className="font-medium text-slate-900">{email}</span> belongs to a workspace account,
                we've emailed a link to set a new password. It stays valid for 7 days.
              </p>
              <Button
                className="mt-6 h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                onClick={() => navigate('/login')}
              >
                Back to sign in
                <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
              </Button>
            </>
          ) : (
            <>
              <div className="mb-6 space-y-1.5">
                <h1 className="text-[28px] font-semibold leading-tight tracking-tight">Reset your password</h1>
                <p className="text-sm leading-6 text-slate-500">
                  Enter your work email and we'll send you a link to choose a new password.
                </p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="email" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Work email
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:letter-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="email"
                      type="email"
                      placeholder="you@company.com"
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      required
                      autoComplete="email"
                      className="h-11 rounded-lg border-slate-200 bg-white pl-10 text-[15px] text-slate-950 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] transition focus-visible:border-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900/10"
                    />
                  </div>
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
                  disabled={loading || !email}
                >
                  {loading ? (
                    <>
                      <Icon icon="solar:restart-circle-bold" className="mr-2 h-[18px] w-[18px] animate-spin" />
                      Sending link
                    </>
                  ) : (
                    <>
                      Send reset link
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

export default ForgotPasswordPage