import { Link } from 'react-router-dom';
import { BRANDS } from '@/storefront/data/products';

const brandLogos: Record<string, { color: string; accent: string }> = {
  SEVENTEEN: { color: '#FF5C8D', accent: '#FFC4D6' },
  BTS: { color: '#9B59B6', accent: '#E8D5F2' },
  BLACKPINK: { color: '#FF69B4', accent: '#FFD1E8' },
  TXT: { color: '#00D1FF', accent: '#B3F0FF' },
  IVE: { color: '#FFD700', accent: '#FFF4B3' },
};

export const BrandsSection = () => {
  return (
    <section className="py-16 md:py-20 border-y border-border/50">
      <div className="container">
        <p className="text-center text-muted-foreground mb-10">
          Official merchandise from your favorite artists
        </p>

        <div className="flex flex-wrap justify-center items-center gap-8 md:gap-16">
          {BRANDS.map((brand, index) => (
            <Link
              key={brand}
              to={`/shop?brand=${brand}`}
              className="group relative animate-fade-in"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              <div
                className="w-28 h-28 md:w-36 md:h-36 rounded-2xl flex items-center justify-center transition-all duration-300 group-hover:scale-110"
                style={{
                  background: `linear-gradient(135deg, ${brandLogos[brand].color}20 0%, ${brandLogos[brand].accent}15 100%)`,
                }}
              >
                <span
                  className="text-xl md:text-2xl font-display font-bold transition-all duration-300 group-hover:scale-105"
                  style={{ color: brandLogos[brand].color }}
                >
                  {brand}
                </span>
              </div>
              
              {/* Glow effect on hover */}
              <div
                className="absolute inset-0 rounded-2xl opacity-0 group-hover:opacity-50 blur-xl transition-opacity duration-300 -z-10"
                style={{ background: brandLogos[brand].color }}
              />
            </Link>
          ))}
        </div>
      </div>
    </section>
  );
};
