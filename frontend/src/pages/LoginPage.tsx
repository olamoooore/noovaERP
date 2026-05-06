import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
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
    <div className="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.16),_transparent_32%),radial-gradient(circle_at_bottom_right,_rgba(245,158,11,0.16),_transparent_28%),linear-gradient(135deg,#f8fafc_0%,#eef2ff_45%,#ecfeff_100%)] px-4 py-8">
      <div className="mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-8 lg:grid-cols-[1.1fr_0.9fr]">
        <div className="hidden rounded-[2rem] border border-white/60 bg-slate-950/90 p-10 text-white shadow-2xl backdrop-blur lg:block">
          <div className="max-w-xl space-y-5">
            <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300/80">
              ERP Control Center
            </p>
            <h1 className="text-5xl font-semibold leading-tight text-balance">
              Run the business from one workspace.
            </h1>
            <p className="text-base leading-7 text-slate-300">
              Inventory, sales, finance, and operations in one place.
            </p>
          </div>

          <div className="mt-10 grid gap-4 sm:grid-cols-3">
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-sm font-medium text-white">Inventory</p>
              <p className="mt-2 text-sm text-slate-300">Stock, purchasing, and warehouse visibility.</p>
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-sm font-medium text-white">Accounting</p>
              <p className="mt-2 text-sm text-slate-300">Posting, reporting, and control.</p>
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-sm font-medium text-white">Operations</p>
              <p className="mt-2 text-sm text-slate-300">One login for daily operations.</p>
            </div>
          </div>
        </div>

        <Card className="mx-auto w-full max-w-md border-white/70 bg-white/85 shadow-2xl backdrop-blur">
          <CardHeader className="space-y-4 text-center">
            <div className="mx-auto flex items-center justify-center">
              <img
                src={NOOVAERP_LOGO_URL}
                alt="Noovaerp"
                className="h-12 w-auto object-contain"
              />
            </div>
            <div className="space-y-1">
              <CardTitle className="text-3xl font-semibold text-slate-950">Sign in</CardTitle>
              <CardDescription className="text-sm text-slate-600">
                Access your NoovaERP workspace.
              </CardDescription>
            </div>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleLogin} className="space-y-5">
              <div className="space-y-2">
                <Label htmlFor="email">Work Email</Label>
                <Input
                  id="email"
                  type="email"
                  placeholder="you@company.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoComplete="email"
                  className="h-11 bg-white"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="password">Password</Label>
                <div className="relative">
                  <Input
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    placeholder="Enter your password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    autoComplete="current-password"
                    className="h-11 bg-white pr-12"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((current) => !current)}
                    className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 transition hover:text-slate-900"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    <Icon icon={showPassword ? 'solar:eye-closed-bold' : 'solar:eye-bold'} className="h-4 w-4" />
                  </button>
                </div>
              </div>
              <Button type="submit" className="h-11 w-full text-sm font-medium" disabled={loading}>
                {loading ? (
                  <>
                    <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                    Signing In...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:login-2-bold" className="mr-2 h-4 w-4" />
                    Sign In to NoovaERP
                  </>
                )}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
