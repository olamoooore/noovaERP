import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  Truck,
  Clock,
  MapPin,
  Package,
  CreditCard,
  AlertTriangle,
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
    title: 'Delivery Timeframes',
    icon: Clock,
    items: [
      {
        question: 'How long does delivery take for in-stock items?',
        answer:
          'Product preparation is within 7 working days from the completion of payment, after which it will be dispatched. Delivery may be delayed during local/Korea public holidays or when there is a high volume of orders for specific products.',
      },
      {
        question: 'How long does delivery take for pre-order items?',
        answer:
          'Waiting time varies depending on the item. Product preparation for pre-order items is within 7 days to 3 months from the arrival of items. Delivery may be delayed during local/Korea public holidays or when there is a high volume of orders.',
      },
    ],
  },
  {
    title: 'Shipping Rates',
    icon: CreditCard,
    items: [
      {
        question: 'How much does local delivery cost?',
        answer:
          'Local delivery is $10–$15 depending on the weight of items.',
      },
      {
        question: 'How much does international delivery cost?',
        answer:
          'International delivery charges vary depending on shipping location and the size & weight of the purchased items. Exact charges will be calculated at checkout.',
      },
    ],
  },
  {
    title: 'Order Tracking',
    icon: Package,
    items: [
      {
        question: 'How can I track my shipment?',
        answer:
          'When the order status is marked as "In Transit", it means the item has been handed over to the delivery company. A tracking number will be provided in your order summary once the courier company has generated it.',
      },
      {
        question: 'Can I change my delivery address after placing an order?',
        answer:
          'Customers cannot modify or change the delivery address themselves. Please send a request via the 1:1 query page. Address changes are only possible before the product is dispatched to the delivery company.',
      },
      {
        question: 'Can my orders be shipped together (combined shipping)?',
        answer:
          'Orders with different order numbers cannot be shipped together — all orders are shipped individually by order number. If you want combined shipping, please add all items to your cart and pay for them together in a single order.',
      },
    ],
  },
  {
    title: 'Self Collection',
    icon: MapPin,
    items: [
      {
        question: 'Where is the collection location?',
        answer:
          'Store pick-up is available at 3 Temasek Boulevard, Suntec City Mall Tower 3 & 4 #03-364/365 Singapore 038983, from 12pm – 7pm.',
      },
      {
        question: 'How long do I have to collect my order?',
        answer:
          'All pre-order items must be collected within 2 weeks of item arrival. For in-stock items, the 2-week period starts from the date of purchase. Failure to collect within the collection period will void the order with no refund or exchange.',
      },
      {
        question: 'What documents do I need for collection?',
        answer:
          'Please present a screenshot of your order confirmation page when collecting your order. Someone else may collect on your behalf with the same screenshot.',
      },
    ],
  },
  {
    title: 'Issues & Lost Parcels',
    icon: AlertTriangle,
    items: [
      {
        question: 'What happens if my parcel is lost?',
        answer:
          'Tracking numbers are provided in your order summary once items have been shipped. Parcel delivery is out of our control, so in the case of a lost parcel, unless the courier company compensates us, we would be unable to issue a refund.',
      },
      {
        question: 'What if my parcel is returned to the seller?',
        answer:
          'Parcels returned due to incomplete address, unclaimed, refused, or wrong address are subject to no refunds. Another delivery fare must be paid for the parcel to be shipped again. We will contact you via email, and if delivery fare is not paid within 2 weeks of notification, the item will be cancelled with no refund.',
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

/* ─── Page ──────────────────────────────────────────────── */

const Shipping = () => {
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
                <Truck className="h-6 w-6 text-primary" />
              </div>
            </div>
            <h1 className="text-3xl md:text-5xl font-display font-bold tracking-tight mb-4">
              Order &amp; Shipping
            </h1>
            <p className="text-muted-foreground text-lg">
              Everything you need to know about placing orders, delivery timeframes, shipping rates, and self-collection.
            </p>
          </div>
        </div>
      </section>

      {/* Content */}
      <section className="container py-12 md:py-16">
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
            <h2 className="text-2xl font-display font-bold mb-3">Need help with your order?</h2>
            <p className="text-muted-foreground mb-6">
              If you have questions about an existing order or need to make changes, don&apos;t hesitate to reach out.
            </p>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <Link
                to={storefrontPath('/contact')}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
              >
                <Truck className="h-4 w-4" />
                Contact Us
              </Link>
              <Link
                to={storefrontRoutes.faq}
                className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-white px-6 py-3 text-sm font-semibold text-foreground hover:bg-muted transition-all"
              >
                More FAQ
              </Link>
            </div>
          </div>
        </div>
      </section>
    </Layout>
  );
};

export default Shipping;
