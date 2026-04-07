import { Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { AdminProtectedRoute } from '@/components/admin/AdminProtectedRoute'
import { AdminLayout } from '@/components/admin/AdminLayout'
import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'
import { AdminDashboardPage } from '@/pages/AdminDashboardPage'
import { PlanListPage } from '@/pages/plans/PlanListPage'
import { PlanDetailPage } from '@/pages/plans/PlanDetailPage'
import { ModuleCatalogPage } from '@/pages/modules/ModuleCatalogPage'
import TenantListPage from '@/pages/tenants/TenantListPage'
import TenantDetailPage from '@/pages/tenants/TenantDetailPage'
import SubscriptionListPage from '@/pages/subscriptions/SubscriptionListPage'
import SubscriptionDetailPage from '@/pages/subscriptions/SubscriptionDetailPage'
import BillingPage from '@/pages/billing/BillingPage'
import AuditLogPage from '@/pages/audit/AuditLogPage'
import ObservabilityPage from '@/pages/observability/ObservabilityPage'

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

                {/* Phase 2 — Tenant 360 */}
                <Route path="tenants"               element={<TenantListPage />} />
                <Route path="tenants/:tenantId"     element={<TenantDetailPage />} />

                {/* Phase 1 — live pages */}
                <Route path="plans"           element={<PlanListPage />} />
                <Route path="plans/:familyId" element={<PlanDetailPage />} />
                <Route path="modules"         element={<ModuleCatalogPage />} />
                {/* Phase 3 — Subscriptions & Billing */}
                <Route path="subscriptions"                    element={<SubscriptionListPage />} />
                <Route path="subscriptions/:subscriptionId"    element={<SubscriptionDetailPage />} />
                <Route path="billing"                          element={<BillingPage />} />
                {/* Phase 4 — Audit Log & Observability */}
                <Route path="audit"         element={<AuditLogPage />} />
                <Route path="observability" element={<ObservabilityPage />} />

                <Route path="collections/*" element={<ComingSoon title="Collections" />} />
                <Route path="sales/*"       element={<ComingSoon title="Sales" />} />
                <Route path="operations/*"  element={<ComingSoon title="Operations" />} />
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
