import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ChevronDown,
  Truck,
  ShoppingBag,
  Package,
  RefreshCcw,
  Users,
  HelpCircle,
  MapPin,
  ArrowLeft,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { cn } from '@/lib/utils';
import { storefrontRoutes } from '@/storefront/lib/paths';

/* ─── FAQ Data ──────────────────────────────────────────── */

interface FAQItem {
  question: string;
  answer: string;
}

interface FAQSection {
  title: string;
  icon: React.ElementType;
  items: FAQItem[];
}

const faqSections: FAQSection[] = [
  {
    title: 'Shipping',
    icon: Truck,
    items: [
      {
        question: 'Can I change my delivery address after placing an order?',
        answer:
          'Customers cannot modify or change the delivery address themselves. Please send a request via the 1:1 query page. Address changes are only possible before the product is dispatched to the delivery company.',
      },
      {
        question: 'Can my orders be shipped together (combined shipping)?',
        answer:
          'Orders with different order numbers cannot be shipped together — all orders are shipped individually by order number. If you want combined shipping, please add all items to your cart and pay for them together in a single order. If you already placed separate orders, please cancel them, add items to your cart, and place a new order.',
      },
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
      {
        question: 'How can I track my shipment?',
        answer:
          'When the order status is marked as "In Transit", it means the item has been handed over to the delivery company. A tracking number will be provided in your order summary once the courier company has generated it.',
      },
      {
        question: 'How much does delivery cost?',
        answer:
          'Local delivery is $10–$15 depending on the weight of items. International delivery charges vary depending on shipping location and the size & weight of the purchased items.',
      },
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
  {
    title: 'Self Collection',
    icon: MapPin,
    items: [
      {
        question: 'What documents do I need for collection?',
        answer:
          'Please present a screenshot of your order confirmation page when collecting your order.',
      },
      {
        question: 'How long do I have to collect my order?',
        answer:
          'All pre-order items must be collected within 2 weeks of item arrival. For in-stock items, the 2-week period starts from the date of purchase. Failure to collect within the collection period will void the order with no refund or exchange. For collection extension, please write in by email (subject to approval).',
      },
      {
        question: 'Where is the collection location?',
        answer:
          'Store pick-up is available at 3 Temasek Boulevard, Suntec City Mall Tower 3 & 4 #03-364/365 Singapore 038983, from 12pm – 7pm.',
      },
      {
        question: 'Can someone else collect on my behalf?',
        answer:
          'Yes. The person collecting must present a screenshot of the customer\'s order confirmation page.',
      },
    ],
  },
  {
    title: 'Orders',
    icon: ShoppingBag,
    items: [
      {
        question: 'Can I cancel a pre-order item?',
        answer:
          'Pre-order items are ordered according to the number of orders within the pre-order period. Hence, order cancellation and quantity modification is not possible.',
      },
      {
        question: 'How can I check my order status?',
        answer:
          'If you are a member, check your order history in Account → Order History. If you are a non-member, you can check your order by entering the order number. Please make sure to remember your order number and password. If you do not know your order number, please inquire through the 1:1 inquiry page.',
      },
      {
        question: 'How do I cancel an order?',
        answer:
          'Please send your order number, product name, and reason for cancellation through the 1:1 inquiry page. Pre-order items may be difficult to cancel after payment is completed. Order cancellation for all event orders is not possible.',
      },
      {
        question: 'Can I change my order quantity or option?',
        answer:
          'It is not possible to change order quantity or option after payment is made. Please cancel and make a new order.',
      },
      {
        question: 'What payment methods are accepted?',
        answer:
          'Local payment methods accepted are PayNow/PayLah. All card (domestic/international) payments will incur an additional 4% admin charge. Infinity vouchers (unique barcode number) are also accepted.',
      },
    ],
  },
  {
    title: 'Exchange & Refund',
    icon: RefreshCcw,
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
      {
        question: 'What about defects on in-store purchases?',
        answer:
          'A store-issued receipt must be clearly visible in the unboxing video. Without an unboxing video, no action will be taken. If an exchange is possible, please bring the item to the store within 3 days of notification.',
      },
    ],
  },
  {
    title: 'Membership',
    icon: Users,
    items: [
      {
        question: 'I forgot my ID or password — how do I recover it?',
        answer:
          'Please use the Forget ID / Forget Password function on the login page to recover your account.',
      },
      {
        question: 'How do membership tiers work?',
        answer:
          'Bronze: upon registration ($1 = 1pt). Silver: spend at least $1,000 in 365 days ($1 = 1.5pt + $10 voucher). Gold: spend at least $2,000 in 365 days ($1 = 2pt + $20 voucher). Platinum: spend at least $3,000 in 365 days ($1 = 3pt + $30 voucher).',
      },
      {
        question: 'How do points work?',
        answer:
          'Points are accumulated according to your tier. They are non-transferrable and converted at $0.01 per point. Points can be used for payment or redeemed for vouchers ($5 / $10 / $20 / $50). Points validity is 12 months.',
      },
      {
        question: 'What benefits do members get?',
        answer:
          'Birthday month perk: 1× $10 complimentary voucher (issued on the 1st of the month, valid during the month only). Friend recommendation reward: 500 points after your friend\'s first minimum spend of $20.',
      },
      {
        question: 'How do vouchers work?',
        answer:
          'Cash voucher purchases are eligible for points accumulation. Voucher codes can be shared but only used once. Vouchers are only applicable to original-priced items (not discounted/sale items). Voucher validity is 12 months from date of purchase.',
      },
    ],
  },
  {
    title: 'Others',
    icon: HelpCircle,
    items: [
      {
        question: 'I was selected for a fansign — what do I need to know?',
        answer:
          'A separate email will be sent to all winners with the necessary info regarding the event.',
      },
      {
        question: 'Where can I view the fansign/event winner list?',
        answer:
          'You can view the winner announcement under Event Page → Winner Announcement.',
      },
      {
        question: 'Where is the offline store located?',
        answer:
          '3 Temasek Boulevard, Suntec City Mall Tower 3 & 4 #03-364/365, Singapore 038983.',
      },
      {
        question: 'Will there be a restock for sold-out items?',
        answer:
          'We are unable to provide updates on restocking or resale schedules for items that are currently sold out.',
      },
      {
        question: 'I received duplicate POB/LD/Benefit Photocards — can I exchange them?',
        answer:
          'All benefit photocards are given at random unless stated otherwise. If the event notice states items will be shipped without duplication, they are sent without duplicates within a single order. Multiple orders may result in duplicates. If you require an exchange, please leave a message through the 1:1 query page within 7 working days with a picture of the shipping label and an unboxing video. Exchanges are processed on a case-by-case basis.',
      },
    ],
  },
];

/* ─── Accordion Item ────────────────────────────────────── */

function AccordionItem({ item, isOpen, onToggle }: { item: FAQItem; isOpen: boolean; onToggle: () => void }) {
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

function FAQSectionCard({ section, openKey, setOpenKey }: {
  section: FAQSection;
  openKey: string | null;
  setOpenKey: (key: string | null) => void;
}) {
  const Icon = section.icon;

  return (
    <div className="rounded-2xl border border-border/60 bg-white p-6 md:p-8 shadow-sm">
      <div className="flex items-center gap-3 mb-6">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
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

/* ─── Category Pills ────────────────────────────────────── */

function CategoryPills({
  sections,
  active,
  onChange,
}: {
  sections: FAQSection[];
  active: string | null;
  onChange: (title: string | null) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2 justify-center">
      <button
        type="button"
        onClick={() => onChange(null)}
        className={cn(
          'rounded-full px-5 py-2 text-sm font-medium transition-all duration-200 border',
          active === null
            ? 'bg-primary text-primary-foreground border-primary shadow-md'
            : 'bg-white text-muted-foreground border-border hover:border-primary/40 hover:text-foreground',
        )}
      >
        All
      </button>
      {sections.map((s) => {
        const Icon = s.icon;
        return (
          <button
            key={s.title}
            type="button"
            onClick={() => onChange(s.title)}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-full px-5 py-2 text-sm font-medium transition-all duration-200 border',
              active === s.title
                ? 'bg-primary text-primary-foreground border-primary shadow-md'
                : 'bg-white text-muted-foreground border-border hover:border-primary/40 hover:text-foreground',
            )}
          >
            <Icon className="h-3.5 w-3.5" />
            {s.title}
          </button>
        );
      })}
    </div>
  );
}

/* ─── Page ──────────────────────────────────────────────── */

const FAQ = () => {
  const [openKey, setOpenKey] = useState<string | null>(null);
  const [activeCategory, setActiveCategory] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  const filtered = faqSections
    .filter((s) => !activeCategory || s.title === activeCategory)
    .map((s) => ({
      ...s,
      items: s.items.filter(
        (item) =>
          !search ||
          item.question.toLowerCase().includes(search.toLowerCase()) ||
          item.answer.toLowerCase().includes(search.toLowerCase()),
      ),
    }))
    .filter((s) => s.items.length > 0);

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
            <h1 className="text-3xl md:text-5xl font-display font-bold tracking-tight mb-4">
              Frequently Asked Questions
            </h1>
            <p className="text-muted-foreground text-lg mb-8">
              Find answers to common questions about orders, shipping, membership, and more.
            </p>

            {/* Search */}
            <div className="relative max-w-md mx-auto">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search for a question..."
                className="w-full rounded-full border border-border bg-white px-5 py-3 pl-12 text-sm shadow-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all"
              />
              <svg
                className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={2}
              >
                <circle cx={11} cy={11} r={8} />
                <path d="m21 21-4.35-4.35" strokeLinecap="round" />
              </svg>
            </div>
          </div>
        </div>
      </section>

      {/* Category Pills */}
      <section className="container py-6">
        <CategoryPills sections={faqSections} active={activeCategory} onChange={setActiveCategory} />
      </section>

      {/* FAQ Sections */}
      <section className="container pb-16 md:pb-24">
        {filtered.length === 0 ? (
          <div className="text-center py-16">
            <HelpCircle className="h-12 w-12 text-muted-foreground/40 mx-auto mb-4" />
            <p className="text-muted-foreground">No results found. Try a different search term.</p>
          </div>
        ) : (
          <div className="grid gap-8 max-w-3xl mx-auto">
            {filtered.map((section) => (
              <FAQSectionCard
                key={section.title}
                section={section}
                openKey={openKey}
                setOpenKey={setOpenKey}
              />
            ))}
          </div>
        )}
      </section>

      {/* Contact CTA */}
      <section className="border-t border-border bg-muted/30">
        <div className="container py-12 md:py-16">
          <div className="max-w-xl mx-auto text-center">
            <h2 className="text-2xl font-display font-bold mb-3">Still have questions?</h2>
            <p className="text-muted-foreground mb-6">
              Can&apos;t find what you&apos;re looking for? Reach out via our 1:1 inquiry page and we&apos;ll get back to you as soon as possible.
            </p>
            <div className="flex flex-col sm:flex-row gap-3 justify-center">
              <Link
                to={storefrontRoutes.home}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-lg hover:bg-primary/90 transition-all"
              >
                <Package className="h-4 w-4" />
                Browse Store
              </Link>
              <Link
                to={storefrontRoutes.account}
                className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-white px-6 py-3 text-sm font-semibold text-foreground hover:bg-muted transition-all"
              >
                <HelpCircle className="h-4 w-4" />
                Contact Us
              </Link>
            </div>
          </div>
        </div>
      </section>
    </Layout>
  );
};

export default FAQ;
