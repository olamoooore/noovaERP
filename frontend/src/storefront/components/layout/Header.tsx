import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Button } from '@/storefront/components/ui/button';
import { useCart } from '@/storefront/context/CartContext';
import { useWishlist } from '@/storefront/context/WishlistContext';
import { useCustomerAuth } from '@/storefront/context/CustomerAuthContext';
import { cn } from '@/lib/utils';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { STOREFRONT_LOGO_URL } from '@/storefront/lib/assets';
import { ProductSearchOverlay } from './ProductSearchOverlay';

// Base path for storefront routes (matches App.tsx mount point)
const STOREFRONT_BASE = '/store';

const navLinks = [
  { href: `${STOREFRONT_BASE}/`, label: 'Home' },
  { href: `${STOREFRONT_BASE}/shop`, label: 'Shop' },
  { href: `${STOREFRONT_BASE}/events`, label: 'Events' },
  { href: `${STOREFRONT_BASE}/membership`, label: 'Membership' },
];

export const Header = () => {
  const location = useLocation();
  const { totalItems, setIsOpen: setCartOpen } = useCart();
  const { totalItems: wishlistCount } = useWishlist();
  const { isAuthenticated } = useCustomerAuth();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);

  return (
    <>
      <header className="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-white/20 shadow-sm">
        <div className="container">
          <div className="flex items-center justify-between h-16 md:h-20">
            {/* Logo */}
            <Link to={STOREFRONT_BASE} className="flex items-center gap-2 group">
              <img 
                src={STOREFRONT_LOGO_URL} 
                alt="InfinitiKPOP" 
                className="h-12 md:h-14 w-auto object-contain transition-transform group-hover:scale-105"
              />
            </Link>

            {/* Desktop Navigation */}
            <nav className="hidden md:flex items-center gap-8">
              {navLinks.map((link) => (
                <Link
                  key={link.href}
                  to={link.href}
                  className={cn(
                    "relative text-sm font-medium transition-colors hover:text-primary",
                    location.pathname === link.href
                      ? "text-foreground font-semibold"
                      : "text-muted-foreground"
                  )}
                >
                  {link.label}
                  {location.pathname === link.href && (
                    <span className="absolute -bottom-1 left-0 w-full h-0.5 bg-gradient-brand rounded-full" />
                  )}
                </Link>
              ))}
            </nav>

            {/* Actions */}
            <div className="flex items-center gap-2 md:gap-3">
              <Button 
                variant="ghost" 
                size="icon" 
                className="hidden md:flex hover:bg-primary/5 hover:text-primary transition-colors"
                onClick={() => setSearchOpen(true)}
              >
                <Icon icon="solar:magnifer-linear" className="h-6 w-6" />
              </Button>

              {/* Wishlist */}
              <Button variant="ghost" size="icon" className="relative hover:bg-primary/5 hover:text-primary transition-colors" asChild>
                <Link to={storefrontRoutes.account + '?tab=wishlist'}>
                  <Icon icon="solar:heart-linear" className="h-6 w-6" />
                  {wishlistCount > 0 && (
                    <span className="absolute top-1 right-1 h-4 w-4 rounded-full bg-secondary text-secondary-foreground text-[10px] flex items-center justify-center font-bold shadow-sm">
                      {wishlistCount}
                    </span>
                  )}
                </Link>
              </Button>

              {/* Cart */}
              <Button
                variant="ghost"
                size="icon"
                className="relative hover:bg-primary/5 hover:text-primary transition-colors"
                onClick={() => setCartOpen(true)}
              >
                <Icon icon="solar:bag-3-linear" className="h-6 w-6" />
                {totalItems > 0 && (
                  <span className="absolute top-1 right-1 h-4 w-4 rounded-full bg-primary text-primary-foreground text-[10px] flex items-center justify-center font-bold shadow-sm">
                    {totalItems}
                  </span>
                )}
              </Button>

              {/* Account */}
              <Button variant="ghost" size="icon" className="hidden md:flex hover:bg-primary/5 hover:text-primary transition-colors" asChild>
                <Link to={isAuthenticated ? storefrontRoutes.account : storefrontRoutes.login}>
                  <Icon icon="solar:user-circle-linear" className="h-6 w-6" />
                </Link>
              </Button>

              {/* Mobile Menu Toggle */}
              <Button
                variant="ghost"
                size="icon"
                className="md:hidden"
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              >
                <Icon 
                  icon={mobileMenuOpen ? "solar:close-circle-linear" : "solar:hamburger-menu-linear"} 
                  className="h-6 w-6"
                />
              </Button>
            </div>
          </div>
        </div>

        {/* Mobile Menu */}
        {mobileMenuOpen && (
          <div className="md:hidden absolute top-full left-0 w-full bg-white border-b border-border shadow-lg animate-in slide-in-from-top-2">
            <nav className="container py-6 flex flex-col gap-4">
              {navLinks.map((link) => (
                <Link
                  key={link.href}
                  to={link.href}
                  onClick={() => setMobileMenuOpen(false)}
                  className={cn(
                    "flex items-center justify-between py-2 text-lg font-medium transition-colors",
                    location.pathname === link.href
                      ? "text-primary"
                      : "text-muted-foreground hover:text-foreground"
                  )}
                >
                  {link.label}
                  <Icon icon="solar:arrow-right-linear" className="h-5 w-5 opacity-50" />
                </Link>
              ))}
              <div className="h-px bg-border my-2" />
              <Link
                to={storefrontRoutes.account + '?tab=wishlist'}
                onClick={() => setMobileMenuOpen(false)}
                className="flex items-center justify-between py-2 text-muted-foreground hover:text-foreground transition-colors"
              >
                <span className="flex items-center gap-2">
                  Wishlist
                  {wishlistCount > 0 && (
                    <span className="text-xs bg-secondary/20 text-secondary-foreground px-2 py-0.5 rounded-full">
                      {wishlistCount}
                    </span>
                  )}
                </span>
                <Icon icon="solar:heart-linear" className="h-6 w-6" />
              </Link>
              <Link
                to={isAuthenticated ? storefrontRoutes.account : storefrontRoutes.login}
                onClick={() => setMobileMenuOpen(false)}
                className="flex items-center justify-between py-2 text-muted-foreground hover:text-foreground transition-colors"
              >
                <span>{isAuthenticated ? 'Account' : 'Sign In'}</span>
                <Icon icon="solar:user-circle-linear" className="h-6 w-6" />
              </Link>
            </nav>
          </div>
        )}
      </header>

      {/* Product Search Overlay */}
      <ProductSearchOverlay isOpen={searchOpen} onClose={() => setSearchOpen(false)} />
    </>
  );
};
