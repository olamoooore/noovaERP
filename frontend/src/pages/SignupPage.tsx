import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

const SIGNUP_STEPS = [
  {
    icon: 'solar:user-plus-bold-duotone',
    title: 'Create your account',
    desc: 'Tell us who you are and name your company workspace.',
  },
  {
    icon: 'solar:settings-minimalistic-bold-duotone',
    title: 'We provision your tenant',
    desc: 'An isolated workspace with its own data, domain and trial plan.',
  },
  {
    icon: 'solar:rocket-2-bold-duotone',
    title: 'Start operating',
    desc: 'Invite your team, load your catalog and run your first sale.',
  },
]

function slugify(value: string) {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9-\s]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .slice(0, 63)
}

export function SignupPage() {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [company, setCompany] = useState('')
  const [subdomain, setSubdomain] = useState('')
  const [subdomainTouched, setSubdomainTouched] = useState(false)
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()
  const { toast } = useToast()

  const passwordTooShort = password.length > 0 && password.length < 8

  const effectiveSubdomain = useMemo(
    () => (subdomainTouched ? subdomain : slugify(company)),
    [subdomain, subdomainTouched, company]
  )

  const handleSignup = async (e: React.FormEvent) => {
    e.preventDefault()
    if (password.length < 8) {
      toast({
        variant: 'destructive',
        title: 'Password too short',
        description: 'Use at least 8 characters.',
      })
      return
    }
    setLoading(true)

    try {
      await api.post('/auth/signup', {
        name,
        email,
        company,
        subdomain: effectiveSubdomain,
        password,
      })
      toast({
        title: 'Workspace created',
        description: 'Your 14-day trial is ready. Sign in to get started.',
      })
      navigate('/login')
    } catch (error: any) {
      const data = error.response?.data
      const firstValidationError =
        data?.errors && typeof data.errors === 'object'
          ? (Object.values(data.errors).flat()[0] as string | undefined)
          : undefined
      toast({
        variant: 'destructive',
        title: 'Signup failed',
        description: firstValidationError || data?.message || 'Something went wrong. Please try again.',
      })
    } finally {
      setLoading(false)
    }
  }

  const inputClass =
    'h-11 rounded-lg border-slate-200 bg-white text-[15px] text-slate-950 shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] transition focus-visible:border-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900/10'

  return (
    <div className="min-h-screen w-full bg-white text-slate-950">
      <div className="grid min-h-screen w-full lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
        {/* Left - form column */}
        <section className="relative flex min-h-screen flex-col overflow-y-auto bg-white px-6 py-5 sm:px-10 lg:px-14">
          <header className="flex items-center justify-between">
            <Link to="/" className="flex items-center">
              <img src={NOOVAERP_LOGO_URL} alt="NoovaERP" className="h-8 w-auto object-contain" />
            </Link>
            <Link
              to="/"
              className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900"
            >
              <Icon icon="solar:arrow-left-linear" className="h-3.5 w-3.5" />
              Back to website
            </Link>
          </header>

          <div className="flex flex-1 items-center py-8">
            <div className="w-full max-w-[440px]">
              <div className="mb-6 space-y-1.5">
                <h1 className="text-[28px] font-semibold leading-tight tracking-tight text-slate-950">
                  Create your workspace
                </h1>
                <p className="text-sm leading-6 text-slate-500">
                  Start your 14-day free trial. No credit card required.
                </p>
              </div>

              <form onSubmit={handleSignup} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="name" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Full name
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:user-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="name"
                      type="text"
                      placeholder="Alex Morgan"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      required
                      autoComplete="name"
                      className={`${inputClass} pl-10`}
                    />
                  </div>
                </div>

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
                      className={`${inputClass} pl-10`}
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="company" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Company name
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:buildings-2-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="company"
                      type="text"
                      placeholder="Acme Trading Co"
                      value={company}
                      onChange={(e) => setCompany(e.target.value)}
                      required
                      autoComplete="organization"
                      className={`${inputClass} pl-10`}
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="subdomain" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Workspace URL
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:global-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="subdomain"
                      type="text"
                      placeholder="acme"
                      value={effectiveSubdomain}
                      onChange={(e) => {
                        setSubdomainTouched(true)
                        setSubdomain(slugify(e.target.value))
                      }}
                      required
                      autoComplete="off"
                      spellCheck={false}
                      className={`${inputClass} pl-10`}
                    />
                  </div>
                  <p className="text-[11px] text-slate-400">
                    Lowercase letters, numbers and hyphens. This becomes your workspace address.
                  </p>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="password" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Password
                  </Label>
                  <div className="relative">
                    <Icon
                      icon="solar:lock-password-bold-duotone"
                      className="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                    />
                    <Input
                      id="password"
                      type={showPassword ? 'text' : 'password'}
                      placeholder="Minimum 8 characters"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                      minLength={8}
                      autoComplete="new-password"
                      className={`${inputClass} pl-10 pr-11`}
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
                  {passwordTooShort && (
                    <p className="text-[11px] text-rose-500">Password must be at least 8 characters.</p>
                  )}
                </div>

                <Button
                  type="submit"
                  className="h-11 w-full rounded-lg bg-slate-950 text-[14px] font-semibold tracking-wide text-white shadow-sm transition hover:bg-slate-800"
                  disabled={loading}
                >
                  {loading ? (
                    <>
                      <Icon icon="solar:restart-circle-bold" className="mr-2 h-[18px] w-[18px] animate-spin" />
                      Creating workspace
                    </>
                  ) : (
                    <>
                      Create workspace
                      <Icon icon="solar:arrow-right-bold" className="ml-2 h-[18px] w-[18px]" />
                    </>
                  )}
                </Button>
              </form>

              <p className="mt-5 text-center text-[13px] text-slate-500">
                Already have an account?{' '}
                <Link to="/login" className="font-semibold text-slate-900 transition hover:underline">
                  Sign in
                </Link>
              </p>

              <p className="mt-4 text-center text-[11px] leading-5 text-slate-400">
                By creating a workspace you agree to our terms of service and privacy policy.
              </p>
            </div>
          </div>

          <footer className="text-[11px] text-slate-400">
            <span>(c) {new Date().getFullYear()} NoovaERP</span>
          </footer>
        </section>

        {/* Right - brand visual */}
        <aside className="relative hidden min-h-screen overflow-hidden lg:block">
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
                14-day free trial
              </span>
            </div>

            <div className="my-auto max-w-[520px]">
              <h2 className="text-[40px] font-semibold leading-[1.08] tracking-tight">
                Two minutes to
                <span className="block text-slate-300">your own workspace.</span>
              </h2>
              <p className="mt-4 max-w-[440px] text-[15px] leading-7 text-slate-300/90">
                Every NoovaERP workspace is a fully isolated tenant with its own data,
                domain and access controls - provisioned the moment you sign up.
              </p>

              <div className="mt-9 space-y-4">
                {SIGNUP_STEPS.map((step, i) => (
                  <div
                    key={step.title}
                    className="flex items-start gap-4 rounded-xl border border-white/10 bg-white/[0.05] p-4 backdrop-blur"
                  >
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/[0.06]">
                      <Icon icon={step.icon} className="h-5 w-5 text-sky-300" />
                    </span>
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-[11px] font-semibold text-slate-400">0{i + 1}</span>
                        <h3 className="text-[14.5px] font-semibold text-white">{step.title}</h3>
                      </div>
                      <p className="mt-1 text-[12.5px] leading-5 text-slate-300/80">{step.desc}</p>
                    </div>
                  </div>
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
