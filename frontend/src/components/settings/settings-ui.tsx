import { useState, type ComponentType, type ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

/**
 * Shared building blocks for the advanced settings pages
 * (Sales, Inventory, General). Keeps the sub-nav + section-card + save-bar
 * pattern in one place instead of copy-pasting it per module.
 */

export interface SettingsSectionDef {
  key: string
  title: string
  description: string
  icon: string
  component: ComponentType
}

const NAV_COLLAPSED_KEY = 'settings-nav-collapsed'

export function SettingsSectionNav({
  sections,
  activeKey,
  onSelect,
  className,
}: {
  sections: SettingsSectionDef[]
  activeKey: string
  onSelect: (key: string) => void
  className?: string
}) {
  // Collapsed state is a user preference, shared by every settings page.
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.localStorage.getItem(NAV_COLLAPSED_KEY) === '1'
  })

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev
      try {
        window.localStorage.setItem(NAV_COLLAPSED_KEY, next ? '1' : '0')
      } catch {
        // ignore storage failures (private mode, quota, …)
      }
      return next
    })
  }

  const toggleLabel = collapsed ? 'Expand settings menu' : 'Minimise settings menu'

  return (
    <Card
      className={cn(
        'w-full shrink-0 overflow-hidden p-2 transition-[width] duration-200 lg:w-72',
        collapsed && 'lg:w-16',
        className,
      )}
    >
      <nav className="flex flex-col gap-1">
        {sections.map((section) => {
          const isActive = activeKey === section.key
          return (
            <button
              key={section.key}
              type="button"
              onClick={() => onSelect(section.key)}
              title={collapsed ? section.title : undefined}
              className={cn(
                'group flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-all',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                collapsed && 'lg:justify-center lg:px-2',
                isActive
                  ? 'bg-primary/10 text-foreground shadow-sm ring-1 ring-primary/20'
                  : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
              )}
            >
              <div
                className={cn(
                  'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                  isActive
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'bg-muted text-muted-foreground group-hover:bg-background',
                )}
              >
                <Icon icon={section.icon} className="h-4 w-4" />
              </div>
              <div className={cn('min-w-0 flex-1 space-y-0.5', collapsed && 'lg:hidden')}>
                <div className={cn('text-sm font-medium leading-tight', isActive && 'text-primary')}>
                  {section.title}
                </div>
                <div className="line-clamp-2 text-[11px] leading-tight text-muted-foreground">
                  {section.description}
                </div>
              </div>
              {isActive && (
                <Icon
                  icon="solar:alt-arrow-right-bold-duotone"
                  className={cn('mt-1.5 h-3.5 w-3.5 shrink-0 text-primary', collapsed && 'lg:hidden')}
                />
              )}
            </button>
          )
        })}
      </nav>

      {/* Full-width minimise control pinned under the section list, so it reads
          as a clickable row rather than a floating chevron. */}
      <div className="mt-2 border-t pt-2">
        <button
          type="button"
          onClick={toggleCollapsed}
          title={toggleLabel}
          aria-label={toggleLabel}
          aria-expanded={!collapsed}
          className={cn(
            'group flex w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left text-muted-foreground transition-colors',
            'hover:bg-muted/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            collapsed && 'lg:justify-center lg:px-2',
          )}
        >
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted transition-colors group-hover:bg-background">
            <Icon
              icon={
                collapsed
                  ? 'solar:alt-arrow-right-bold-duotone'
                  : 'solar:alt-arrow-left-bold-duotone'
              }
              className="h-4 w-4"
            />
          </span>
          <span className={cn('min-w-0 flex-1 text-sm font-medium', collapsed && 'lg:hidden')}>
            {collapsed ? 'Expand' : 'Minimise'}
          </span>
        </button>
      </div>
    </Card>
  )
}

export function SettingsSectionHeader({
  icon,
  title,
  description,
  accentClass,
  action,
}: {
  icon: string
  title: string
  description: string
  accentClass?: string
  action?: ReactNode
}) {
  return (
    <CardHeader className="border-b bg-muted/20">
      <div className="flex items-start gap-3">
        <div
          className={cn(
            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
            accentClass ?? 'bg-primary/10',
          )}
        >
          <Icon icon={icon} className={cn('h-5 w-5', accentClass ? undefined : 'text-primary')} />
        </div>
        <div className="min-w-0 flex-1 space-y-1">
          <CardTitle className="text-lg leading-none">{title}</CardTitle>
          <CardDescription className="text-sm leading-relaxed">{description}</CardDescription>
        </div>
        {action && <div className="shrink-0 pl-2">{action}</div>}
      </div>
    </CardHeader>
  )
}

export function SettingsRow({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: ReactNode
}) {
  return (
    <div className="flex items-start justify-between gap-8 py-4">
      <div className="flex-1 space-y-1">
        <div className="text-sm font-medium leading-none">{title}</div>
        <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>
      </div>
      <div className="shrink-0 pt-0.5">{children}</div>
    </div>
  )
}

export function SettingsSaveBar({
  hasChanges,
  isPending,
  onSave,
  onCancel,
  label,
}: {
  hasChanges: boolean
  isPending: boolean
  onSave: () => void
  onCancel: () => void
  label: string
}) {
  if (!hasChanges) return null
  return (
    <div className="flex items-center justify-between gap-4 border-t bg-muted/20 px-6 py-3">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Icon icon="solar:info-circle-bold-duotone" className="h-4 w-4 text-amber-500" />
        Unsaved changes
      </div>
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="sm" onClick={onCancel} disabled={isPending}>
          Discard
        </Button>
        <Button size="sm" onClick={onSave} disabled={isPending}>
          {isPending ? (
            <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
          ) : (
            <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
          )}
          {label}
        </Button>
      </div>
    </div>
  )
}

export function SettingsLoadingCard() {
  return (
    <Card>
      <CardContent className="flex items-center justify-center py-24">
        <div className="flex flex-col items-center gap-3 text-muted-foreground">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
          <span className="text-sm">Loading settings…</span>
        </div>
      </CardContent>
    </Card>
  )
}
