import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAuthStore } from '@/store/authStore'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

export function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const navigate = useNavigate()
  const setAuth = useAuthStore((state) => state.setAuth)
  const { toast } = useToast()

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)

    try {
      // Use the central auth endpoint (not tenant-scoped)
      const response = await api.post('/auth/login', { email, password })
      setAuth(response.data.user, response.data.token, response.data.tenant?.id)
      toast({
        title: 'Login successful',
        description: 'Welcome back!',
      })
      navigate('/')
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Login failed',
        description: error.response?.data?.message || 'Invalid credentials',
      })
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="h-screen w-full overflow-hidden bg-white text-slate-950">
      <div className="grid h-full w-full lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
        {/* Left — form column (light, logo retains color) */}
        <section className="relative flex h-full flex-col overflow-hidden bg-white px-6 py-5 sm:px-10 lg:px-14">
          <header className="flex items-center">
            <img
              src={NOOVAERP_LOGO_URL}
              alt="Noovaerp"
              className="h-9 w-auto object-contain"
            />
          </header>

          <div className="flex flex-1 items-center">
            <div className="w-full max-w-[420px]">
              <div className="mb-6 space-y-1.5">
                <h1 className="text-[28px] font-semibold leading-tight tracking-tight text-slate-950">
                  Welcome back
                </h1>
                <p className="text-sm leading-6 text-slate-500">
                  Sign in to your company workspace to continue.
                </p>
              </div>

              <form onSubmit={handleLogin} className="space-y-4">
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
                      onChange={(e) => setEmail(e.target.value)}
                      required
                      autoComplete="email"
                      className="h-11 rounded-lg border-slate-200 bg-white pl-10 text-[15px] text-slate-950 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] transition focus-visible:border-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900/10"
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="password" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                      Password
                    </Label>
                    <button
                      type="button"
                      className="text-xs font-medium text-slate-500 transition hover:text-slate-900"
                    >
                      Forgot?
                    </button>
                  </div>
                  <div className="relative">
                    <Icon
                      icon="solar:lock-password-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="password"
                      type={showPassword ? 'text' : 'password'}
                      placeholder="Enter your password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                      autoComplete="current-password"
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
                </div>

                <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900/30"
                  />
                  Keep me signed in on this device
                </label>

                <Button
                  type="submit"
                  className="h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                  disabled={loading}
                >
                  {loading ? (
                    <>
                      <Icon icon="solar:restart-circle-bold" className="mr-2 h-[18px] w-[18px] animate-spin" />
                      Signing in
                    </>
                  ) : (
                    <>
                      Sign in
                      <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
                    </>
                  )}
                </Button>
              </form>

              <div className="mt-5 flex items-center gap-3 text-[11px] text-slate-400">
                <span className="h-px flex-1 bg-slate-200" />
                <span>protected workspace</span>
                <span className="h-px flex-1 bg-slate-200" />
              </div>

              <div className="mt-4 flex items-center justify-center gap-4 text-[11px] text-slate-500">
                <span className="inline-flex items-center gap-1.5">
                  <Icon icon="solar:shield-check-bold-duotone" className="h-3.5 w-3.5 text-emerald-600" />
                  TLS encrypted
                </span>
                <span className="h-3 w-px bg-slate-200" />
                <span className="inline-flex items-center gap-1.5">
                  <Icon icon="solar:server-square-cloud-bold-duotone" className="h-3.5 w-3.5 text-sky-600" />
                  Tenant-isolated
                </span>
                <span className="h-3 w-px bg-slate-200" />
                <span className="inline-flex items-center gap-1.5">
                  <Icon icon="solar:user-id-bold-duotone" className="h-3.5 w-3.5 text-violet-600" />
                  RBAC
                </span>
              </div>
            </div>
          </div>

          <footer className="text-[11px] text-slate-400">
            <span>© {new Date().getFullYear()} NoovaERP</span>
          </footer>
        </section>

        {/* Right — brand visual (no fake widgets, fits viewport) */}
        <aside className="relative hidden h-full overflow-hidden lg:block">
          <div
            className="absolute inset-0"
            style={{
              backgroundImage:
                'radial-gradient(1100px 500px at 85% -10%, rgba(56,189,248,0.22), transparent 60%),' +
                'radial-gradient(900px 500px at -10% 110%, rgba(16,185,129,0.18), transparent 60%),' +
                'linear-gradient(135deg,#0b1220 0%,#0f172a 50%,#0b1220 100%)',
            }}
          />
          <div
            className="absolute inset-0 opacity-[0.08]"
            style={{
              backgroundImage:
                'linear-gradient(rgba(255,255,255,0.6) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.6) 1px, transparent 1px)',
              backgroundSize: '44px 44px',
              maskImage: 'radial-gradient(ellipse at center, black 55%, transparent 85%)',
              WebkitMaskImage: 'radial-gradient(ellipse at center, black 55%, transparent 85%)',
            }}
          />

          <div className="relative z-10 flex h-full flex-col p-10 text-white">
            <div className="flex items-center justify-between">
              <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-[11px] font-medium uppercase tracking-[0.18em] text-slate-200 backdrop-blur">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                Enterprise edition
              </span>
            </div>

            <div className="my-auto max-w-[520px]">
              <h2 className="text-[44px] font-semibold leading-[1.05] tracking-tight">
                One platform.
                <span className="block text-slate-300">Every operation.</span>
              </h2>
              <p className="mt-4 max-w-[440px] text-[15px] leading-7 text-slate-300/90">
                Finance, inventory, retail, HR and service — unified in a single, governed workspace
                built for serious teams.
              </p>

              <div className="mt-8 flex flex-wrap gap-2">
                {['Finance', 'Inventory', 'Retail / POS', 'HRM', 'OCR', 'Field service', 'IoT'].map((m) => (
                  <span
                    key={m}
                    className="rounded-full border border-white/15 bg-white/[0.06] px-3 py-1.5 text-[12px] font-medium text-slate-100 backdrop-blur"
                  >
                    {m}
                  </span>
                ))}
              </div>
            </div>

            <div className="flex items-center justify-between border-t border-white/10 pt-5 text-[11px] text-slate-300/80">
              <div className="flex items-center gap-2">
                <Icon icon="solar:shield-keyhole-bold-duotone" className="h-4 w-4 text-emerald-300" />
                SOC-grade controls
              </div>
              <div className="hidden items-center gap-2 sm:flex">
                <Icon icon="solar:global-bold-duotone" className="h-4 w-4 text-sky-300" />
                Multi-tenant cloud
              </div>
              <div className="flex items-center gap-2">
                <Icon icon="solar:cpu-bolt-bold-duotone" className="h-4 w-4 text-violet-300" />
                AI assisted
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  )
}
