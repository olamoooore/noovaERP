import { Link } from 'react-router-dom';
import { Home, ArrowLeft } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { storefrontRoutes } from '@/storefront/lib/paths';

const NotFound = () => {
  return (
    <Layout>
      <div className="container py-16 md:py-24">
        <div className="max-w-lg mx-auto text-center">
          {/* 404 Display */}
          <div className="relative mb-8">
            <span className="text-[150px] md:text-[200px] font-display font-bold leading-none gradient-text opacity-20">
              404
            </span>
            <div className="absolute inset-0 flex items-center justify-center">
              <span className="text-6xl md:text-8xl font-display font-bold gradient-text neon-text">
                Oops!
              </span>
            </div>
          </div>

          <h1 className="text-2xl md:text-3xl font-display font-bold mb-4">
            Page Not Found
          </h1>
          <p className="text-muted-foreground mb-8">
            The page you're looking for seems to have wandered off to a concert. Let's get you back on track!
          </p>

          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Button variant="hero" asChild>
              <Link to={storefrontRoutes.home}>
                <Home className="h-4 w-4 mr-2" />
                Go Home
              </Link>
            </Button>
            <Button variant="glass" asChild>
              <Link to={storefrontRoutes.shop}>
                <ArrowLeft className="h-4 w-4 mr-2" />
                Back to Shop
              </Link>
            </Button>
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default NotFound;
