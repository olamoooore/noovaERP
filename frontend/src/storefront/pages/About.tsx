import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  Heart,
  MapPin,
  Clock,
  Star,
  Users,
  Sparkles,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { storefrontRoutes, storefrontPath } from '@/storefront/lib/paths';
import { Icon } from '@iconify/react';

/* ─── Page ──────────────────────────────────────────────── */

const About = () => {
  return (
    <Layout>
      {/* Hero */}
      <section className="relative bg-gradient-to-b from-primary/5 via-background to-background pt-12 pb-8 md:pt-20 md:pb-12">
        <div className="container">
          <Link
            to={storefrontRoutes.home}
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-primary transition-colors mb-8"
          >
            <ArrowLeft className="h-4 w-4" />
            Back to Store
          </Link>

          <div className="max-w-2xl mx-auto text-center">
            <div className="flex items-center justify-center gap-3 mb-4">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10">
                <Heart className="h-6 w-6 text-primary" />
              </div>
            </div>
            <h1 className="text-3xl md:text-5xl font-display font-bold tracking-tight mb-4">
              About Us
            </h1>
            <p className="text-muted-foreground text-lg">
              Your one-stop K-pop destination in Singapore
            </p>
          </div>
        </div>
      </section>

      {/* Story */}
      <section className="container py-12 md:py-16">
        <div className="max-w-3xl mx-auto">
          <div className="rounded-2xl border border-border/60 bg-white p-8 md:p-12 shadow-sm">
            <div className="flex items-center gap-3 mb-6">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                <Sparkles className="h-5 w-5 text-primary" />
              </div>
              <h2 className="text-2xl font-display font-bold">Our Story</h2>
            </div>
            <div className="space-y-4 text-muted-foreground leading-relaxed">
              <p>
                <strong className="text-foreground">Infinity Kpop</strong> is Singapore&apos;s go-to K-pop retail store, 
                dedicated to bringing fans the latest and most sought-after albums, lightsticks, merchandise, photocards, 
                and exclusive collectibles from their favourite K-pop artists.
              </p>
              <p>
                Located at <strong className="text-foreground">Suntec City Mall</strong>, we strive to create 
                a community for K-pop enthusiasts — a place where fans can discover new releases, 
                connect with fellow fans, and celebrate the music and culture they love.
              </p>
              <p>
                From pre-order events and fansign applications to exclusive in-store experiences, 
                we go beyond retail to deliver an unforgettable K-pop experience in Singapore.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* Values */}
      <section className="container pb-12 md:pb-16">
        <div className="max-w-3xl mx-auto">
          <div className="grid gap-6 sm:grid-cols-2">
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 mb-4">
                <Star className="h-5 w-5 text-primary" />
              </div>
              <h3 className="text-lg font-display font-bold mb-2">Authentic Products</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">
                We source directly from official distributors in Korea to guarantee 100% authentic products for every fan.
              </p>
            </div>
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 mb-4">
                <Users className="h-5 w-5 text-primary" />
              </div>
              <h3 className="text-lg font-display font-bold mb-2">Community First</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">
                Our membership programme, in-store events, and fansign applications create a vibrant community for K-pop lovers.
              </p>
            </div>
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 mb-4">
                <Heart className="h-5 w-5 text-primary" />
              </div>
              <h3 className="text-lg font-display font-bold mb-2">Fan Experience</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">
                From curated collections to exclusive events, every touchpoint is designed to delight and inspire fans.
              </p>
            </div>
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 mb-4">
                <Sparkles className="h-5 w-5 text-primary" />
              </div>
              <h3 className="text-lg font-display font-bold mb-2">Latest Releases</h3>
              <p className="text-sm text-muted-foreground leading-relaxed">
                We stay on top of every comeback and release so you never miss your next must-have album or merch.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* Store Info */}
      <section className="container pb-12 md:pb-16">
        <div className="max-w-3xl mx-auto">
          <div className="rounded-2xl border border-border/60 bg-white p-8 md:p-12 shadow-sm">
            <h2 className="text-2xl font-display font-bold mb-8 text-center">Visit Our Store</h2>
            <div className="grid gap-8 sm:grid-cols-3">
              <div className="text-center">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                  <MapPin className="h-6 w-6 text-primary" />
                </div>
                <h3 className="font-medium text-foreground mb-2">Address</h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  Suntec City Mall<br />
                  3 Temasek Boulevard<br />
                  #03-364/365<br />
                  Singapore 038983
                </p>
              </div>
              <div className="text-center">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                  <Clock className="h-6 w-6 text-primary" />
                </div>
                <h3 className="font-medium text-foreground mb-2">Opening Hours</h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  11.30am – 8pm<br />
                  Open Daily
                </p>
              </div>
              <div className="text-center">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                  <Heart className="h-6 w-6 text-primary" />
                </div>
                <h3 className="font-medium text-foreground mb-2">Follow Us</h3>
                <div className="flex gap-2 justify-center mt-2">
                  <a
                    href="https://www.instagram.com/infinitykpopshop/"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="w-9 h-9 rounded-full border border-input flex items-center justify-center text-muted-foreground hover:border-primary hover:text-primary transition-all duration-300 bg-white"
                    aria-label="Instagram"
                  >
                    <Icon icon="simple-icons:instagram" className="h-4 w-4" />
                  </a>
                  <a
                    href="https://www.tiktok.com/@infinitykpopsg"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="w-9 h-9 rounded-full border border-input flex items-center justify-center text-muted-foreground hover:border-primary hover:text-primary transition-all duration-300 bg-white"
                    aria-label="TikTok"
                  >
                    <Icon icon="simple-icons:tiktok" className="h-4 w-4" />
                  </a>
                  <a
                    href="https://wa.me/message/RLWXPANVCF73I1"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="w-9 h-9 rounded-full border border-input flex items-center justify-center text-muted-foreground hover:border-primary hover:text-primary transition-all duration-300 bg-white"
                    aria-label="WhatsApp"
                  >
                    <Icon icon="simple-icons:whatsapp" className="h-4 w-4" />
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="border-t border-border bg-muted/30">
        <div className="container py-12 md:py-16">
          <div className="max-w-xl mx-auto text-center">
            <h2 className="text-2xl font-display font-bold mb-3">Ready to explore?</h2>
            <p className="text-muted-foreground mb-6">
              Browse our latest collection online or visit us at Suntec City Mall.
            </p>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <Link
                to={storefrontRoutes.shop}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
              >
                Browse Shop
              </Link>
              <Link
                to={storefrontPath('/contact')}
                className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-white px-6 py-3 text-sm font-semibold text-foreground hover:bg-muted transition-all"
              >
                Contact Us
              </Link>
            </div>
          </div>
        </div>
      </section>
    </Layout>
  );
};

export default About;
