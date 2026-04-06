import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'

const modules = [
  {
    icon: 'solar:users-group-rounded-bold-duotone',
    title: 'Employees',
    description: 'Manage employee profiles, org structure, and employment status.',
    href: '/hrm/employees',
  },
  {
    icon: 'solar:document-text-bold-duotone',
    title: 'Contracts',
    description: 'Employee contract lifecycle — creation, renewal, and termination.',
    href: '/hrm/contracts',
  },
  {
    icon: 'solar:clock-circle-bold-duotone',
    title: 'Timeshifts',
    description: 'Define and assign work shifts and weekly schedules.',
    href: '/hrm/timeshifts',
  },
  {
    icon: 'solar:clipboard-list-bold-duotone',
    title: 'Attendance',
    description: 'Track daily attendance and working hours manually.',
    href: '/hrm/attendance',
  },
  {
    icon: 'solar:calendar-bold-duotone',
    title: 'Leave Requests',
    description: 'Manage employee leave applications and approvals.',
    href: '/hrm/leave-requests',
  },
  {
    icon: 'solar:wallet-money-bold-duotone',
    title: 'Payroll',
    description: 'Payroll runs, entries, and approval workflow.',
    href: '/hrm/payroll',
  },
]

export function HRMDashboard() {
  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">HRM Dashboard</h1>
        <p className="text-sm text-gray-500 mt-1">Human Resource Management — select a module to get started.</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {modules.map((mod) => (
          <Link
            key={mod.title}
            to={mod.href}
            className="group relative rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-teal-400 hover:shadow-md transition-all"
          >
            <div className="flex items-center gap-3 mb-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-md bg-teal-50 group-hover:bg-teal-100 transition-colors">
                <Icon icon={mod.icon} className="h-5 w-5 text-teal-600" width={20} height={20} />
              </div>
              <h2 className="text-sm font-semibold text-gray-900">{mod.title}</h2>
            </div>
            <p className="text-xs text-gray-500">{mod.description}</p>
          </Link>
        ))}
      </div>
    </div>
  )
}
