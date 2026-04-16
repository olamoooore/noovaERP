import { Routes, Route } from 'react-router-dom';
import { CartProvider } from '@/storefront/context/CartContext';
import { WishlistProvider } from '@/storefront/context/WishlistContext';
import { CustomerAuthProvider } from '@/storefront/context/CustomerAuthContext';
import { MembershipProvider } from '@/storefront/context/MembershipContext';
import { Toaster } from '@/storefront/components/ui/sonner';
import { StorefrontChatWidget } from '@/storefront/components/chat/StorefrontChatWidget';
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
const StorefrontProductDetail = lazy(() => import('@/storefront/pages/ProductDetail'));
const StorefrontFAQ = lazy(() => import('@/storefront/pages/FAQ'));
const StorefrontShipping = lazy(() => import('@/storefront/pages/Shipping'));
const StorefrontReturns = lazy(() => import('@/storefront/pages/Returns'));
const StorefrontAbout = lazy(() => import('@/storefront/pages/About'));
const StorefrontContact = lazy(() => import('@/storefront/pages/Contact'));

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
                  <Route path="/product/:id" element={<StorefrontProductDetail />} />
                  <Route path="/events" element={<StorefrontEvents />} />
                  <Route path="/membership" element={<StorefrontMembership />} />
                  <Route path="/checkout" element={<StorefrontCheckout />} />
                  <Route path="/account" element={<StorefrontAccount />} />
                  <Route path="/login" element={<StorefrontLogin />} />
                  <Route path="/faq" element={<StorefrontFAQ />} />
                  <Route path="/shipping" element={<StorefrontShipping />} />
                  <Route path="/returns" element={<StorefrontReturns />} />
                  <Route path="/about" element={<StorefrontAbout />} />
                  <Route path="/contact" element={<StorefrontContact />} />
                  <Route path="*" element={<StorefrontNotFound />} />
                </Routes>
              </Suspense>
              <Toaster />
              <StorefrontChatWidget />
            </WishlistProvider>
          </CartProvider>
        </MembershipProvider>
      </CustomerAuthProvider>
    </div>
  );
}
