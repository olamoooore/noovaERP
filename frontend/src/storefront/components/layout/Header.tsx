import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Button } from '@/storefront/components/ui/button';
import { useCart } from '@/storefront/context/CartContext';
import { useMembership, MembershipTier } from '@/storefront/context/MembershipContext';
import { cn } from '@/lib/utils';

// Base path for storefront routes (matches App.tsx mount point)
const STOREFRONT_BASE = '/store';

const navLinks = [
  { href: `${STOREFRONT_BASE}/`, label: 'Home' },
  { href: `${STOREFRONT_BASE}/shop`, label: 'Shop' },
  { href: `${STOREFRONT_BASE}/events`, label: 'Events' },
  { href: `${STOREFRONT_BASE}/membership`, label: 'Membership' },
];

const tierOptions: MembershipTier[] = ['free', 'bronze', 'silver', 'gold'];

export const Header = () => {
  const location = useLocation();
  const { totalItems, setIsOpen: setCartOpen } = useCart();
  const { tier, setTier } = useMembership();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  return (
    <>
      {/* Membership Preview Bar */}
      <div className="bg-gradient-to-r from-primary/10 via-white to-secondary/10 border-b border-border/40 py-1.5 px-4 backdrop-blur-sm">
        <div className="container flex items-center justify-between text-xs font-medium tracking-wide">
          <span className="text-muted-foreground uppercase tracking-widest text-[10px]">Membership Preview</span>
          <div className="flex items-center gap-2">
            {tierOptions.map((t) => (
              <button
                key={t}
                onClick={() => setTier(t)}
                className={cn(
                  "px-3 py-0.5 rounded-full text-[10px] uppercase tracking-wider transition-all duration-300 border",
                  tier === t
                    ? t === 'free'
                      ? 'bg-foreground text-background border-foreground'
                      : t === 'bronze'
                      ? 'bg-amber-100 text-amber-800 border-amber-200'
                      : t === 'silver'
                      ? 'bg-slate-100 text-slate-800 border-slate-200'
                      : 'bg-yellow-50 text-yellow-800 border-yellow-200'
                    : 'bg-transparent text-muted-foreground border-transparent hover:border-border'
                )}
              >
                {t}
              </button>
            ))}
          </div>
        </div>
      </div>

      <header className="sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-white/20 shadow-sm">
        <div className="container">
          <div className="flex items-center justify-between h-16 md:h-20">
            {/* Logo */}
            <Link to={STOREFRONT_BASE} className="flex items-center gap-2 group">
              <img 
                src="/storefront-logo.webp" 
                alt="InfinitiKPOP" 
                className="h-10 md:h-12 w-auto object-contain transition-transform group-hover:scale-105"
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
              <Button variant="ghost" size="icon" className="hidden md:flex hover:bg-primary/5 hover:text-primary transition-colors">
                <Icon icon="solar:magnifer-linear" className="h-6 w-6" />
              </Button>
              
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

              <Button variant="ghost" size="icon" className="hidden md:flex hover:bg-primary/5 hover:text-primary transition-colors">
                <Icon icon="solar:user-circle-linear" className="h-6 w-6" />
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
              <div className="flex items-center justify-between py-2 text-muted-foreground">
                <span>Account</span>
                <Icon icon="solar:user-circle-linear" className="h-6 w-6" />
              </div>
            </nav>
          </div>
        )}
      </header>
    </>
  );
};
