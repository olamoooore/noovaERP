import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

export function MainLayout() {
  return (
    <div className="flex h-dvh overflow-hidden bg-[#f6f8fc] overscroll-none">
      <Sidebar />
      <div className="flex flex-1 flex-col overflow-hidden">
        <TopBar />
        <main className="flex-1 overflow-y-auto overscroll-contain bg-[#f6f8fc] p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
