import { Layout } from '@/storefront/components/layout/Layout';
import { HeroSection } from '@/storefront/components/home/HeroSection';
import { FeaturedProducts } from '@/storefront/components/home/FeaturedProducts';
import { BrandsSection } from '@/storefront/components/home/BrandsSection';
import { EventsSection } from '@/storefront/components/home/EventsSection';
import { MembershipBanner } from '@/storefront/components/home/MembershipBanner';

const Index = () => {
  return (
    <Layout>
      <HeroSection />
      <BrandsSection />
      <FeaturedProducts />
      <EventsSection />
      <MembershipBanner />
    </Layout>
  );
};

export default Index;
