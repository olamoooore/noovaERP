import { Routes, Route } from 'react-router-dom';
import { CartProvider } from '@/storefront/context/CartContext';
import { MembershipProvider } from '@/storefront/context/MembershipContext';
import { Toaster } from '@/storefront/components/ui/sonner';
import '@/storefront/storefront.css';

// Lazy load storefront pages
import { lazy, Suspense } from 'react';

const StorefrontHome = lazy(() => import('@/storefront/pages/Index'));
const StorefrontShop = lazy(() => import('@/storefront/pages/Shop'));
const StorefrontEvents = lazy(() => import('@/storefront/pages/Events'));
const StorefrontMembership = lazy(() => import('@/storefront/pages/Membership'));
const StorefrontCheckout = lazy(() => import('@/storefront/pages/Checkout'));
const StorefrontNotFound = lazy(() => import('@/storefront/pages/NotFound'));

function StorefrontLoading() {
  return (
    <div className="flex items-center justify-center min-h-screen">
      <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
    </div>
  );
}

export default function StorefrontApp() {
  return (
    <div className="storefront">
      <MembershipProvider>
        <CartProvider>
          <Suspense fallback={<StorefrontLoading />}>
            <Routes>
              <Route path="/" element={<StorefrontHome />} />
              <Route path="/shop" element={<StorefrontShop />} />
              <Route path="/events" element={<StorefrontEvents />} />
              <Route path="/membership" element={<StorefrontMembership />} />
              <Route path="/checkout" element={<StorefrontCheckout />} />
              <Route path="*" element={<StorefrontNotFound />} />
            </Routes>
          </Suspense>
          <Toaster />
        </CartProvider>
      </MembershipProvider>
    </div>
  );
}
