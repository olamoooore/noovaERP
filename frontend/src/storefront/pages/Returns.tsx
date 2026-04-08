import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  RefreshCcw,
  AlertTriangle,
  ShieldCheck,
  PackageX,
  ChevronDown,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { storefrontRoutes, storefrontPath } from '@/storefront/lib/paths';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/* ─── Data ──────────────────────────────────────────────── */

interface InfoItem {
  question: string;
  answer: string;
}

interface InfoSection {
  title: string;
  icon: React.ElementType;
  items: InfoItem[];
}

const sections: InfoSection[] = [
  {
    title: 'Return & Exchange Policy',
    icon: ShieldCheck,
    items: [
      {
        question: 'What is your general return policy?',
        answer:
          'We accept returns and exchanges for defective or incorrect items only. All claims must be submitted within 3 working days of product delivery date via the 1:1 query page with proof of the issue.',
      },
      {
        question: 'Can I return an item if I changed my mind?',
        answer:
          'Unfortunately, we do not accept returns for change of mind. Please ensure you check product details carefully before placing your order.',
      },
      {
        question: 'Can I cancel a pre-order item?',
        answer:
          'Pre-order items are ordered according to the number of orders within the pre-order period. Hence, order cancellation and quantity modification is not possible. Order cancellation for all event orders is also not possible.',
      },
    ],
  },
  {
    title: 'Defective Items',
    icon: AlertTriangle,
    items: [
      {
        question: 'What do I do if I receive a defective item?',
        answer:
          'Please leave a message through the 1:1 query page within 3 working days of product delivery date with a picture of the shipping label and an unboxing video of the parcel with the shipping label clearly visible. Without an unboxing video, no action will be taken. Damaged items caused by production error/defect are attended to on a case-by-case basis.',
      },
      {
        question: 'What if items are missing from my order?',
        answer:
          'Please leave a message through the 1:1 query page within 3 working days of product delivery date with a picture of the shipping label and an unboxing video with the shipping label clearly visible. Cuts or edits to the unboxing video are not accepted. Without an unboxing video, no action will be taken.',
      },
      {
        question: 'I received the wrong product — what should I do?',
        answer:
          'Please leave a message through the 1:1 query page within 3 working days with a picture of the shipping label and an unboxing video. If an exchange is required, the original product must be returned within 3 days. The product must be in its original packaging, sealed, and brand new for the exchange to be processed.',
      },
    ],
  },
  {
    title: 'In-Store Purchases',
    icon: PackageX,
    items: [
      {
        question: 'What about defects on in-store purchases?',
        answer:
          'A store-issued receipt must be clearly visible in the unboxing video. Without an unboxing video, no action will be taken. If an exchange is possible, please bring the item to the store within 3 days of notification.',
      },
      {
        question: 'What about duplicate benefit photocards?',
        answer:
          'All benefit photocards are given at random unless stated otherwise. If the event notice states items will be shipped without duplication, they are sent without duplicates within a single order. Multiple orders may result in duplicates. If you require an exchange, please leave a message through the 1:1 query page within 7 working days with a picture of the shipping label and an unboxing video. Exchanges are processed on a case-by-case basis.',
      },
    ],
  },
];

/* ─── Accordion Item ────────────────────────────────────── */

function AccordionItem({ item, isOpen, onToggle }: { item: InfoItem; isOpen: boolean; onToggle: () => void }) {
  return (
    <div className="border-b border-border/60 last:border-b-0">
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-center justify-between gap-4 py-5 text-left transition-colors hover:text-primary"
      >
        <span className="text-[15px] font-medium leading-snug">{item.question}</span>
        <ChevronDown
          className={cn(
            'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
            isOpen && 'rotate-180 text-primary',
          )}
        />
      </button>
      <div
        className={cn(
          'grid transition-all duration-200 ease-in-out',
          isOpen ? 'grid-rows-[1fr] opacity-100 pb-5' : 'grid-rows-[0fr] opacity-0',
        )}
      >
        <div className="overflow-hidden">
          <p className="text-sm leading-relaxed text-muted-foreground">{item.answer}</p>
        </div>
      </div>
    </div>
  );
}

/* ─── Section Card ──────────────────────────────────────── */

function SectionCard({ section, openKey, setOpenKey }: {
  section: InfoSection;
  openKey: string | null;
  setOpenKey: (key: string | null) => void;
}) {
  const SectionIcon = section.icon;

  return (
    <div className="rounded-2xl border border-border/60 bg-white p-6 md:p-8 shadow-sm">
      <div className="flex items-center gap-3 mb-6">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <SectionIcon className="h-5 w-5 text-primary" />
        </div>
        <h2 className="text-xl font-display font-bold">{section.title}</h2>
      </div>
      <div>
        {section.items.map((item, idx) => {
          const key = `${section.title}-${idx}`;
          return (
            <AccordionItem
              key={key}
              item={item}
              isOpen={openKey === key}
              onToggle={() => setOpenKey(openKey === key ? null : key)}
            />
          );
        })}
      </div>
    </div>
  );
}

/* ─── Important Notice ──────────────────────────────────── */

function ImportantNotice() {
  return (
    <div className="rounded-2xl border-2 border-amber-200 bg-amber-50 p-6 md:p-8">
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100">
          <AlertTriangle className="h-5 w-5 text-amber-600" />
        </div>
        <div>
          <h3 className="text-lg font-display font-bold text-amber-900 mb-2">Important</h3>
          <ul className="space-y-2 text-sm text-amber-800 leading-relaxed">
            <li>• All claims must be made within <strong>3 working days</strong> of delivery</li>
            <li>• An <strong>unboxing video</strong> with the shipping label clearly visible is required</li>
            <li>• Items must be in <strong>original packaging, sealed, and brand new</strong></li>
            <li>• Without proper documentation, no action will be taken</li>
          </ul>
        </div>
      </div>
    </div>
  );
}

/* ─── Page ──────────────────────────────────────────────── */

const Returns = () => {
  const [openKey, setOpenKey] = useState<string | null>(null);

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
                <RefreshCcw className="h-6 w-6 text-primary" />
              </div>
            </div>
            <h1 className="text-3xl md:text-5xl font-display font-bold tracking-tight mb-4">
              Returns &amp; Exchanges
            </h1>
            <p className="text-muted-foreground text-lg">
              Our return and exchange policy for online and in-store purchases.
            </p>
          </div>
        </div>
      </section>

      {/* Important Notice */}
      <section className="container pt-8 md:pt-12">
        <div className="max-w-3xl mx-auto">
          <ImportantNotice />
        </div>
      </section>

      {/* Content */}
      <section className="container py-8 md:py-12">
        <div className="grid gap-8 max-w-3xl mx-auto">
          {sections.map((section) => (
            <SectionCard
              key={section.title}
              section={section}
              openKey={openKey}
              setOpenKey={setOpenKey}
            />
          ))}
        </div>
      </section>

      {/* Contact CTA */}
      <section className="border-t border-border bg-muted/30">
        <div className="container py-12 md:py-16">
          <div className="max-w-xl mx-auto text-center">
            <h2 className="text-2xl font-display font-bold mb-3">Need to submit a return?</h2>
            <p className="text-muted-foreground mb-6">
              Please contact us through the 1:1 inquiry page with the required documentation and we&apos;ll assist you promptly.
            </p>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <Link
                to={storefrontPath('/contact')}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
              >
                <RefreshCcw className="h-4 w-4" />
                Contact Us
              </Link>
              <Link
                to={storefrontPath('/shipping')}
                className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-white px-6 py-3 text-sm font-semibold text-foreground hover:bg-muted transition-all"
              >
                Order &amp; Shipping Info
              </Link>
            </div>
          </div>
        </div>
      </section>
    </Layout>
  );
};

export default Returns;
