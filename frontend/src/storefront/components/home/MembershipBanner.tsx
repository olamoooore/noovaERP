import { Link } from 'react-router-dom';
import { Crown, Star, Gift, Percent } from 'lucide-react';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { storefrontRoutes } from '@/storefront/lib/paths';

const benefits = [
  { icon: Percent, title: 'Up to 15% Off', description: 'Exclusive member discounts' },
  { icon: Star, title: 'Early Access', description: 'Shop new drops first' },
  { icon: Gift, title: 'Birthday Perks', description: 'Special treats on your day' },
  { icon: Crown, title: 'VIP Events', description: 'Member-only experiences' },
];

export const MembershipBanner = () => {
  return (
    <section className="py-16 md:py-24 relative overflow-hidden">
      {/* Background Effects */}
      <div className="absolute inset-0 bg-gradient-to-r from-primary/10 via-secondary/10 to-accent/10" />
      <div className="absolute top-0 left-1/4 w-72 h-72 rounded-full bg-primary/20 blur-3xl" />
      <div className="absolute bottom-0 right-1/4 w-96 h-96 rounded-full bg-accent/15 blur-3xl" />

      <div className="container relative">
        <div className="glass-strong rounded-3xl p-8 md:p-12 lg:p-16">
          <div className="grid lg:grid-cols-2 gap-10 items-center">
            {/* Content */}
            <div>
              <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/20 text-primary mb-6">
                <Crown className="h-4 w-4" />
                <span className="text-sm font-semibold">Membership Program</span>
              </div>
              
              <h2 className="text-3xl md:text-4xl lg:text-5xl font-display font-bold mb-4">
                Join the{' '}
                <span className="gradient-text">INFINITI Club</span>
              </h2>
              
              <p className="text-lg text-muted-foreground mb-8">
                Unlock exclusive perks, earn points on every purchase, and get VIP access to limited drops and fan events. Start your journey today!
              </p>

              <div className="flex flex-col sm:flex-row gap-4">
                <Button variant="hero" size="lg" asChild>
                  <Link to={storefrontRoutes.membership}>Join Now - It's Free</Link>
                </Button>
                <Button variant="glass" size="lg" asChild>
                  <Link to={`${storefrontRoutes.membership}#benefits`}>Learn More</Link>
                </Button>
              </div>
            </div>

            {/* Benefits Grid */}
            <div className="grid grid-cols-2 gap-4">
              {benefits.map((benefit, index) => (
                <div
                  key={benefit.title}
                  className="group p-5 rounded-xl bg-muted/50 hover:bg-muted transition-colors animate-fade-in"
                  style={{ animationDelay: `${index * 100}ms` }}
                >
                  <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-primary/20 to-accent/20 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <benefit.icon className="h-6 w-6 text-primary" />
                  </div>
                  <h3 className="font-display font-semibold mb-1">{benefit.title}</h3>
                  <p className="text-sm text-muted-foreground">{benefit.description}</p>
                </div>
              ))}
            </div>
          </div>

          {/* Tier Preview */}
          <div className="mt-10 pt-10 border-t border-border/50">
            <div className="text-center mb-6">
              <p className="text-sm font-medium text-foreground">Membership Tiers</p>
              <p className="text-sm text-muted-foreground">Pick your perks level</p>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="rounded-2xl border border-border bg-background/60 p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-muted/60 flex items-center justify-center text-lg" aria-hidden="true">
                      🆓
                    </div>
                    <div>
                      <p className="font-display font-semibold leading-tight">Free</p>
                      <p className="text-xs text-muted-foreground">Get started</p>
                    </div>
                  </div>
                  <Badge variant="outline">0% off</Badge>
                </div>
              </div>

              <div className="rounded-2xl border border-border bg-background/60 p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-muted/60 flex items-center justify-center text-lg" aria-hidden="true">
                      🥉
                    </div>
                    <div>
                      <p className="font-display font-semibold leading-tight">Bronze</p>
                      <p className="text-xs text-muted-foreground">5% off</p>
                    </div>
                  </div>
                  <Badge variant="bronze">5% off</Badge>
                </div>
              </div>

              <div className="rounded-2xl border border-border bg-background/60 p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-muted/60 flex items-center justify-center text-lg" aria-hidden="true">
                      🥈
                    </div>
                    <div>
                      <p className="font-display font-semibold leading-tight">Silver</p>
                      <p className="text-xs text-muted-foreground">10% off</p>
                    </div>
                  </div>
                  <Badge variant="silver">10% off</Badge>
                </div>
              </div>

              <div className="rounded-2xl border border-border bg-background/60 p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-xl bg-muted/60 flex items-center justify-center text-lg" aria-hidden="true">
                      🥇
                    </div>
                    <div>
                      <p className="font-display font-semibold leading-tight">Gold</p>
                      <p className="text-xs text-muted-foreground">15% off</p>
                    </div>
                  </div>
                  <Badge variant="gold">15% off</Badge>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};
