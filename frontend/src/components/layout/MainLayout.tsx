import { Outlet } from 'react-router-dom'
import { FloatingChatButton } from '@/components/AI/FloatingChatButton'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

export function MainLayout() {
  return (
    <div className="flex h-dvh overflow-hidden bg-[#f6f8fc] overscroll-none">
      <Sidebar />
      {/* min-w-0: without it this flex item's min-width is content-based, so a
          wide table stretches the whole main area (page-level horizontal
          scroll) instead of scrolling inside its own container. */}
      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <TopBar />
        <main className="min-w-0 flex-1 overflow-y-auto overscroll-contain bg-[#f6f8fc] p-6">
          <Outlet />
        </main>
      </div>

      {/* Global AI assistant — visible on every page while the AI module is enabled. */}
      <FloatingChatButton />
    </div>
  )
}
