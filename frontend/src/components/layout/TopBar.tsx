import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { useAuthStore } from '@/store/authStore'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { NotificationBell } from '@/components/layout/NotificationBell'
import { GlobalSearch } from '@/components/search/GlobalSearch'

function greetingFor(hour: number): { label: string; icon: string } {
  if (hour < 12) return { label: 'Good morning', icon: 'solar:sunrise-bold-duotone' }
  if (hour < 17) return { label: 'Good afternoon', icon: 'solar:sun-bold-duotone' }
  return { label: 'Good evening', icon: 'solar:moon-stars-bold-duotone' }
}

function useNow(intervalMs = 10000) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), intervalMs)
    return () => clearInterval(id)
  }, [intervalMs])
  return now
}

export function TopBar() {
  const { user, logout, role } = useAuthStore()
  const navigate = useNavigate()
  const now = useNow()

  const handleLogout = () => {
    logout()
    navigate('/login')
  }

  const greeting = greetingFor(now.getHours())
  const timeLabel = now.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  })
  const dateLabel = now.toLocaleDateString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  })

  const firstName = user?.name?.split(' ')[0] || 'there'

  return (
    <div className="flex h-16 items-center justify-between border-b bg-card px-6">
      <div className="flex items-center gap-4">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg leading-none text-primary">
          <Icon
            icon={greeting.icon}
            className="h-6 w-6"
            aria-label={greeting.label}
          />
        </div>
        <div className="min-w-0">
          <h1 className="truncate text-lg font-semibold leading-tight">
            {greeting.label}, {firstName}
          </h1>
          <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Icon icon="solar:clock-circle-bold-duotone" className="h-3.5 w-3.5" />
            <span className="tabular-nums">{timeLabel}</span>
            <span className="text-muted-foreground/50">·</span>
            <span>{dateLabel}</span>
          </p>
        </div>
      </div>

      <div className="flex flex-1 items-center justify-center px-6">
        <GlobalSearch />
      </div>

      <div className="flex shrink-0 items-center gap-3">
        {/* Notifications */}
        <NotificationBell />

        {/* User menu */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-10 w-10 rounded-full border border-border/70 bg-background shadow-sm transition-all hover:border-primary/40 hover:bg-accent hover:shadow-md"
            >
              <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-primary-foreground">
                <Icon icon="solar:user-bold" className="h-3.5 w-3.5" />
              </div>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>
              <div className="flex flex-col space-y-1">
                <p className="text-sm font-medium">{user?.name}</p>
                <p className="text-xs text-muted-foreground">{user?.email}</p>
                {role && (
                  <p className="text-xs font-medium capitalize text-primary">{role}</p>
                )}
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => navigate('/profile')}>
              <Icon icon="solar:user-circle-bold" className="mr-2 h-4 w-4" />
              <span>Profile</span>
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => navigate('/settings')}>
              <Icon icon="solar:settings-bold" className="mr-2 h-4 w-4" />
              <span>Settings</span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={handleLogout}>
              <Icon icon="solar:logout-2-bold" className="mr-2 h-4 w-4" />
              <span>Logout</span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
