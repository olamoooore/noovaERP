import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  Mail,
  Phone,
  MapPin,
  Clock,
  MessageSquare,
  Send,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { Icon } from '@iconify/react';

/* ─── Page ──────────────────────────────────────────────── */

const Contact = () => {
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
                <MessageSquare className="h-6 w-6 text-primary" />
              </div>
            </div>
            <h1 className="text-3xl md:text-5xl font-display font-bold tracking-tight mb-4">
              Contact Us
            </h1>
            <p className="text-muted-foreground text-lg">
              We&apos;d love to hear from you. Reach out via any of the channels below.
            </p>
          </div>
        </div>
      </section>

      {/* Contact Cards */}
      <section className="container py-12 md:py-16">
        <div className="max-w-4xl mx-auto">
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {/* Phone */}
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm text-center">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                <Phone className="h-6 w-6 text-primary" />
              </div>
              <h3 className="font-display font-bold text-lg mb-2">Phone</h3>
              <p className="text-sm text-muted-foreground mb-3">Message only</p>
              <a
                href="https://wa.me/6598529838"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:text-primary/80 transition-colors"
              >
                <Icon icon="simple-icons:whatsapp" className="h-4 w-4" />
                +65 9852 9838
              </a>
            </div>

            {/* Email */}
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm text-center">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                <Mail className="h-6 w-6 text-primary" />
              </div>
              <h3 className="font-display font-bold text-lg mb-2">Email</h3>
              <p className="text-sm text-muted-foreground mb-3">General enquiries</p>
              <a
                href="mailto:hello@infinitykpop.com.sg"
                className="text-sm font-medium text-primary hover:text-primary/80 transition-colors"
              >
                hello@infinitykpop.com.sg
              </a>
            </div>

            {/* Social */}
            <div className="rounded-2xl border border-border/60 bg-white p-6 shadow-sm text-center sm:col-span-2 lg:col-span-1">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 mx-auto mb-4">
                <Send className="h-6 w-6 text-primary" />
              </div>
              <h3 className="font-display font-bold text-lg mb-2">Social Media</h3>
              <p className="text-sm text-muted-foreground mb-3">Follow &amp; DM us</p>
              <div className="flex gap-3 justify-center">
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
                  href="https://twitter.com/InfinityKpopSG"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="w-9 h-9 rounded-full border border-input flex items-center justify-center text-muted-foreground hover:border-primary hover:text-primary transition-all duration-300 bg-white"
                  aria-label="X (Twitter)"
                >
                  <Icon icon="simple-icons:x" className="h-4 w-4" />
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
      </section>

      {/* Store Info */}
      <section className="container pb-12 md:pb-16">
        <div className="max-w-4xl mx-auto">
          <div className="rounded-2xl border border-border/60 bg-white p-8 md:p-12 shadow-sm">
            <h2 className="text-2xl font-display font-bold mb-8 text-center">Our Store</h2>
            <div className="grid gap-8 sm:grid-cols-2">
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                  <MapPin className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground mb-1">Address</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    Suntec City Mall<br />
                    3 Temasek Boulevard #03-364/365<br />
                    Singapore 038983
                  </p>
                </div>
              </div>
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                  <Clock className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground mb-1">Opening Hours</h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    11.30am – 8pm<br />
                    Open Daily
                  </p>
                </div>
              </div>
            </div>

            {/* Map embed */}
            <div className="mt-8 rounded-xl overflow-hidden border border-border/60">
              <iframe
                title="Infinity Kpop Store Location"
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.7994!2d103.8579!3d1.2935!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x31da19a6b8e38e87%3A0x5c0f1b9e7b9c0a0a!2sSuntec%20City%20Mall!5e0!3m2!1sen!2ssg!4v1680000000000"
                width="100%"
                height="300"
                style={{ border: 0 }}
                allowFullScreen
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
              />
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="border-t border-border bg-muted/30">
        <div className="container py-12 md:py-16">
          <div className="max-w-xl mx-auto text-center">
            <h2 className="text-2xl font-display font-bold mb-3">Browse our latest collection</h2>
            <p className="text-muted-foreground mb-6">
              Discover the newest K-pop albums, merch, and exclusive items.
            </p>
            <Link
              to={storefrontRoutes.shop}
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
            >
              Browse Shop
            </Link>
          </div>
        </div>
      </section>
    </Layout>
  );
};

export default Contact;
