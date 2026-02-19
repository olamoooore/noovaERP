import { Routes, Route } from 'react-router-dom';
import { CartProvider } from '@/storefront/context/CartContext';
import { WishlistProvider } from '@/storefront/context/WishlistContext';
import { CustomerAuthProvider } from '@/storefront/context/CustomerAuthContext';
import { MembershipProvider } from '@/storefront/context/MembershipContext';
import { Toaster } from '@/storefront/components/ui/sonner';
import { FloatingChatButton } from '@/components/AI/FloatingChatButton';
import '@/storefront/storefront.css';

// Lazy load storefront pages
import { lazy, Suspense } from 'react';

const StorefrontHome = lazy(() => import('@/storefront/pages/Index'));
const StorefrontShop = lazy(() => import('@/storefront/pages/Shop'));
const StorefrontEvents = lazy(() => import('@/storefront/pages/Events'));
const StorefrontMembership = lazy(() => import('@/storefront/pages/Membership'));
const StorefrontCheckout = lazy(() => import('@/storefront/pages/Checkout'));
const StorefrontAccount = lazy(() => import('@/storefront/pages/Account'));
const StorefrontLogin = lazy(() => import('@/storefront/pages/Login'));
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
      <CustomerAuthProvider>
        <MembershipProvider>
          <CartProvider>
            <WishlistProvider>
              <Suspense fallback={<StorefrontLoading />}>
                <Routes>
                  <Route path="/" element={<StorefrontHome />} />
                  <Route path="/shop" element={<StorefrontShop />} />
                  <Route path="/events" element={<StorefrontEvents />} />
                  <Route path="/membership" element={<StorefrontMembership />} />
                  <Route path="/checkout" element={<StorefrontCheckout />} />
                  <Route path="/account" element={<StorefrontAccount />} />
                  <Route path="/login" element={<StorefrontLogin />} />
                  <Route path="*" element={<StorefrontNotFound />} />
                </Routes>
              </Suspense>
              <Toaster />
              <FloatingChatButton />
            </WishlistProvider>
          </CartProvider>
        </MembershipProvider>
      </CustomerAuthProvider>
    </div>
  );
}
