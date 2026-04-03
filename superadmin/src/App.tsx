import { Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { AdminProtectedRoute } from '@/components/admin/AdminProtectedRoute'
import { AdminLayout } from '@/components/admin/AdminLayout'
import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'
import { AdminDashboardPage } from '@/pages/AdminDashboardPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
})

function PageLoading() {
  return (
    <div className="flex h-full items-center justify-center">
      <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
    </div>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Suspense fallback={<PageLoading />}>
          <Routes>
            {/* Public */}
            <Route path="/login" element={<AdminLoginPage />} />

            {/* Protected — requires valid admin token */}
            <Route element={<AdminProtectedRoute />}>
              <Route element={<AdminLayout />}>
                <Route index element={<AdminDashboardPage />} />

                {/* Phase 2+ pages — placeholders until implemented */}
                <Route path="tenants/*"       element={<ComingSoon title="Tenants" />} />
                <Route path="plans/*"         element={<ComingSoon title="Plans" />} />
                <Route path="modules/*"       element={<ComingSoon title="Module Catalog" />} />
                <Route path="subscriptions/*" element={<ComingSoon title="Subscriptions" />} />
                <Route path="billing/*"       element={<ComingSoon title="Billing" />} />
                <Route path="collections/*"   element={<ComingSoon title="Collections" />} />
                <Route path="sales/*"         element={<ComingSoon title="Sales" />} />
                <Route path="operations/*"    element={<ComingSoon title="Operations" />} />
                <Route path="audit/*"         element={<ComingSoon title="Audit Log" />} />
              </Route>
            </Route>

            {/* Catch-all */}
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

function ComingSoon({ title }: { title: string }) {
  return (
    <div className="flex h-64 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 text-center">
      <p className="text-lg font-semibold text-gray-700">{title}</p>
      <p className="text-sm text-gray-400">Coming in a future phase.</p>
    </div>
  )
}
