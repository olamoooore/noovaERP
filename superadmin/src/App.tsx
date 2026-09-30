import { Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { AdminProtectedRoute } from '@/components/admin/AdminProtectedRoute'
import { AdminLayout } from '@/components/admin/AdminLayout'
import { ComingSoon } from '@/components/common/ComingSoon'
import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'
import AcceptInvitePage from '@/pages/auth/AcceptInvitePage'
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
import AdminUsersPage from '@/pages/users/AdminUsersPage'
import UserRequestsPage from '@/pages/users/UserRequestsPage'
import CollectionsPage from '@/pages/collections/CollectionsPage'
import OperationsPage from '@/pages/operations/OperationsPage'
import RevenueDashboardPage from '@/pages/analytics/RevenueDashboardPage'
import CatalogManagerPage from '@/pages/catalog/CatalogManagerPage'
import TierMatrixPage from '@/pages/packaging/TierMatrixPage'
import TenantConfigurationPage from '@/pages/tenants/TenantConfigurationPage'
import PricingCalculatorPage from '@/pages/pricing/PricingCalculatorPage'
import PendingPlanChangesPage from '@/pages/plan-changes/PendingPlanChangesPage'
import CatalogAnalyticsPage from '@/pages/analytics/CatalogAnalyticsPage'
import TaxProvenancePage from '@/pages/compliance/TaxProvenancePage'

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
            <Route path="/login"         element={<AdminLoginPage />} />
            <Route path="/accept-invite" element={<AcceptInvitePage />} />

            {/* Protected — requires valid admin token */}
            <Route element={<AdminProtectedRoute />}>
              <Route element={<AdminLayout />}>
                <Route index element={<AdminDashboardPage />} />

                {/* Phase 1 — Plans & Modules */}
                <Route path="plans"           element={<PlanListPage />} />
                <Route path="plans/:familyId" element={<PlanDetailPage />} />
                <Route path="modules"         element={<ModuleCatalogPage />} />

                {/* Phase 2 — Tenant 360 */}
                <Route path="tenants"                              element={<TenantListPage />} />
                <Route path="tenants/:tenantId"                    element={<TenantDetailPage />} />
                <Route path="tenants/:tenantId/configuration"      element={<TenantConfigurationPage />} />

                {/* Phase 3 — Subscriptions & Billing */}
                <Route path="subscriptions"                 element={<SubscriptionListPage />} />
                <Route path="subscriptions/:subscriptionId" element={<SubscriptionDetailPage />} />
                <Route path="billing"                       element={<BillingPage />} />

                {/* Phase 4 — Audit Log & Observability */}
                <Route path="audit"         element={<AuditLogPage />} />
                <Route path="tax-provenance" element={<TaxProvenancePage />} />
                <Route path="observability" element={<ObservabilityPage />} />

                {/* Phase 5 — Admin Users, Collections, Operations, Analytics */}
                <Route path="users"       element={<AdminUsersPage />} />
                <Route path="user-requests" element={<UserRequestsPage />} />
                <Route path="collections" element={<CollectionsPage />} />
                <Route path="operations"  element={<OperationsPage />} />
                <Route path="analytics"   element={<RevenueDashboardPage />} />

                {/* Phase 6A — Product Catalog */}
                <Route path="catalog" element={<CatalogManagerPage />} />

                {/* Phase 6B — Tier Matrix */}
                <Route path="packaging" element={<TierMatrixPage />} />

                {/* Phase 6D — Pricing Engine */}
                <Route path="pricing" element={<PricingCalculatorPage />} />

                {/* Phase 6E — Plan Change Engine */}
                <Route path="plan-changes" element={<PendingPlanChangesPage />} />

                {/* Phase 6F — Catalog Intelligence Analytics */}
                <Route path="analytics/catalog" element={<CatalogAnalyticsPage />} />

                {/* Placeholder — future phases */}
                <Route path="sales/*" element={<ComingSoon title="Sales Pipeline" description="Planned for a future phase." />} />
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
