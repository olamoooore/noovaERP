import { Outlet } from 'react-router-dom'
import { AdminSidebar } from './AdminSidebar'
import { AdminTopbar } from './AdminTopbar'

export function AdminLayout() {
  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      <AdminSidebar />

      <div className="flex flex-1 flex-col overflow-hidden">
        <AdminTopbar />

        <main className="flex-1 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
