import { useEffect } from 'react'
import { Outlet, Navigate, NavLink, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'

const navItems = [
  { path: '/web-shop/account', label: 'Dashboard', icon: 'solar:home-bold-duotone' },
  { path: '/web-shop/account/tickets', label: 'Tickets', icon: 'solar:ticket-bold-duotone' },
  { path: '/web-shop/account/orders', label: 'My Orders', icon: 'solar:box-bold-duotone' },
  { path: '/web-shop/account/loyalty', label: 'Loyalty Points', icon: 'solar:gift-bold-duotone' },
  { path: '/web-shop/account/addresses', label: 'Addresses', icon: 'solar:map-point-bold-duotone' },
  { path: '/web-shop/account/profile', label: 'Profile', icon: 'solar:user-bold-duotone' },
]

export function PortalLayout() {
  const { isAuthenticated, isLoading, customer, logout } = useWebShopAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      navigate('/web-shop/login', { replace: true })
    }
  }, [isLoading, isAuthenticated, navigate])

  const handleLogout = async () => {
    await logout()
    navigate('/web-shop/login', { replace: true })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/web-shop/login" replace />
  }

  return (
    <div className="min-h-screen bg-muted/30">
      {/* Header */}
      <header className="bg-white border-b sticky top-0 z-50">
        <div className="container mx-auto px-4 h-16 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <NavLink to="/" className="flex items-center gap-2 text-xl font-bold">
              <Icon icon="solar:shop-bold-duotone" className="h-6 w-6 text-primary" />
              <span>ACE Shop</span>
            </NavLink>
          </div>
          
          <div className="flex items-center gap-4">
            <div className="hidden md:flex items-center gap-2 text-sm text-muted-foreground">
              <Icon icon="solar:user-bold-duotone" className="h-4 w-4" />
              <span>Welcome, {customer?.first_name || 'Customer'}</span>
            </div>
            <Button variant="ghost" size="sm" onClick={handleLogout}>
              <Icon icon="solar:logout-2-bold-duotone" className="h-4 w-4 mr-2" />
              Logout
            </Button>
          </div>
        </div>
      </header>

      <div className="container mx-auto px-4 py-8">
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-8">
          {/* Sidebar */}
          <aside className="lg:col-span-1">
            <nav className="bg-white rounded-lg shadow-sm border p-2 sticky top-24">
              {navItems.map((item) => (
                <NavLink
                  key={item.path}
                  to={item.path}
                  end={item.path === '/web-shop/account'}
                  className={({ isActive }) =>
                    `flex items-center gap-3 px-4 py-3 rounded-md text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    }`
                  }
                >
                  <Icon icon={item.icon} className="h-5 w-5" />
                  {item.label}
                </NavLink>
              ))}
            </nav>
          </aside>

          {/* Main Content */}
          <main className="lg:col-span-3">
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  )
}