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
              className="h-10 w-10 rounded-full bg-background p-0 ring-1 ring-border/70 shadow-sm transition-all hover:ring-primary/40 hover:bg-accent hover:shadow-md"
            >
              {user?.avatar_url ? (
                <img
                  src={user.avatar_url}
                  alt={user?.name || 'Avatar'}
                  className="h-10 w-10 rounded-full object-cover"
                  onError={(e) => {
                    ;(e.currentTarget as HTMLImageElement).style.display = 'none'
                  }}
                />
              ) : (
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/70 text-xs font-semibold text-primary-foreground">
                  {(user?.name || user?.email || '?')
                    .split(/\s+/)
                    .map((part) => part[0])
                    .filter(Boolean)
                    .slice(0, 2)
                    .join('')
                    .toUpperCase() || <Icon icon="solar:user-bold" className="h-4 w-4" />}
                </div>
              )}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            align="end"
            sideOffset={8}
            className="w-64 overflow-hidden p-0"
          >
            <div className="relative bg-gradient-to-br from-primary/8 via-primary/3 to-transparent px-3 py-3">
              <div className="flex items-start gap-2.5">
                <div className="relative flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-primary to-primary/70 text-xs font-semibold text-primary-foreground shadow-sm ring-2 ring-background">
                  {user?.avatar_url ? (
                    <img
                      src={user.avatar_url}
                      alt={user?.name || 'Avatar'}
                      className="absolute inset-0 h-full w-full object-cover"
                      onError={(e) => {
                        ;(e.currentTarget as HTMLImageElement).style.display = 'none'
                      }}
                    />
                  ) : null}
                  <span className="relative">
                    {(user?.name || user?.email || '?')
                      .split(/\s+/)
                      .map((p) => p[0])
                      .filter(Boolean)
                      .slice(0, 2)
                      .join('')
                      .toUpperCase()}
                  </span>
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1.5">
                    <p className="truncate text-sm font-semibold leading-tight text-foreground">
                      {user?.name || 'Account'}
                    </p>
                    {role && (
                      <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-primary">
                        <Icon icon="solar:shield-user-bold-duotone" className="h-2.5 w-2.5" />
                        {role}
                      </span>
                    )}
                  </div>
                  <p className="truncate text-xs leading-tight text-muted-foreground">
                    {user?.email}
                  </p>
                </div>
              </div>
            </div>

            <DropdownMenuSeparator className="my-0" />

            <div className="p-1">
              <DropdownMenuItem
                onClick={() => navigate('/profile')}
                className="cursor-pointer rounded-md px-2.5 py-2 text-sm"
              >
                <Icon
                  icon="solar:user-circle-bold-duotone"
                  className="mr-2.5 h-4 w-4 text-muted-foreground"
                />
                <span>Profile</span>
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => navigate('/settings')}
                className="cursor-pointer rounded-md px-2.5 py-2 text-sm"
              >
                <Icon
                  icon="solar:settings-bold-duotone"
                  className="mr-2.5 h-4 w-4 text-muted-foreground"
                />
                <span>Settings</span>
              </DropdownMenuItem>
            </div>

            <DropdownMenuSeparator className="my-0" />

            <div className="p-1">
              <DropdownMenuItem
                onClick={handleLogout}
                className="cursor-pointer rounded-md px-2.5 py-2 text-sm text-destructive focus:bg-destructive/10 focus:text-destructive"
              >
                <Icon
                  icon="solar:logout-2-bold-duotone"
                  className="mr-2.5 h-4 w-4"
                />
                <span>Sign out</span>
              </DropdownMenuItem>
            </div>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
