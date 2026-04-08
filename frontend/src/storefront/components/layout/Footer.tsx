import { Link } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { STOREFRONT_LOGO_URL } from '@/storefront/lib/assets';

const socialLinks = [
  { href: 'https://twitter.com/InfinityKpopSG', icon: 'simple-icons:x', label: 'X (Twitter)' },
  { href: 'https://www.instagram.com/infinitykpopshop/', icon: 'simple-icons:instagram', label: 'Instagram' },
  { href: 'https://www.tiktok.com/@infinitykpopsg', icon: 'simple-icons:tiktok', label: 'TikTok' },
  { href: 'https://wa.me/message/RLWXPANVCF73I1', icon: 'simple-icons:whatsapp', label: 'WhatsApp' },
];

export const Footer = () => {
  return (
    <footer className="border-t border-border bg-white mt-auto">
      <div className="container py-12 md:py-20">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-10 md:gap-12">
          {/* Shop */}
          <div>
            <h4 className="font-medium text-foreground mb-6">Shop</h4>
            <ul className="space-y-3">
              <li>
                <Link to={storefrontRoutes.shop} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  All Products
                </Link>
              </li>
              <li>
                <Link to={`${storefrontRoutes.shop}?sort=newest`} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  New Arrivals
                </Link>
              </li>
              <li>
                <Link to={`${storefrontRoutes.shop}?search=pre-order`} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Pre-Orders
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.events} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Events
                </Link>
              </li>
            </ul>
          </div>

          {/* Support */}
          <div>
            <h4 className="font-medium text-foreground mb-6">Support</h4>
            <ul className="space-y-3">
              <li>
                <Link to={storefrontRoutes.membership} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Membership
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.shipping} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Order &amp; Shipping
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.returns} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Returns
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.faq} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  FAQ
                </Link>
              </li>
            </ul>
          </div>

          {/* Company */}
          <div>
            <h4 className="font-medium text-foreground mb-6">Company</h4>
            <ul className="space-y-3">
              <li>
                <Link to={storefrontRoutes.about} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  About Us
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.events} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Events / Our Story
                </Link>
              </li>
              <li>
                <Link to={storefrontRoutes.contact} className="text-sm text-muted-foreground hover:text-primary transition-colors block">
                  Contact Us
                </Link>
              </li>
            </ul>

            <div className="mt-6">
              <div className="flex gap-3">
                {socialLinks.map((social) => (
                  <a
                    key={social.label}
                    href={social.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="w-9 h-9 rounded-full border border-input flex items-center justify-center text-muted-foreground hover:border-primary hover:text-primary transition-all duration-300 bg-white"
                    aria-label={social.label}
                    title={social.label}
                  >
                    <Icon icon={social.icon} className="h-4 w-4" />
                  </a>
                ))}
              </div>
            </div>
          </div>

          {/* Our Stores */}
          <div>
            <h4 className="font-medium text-foreground mb-6">Our Stores</h4>
            <div className="space-y-4 text-sm text-muted-foreground leading-relaxed">
              <div>
                <p className="font-medium text-foreground">Suntec City Mall</p>
                <p>3 Temasek Boulevard #03-364/365</p>
                <p>Singapore 038983</p>
              </div>
              <div>
                <p className="font-medium text-foreground">Opening Hours</p>
                <p>11.30am-8pm Daily</p>
              </div>
            </div>
          </div>
        </div>

        <div className="mt-16 pt-8 border-t border-border flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-muted-foreground">
          <p>&copy; {new Date().getFullYear()} InfinitiKpop. All rights reserved.</p>
          <Link to={storefrontRoutes.home} className="inline-flex items-center gap-2 hover:text-primary transition-colors">
            <img src={STOREFRONT_LOGO_URL} alt="InfinitiKPOP" className="h-12 md:h-14 w-auto object-contain" />
          </Link>
        </div>
      </div>
    </footer>
  );
};
