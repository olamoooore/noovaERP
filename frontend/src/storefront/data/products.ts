export interface Product {
  id: string;
  title: string;
  brand: string;
  type: 'Album' | 'DVD' | "Season's Greetings" | 'Lightstick' | 'Merchandise' | 'Photocard' | 'Miscellaneous';
  collection: string;
  price: number;
  memberPrice?: number;
  image: string;
  tags: ('limited' | 'preorder' | 'new' | 'bestseller')[];
  description?: string;
}

export const PRODUCTS: Product[] = [
  {
    id: "p001",
    title: "SEVENTEEN Album – 13th Mini",
    brand: "SEVENTEEN",
    type: "Album",
    collection: "Comeback 2025",
    price: 29.90,
    memberPrice: 26.91,
    image: "https://images.unsplash.com/photo-1619983081563-430f63602796?w=800&q=80",
    tags: ["preorder", "new"],
    description: "The highly anticipated 13th mini album featuring the iconic 13 members."
  },
  {
    id: "p002",
    title: "BTS Lightstick Ver.4",
    brand: "BTS",
    type: "Lightstick",
    collection: "Limited Edition",
    price: 119.00,
    memberPrice: 107.10,
    image: "https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=800&q=80",
    tags: ["limited", "bestseller"],
    description: "Official ARMY bomb with Bluetooth connectivity and premium finish."
  },
  {
    id: "p003",
    title: "BLACKPINK Photocard Set",
    brand: "BLACKPINK",
    type: "Photocard",
    collection: "Limited Edition",
    price: 24.50,
    memberPrice: 22.05,
    image: "https://images.unsplash.com/photo-1514525253161-7a46d19cd819?w=800&q=80",
    tags: ["limited"],
    description: "Exclusive photocard set featuring all four members in stunning visuals."
  },
  {
    id: "p004",
    title: "TXT Hoodie – Moonrise Tour",
    brand: "TXT",
    type: "Merchandise",
    collection: "Limited Edition",
    price: 49.00,
    memberPrice: 44.10,
    image: "https://images.unsplash.com/photo-1556821840-3a63f95609a7?w=800&q=80",
    tags: ["limited", "new"],
    description: "Premium quality hoodie from the exclusive Moonrise World Tour collection."
  },
  {
    id: "p005",
    title: "IVE Poster Collage",
    brand: "IVE",
    type: "Merchandise",
    collection: "Pre-Order",
    price: 14.99,
    memberPrice: 13.49,
    image: "https://images.unsplash.com/photo-1598387993441-a364f854c3e1?w=800&q=80",
    tags: ["preorder"],
    description: "Beautiful collage poster featuring IVE members in artistic compositions."
  },
  {
    id: "p006",
    title: "SEVENTEEN Photobook – Deluxe",
    brand: "SEVENTEEN",
    type: "Album",
    collection: "Limited Edition",
    price: 45.00,
    memberPrice: 40.50,
    image: "https://images.unsplash.com/photo-1516981879613-9f5da904015f?w=800&q=80",
    tags: ["limited", "bestseller"],
    description: "Deluxe photobook with over 200 pages of exclusive behind-the-scenes content."
  },
  {
    id: "p007",
    title: "BTS Jacket – Stadium",
    brand: "BTS",
    type: "Merchandise",
    collection: "Comeback 2025",
    price: 89.00,
    memberPrice: 80.10,
    image: "https://images.unsplash.com/photo-1551028719-00167b16eac5?w=800&q=80",
    tags: ["new"],
    description: "Premium stadium jacket with embroidered BTS logo and satin lining."
  },
  {
    id: "p008",
    title: "BLACKPINK Necklace – Fan Edition",
    brand: "BLACKPINK",
    type: "Merchandise",
    collection: "Limited Edition",
    price: 39.90,
    memberPrice: 35.91,
    image: "https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=800&q=80",
    tags: ["limited"],
    description: "Elegant pendant necklace with BLACKPINK logo in rose gold finish."
  },
  {
    id: "p009",
    title: "TXT Photocard Box",
    brand: "TXT",
    type: "Photocard",
    collection: "Pre-Order",
    price: 19.50,
    memberPrice: 17.55,
    image: "https://images.unsplash.com/photo-1611532736597-de2d4265fba3?w=800&q=80",
    tags: ["preorder"],
    description: "Collector's box containing 50 exclusive photocards with random pulls."
  },
  {
    id: "p010",
    title: "IVE Lightstick Mini",
    brand: "IVE",
    type: "Lightstick",
    collection: "Limited Edition",
    price: 59.00,
    memberPrice: 53.10,
    image: "https://images.unsplash.com/photo-1470225620780-dba8ba36b745?w=800&q=80",
    tags: ["limited", "new"],
    description: "Compact version of the official IVE lightstick, perfect for everyday carry."
  },
  {
    id: "p011",
    title: "SEVENTEEN Tote Bag",
    brand: "SEVENTEEN",
    type: "Merchandise",
    collection: "Regular",
    price: 19.00,
    memberPrice: 17.10,
    image: "https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?w=800&q=80",
    tags: ["bestseller"],
    description: "Durable canvas tote bag with SEVENTEEN logo print and spacious interior."
  },
  {
    id: "p013",
    title: "SEVENTEEN Concert DVD – Encore Night",
    brand: "SEVENTEEN",
    type: "DVD",
    collection: "Limited Edition",
    price: 54.90,
    memberPrice: 49.41,
    image: "https://images.unsplash.com/photo-1524721696987-b9527df9e512?w=800&q=80",
    tags: ["limited", "bestseller"],
    description: "Official concert DVD with behind-the-scenes footage and photobook insert."
  },
  {
    id: "p014",
    title: "BTS Season's Greetings 2026",
    brand: "BTS",
    type: "Season's Greetings",
    collection: "Pre-Order",
    price: 79.00,
    memberPrice: 71.10,
    image: "https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&q=80",
    tags: ["preorder", "new"],
    description: "Calendar, diary, mini posters, stickers, and exclusive photo set in one premium box."
  },
  {
    id: "p015",
    title: "BLACKPINK Official Lanyard",
    brand: "BLACKPINK",
    type: "Miscellaneous",
    collection: "Regular",
    price: 9.90,
    memberPrice: 8.91,
    image: "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=800&q=80",
    tags: ["new"],
    description: "Sturdy lanyard with detachable clip—perfect for cards, keys, and concert passes."
  },
  {
    id: "p016",
    title: "TXT Acrylic Keychain Set",
    brand: "TXT",
    type: "Miscellaneous",
    collection: "Limited Edition",
    price: 16.50,
    memberPrice: 14.85,
    image: "https://images.unsplash.com/photo-1520975916090-3105956dac38?w=800&q=80",
    tags: ["limited", "new"],
    description: "Set of 5 acrylic keychains with protective film and premium printing."
  },
  {
    id: "p012",
    title: "BTS Photocard Set",
    brand: "BTS",
    type: "Photocard",
    collection: "Comeback 2025",
    price: 22.50,
    memberPrice: 20.25,
    image: "https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=800&q=80",
    tags: ["new", "preorder"],
    description: "Complete set of photocards from the latest comeback album."
  },
];

export const BRANDS = ['SEVENTEEN', 'BTS', 'BLACKPINK', 'TXT', 'IVE'] as const;
export const TYPES = ['Album', 'DVD', "Season's Greetings", 'Lightstick', 'Merchandise', 'Photocard', 'Miscellaneous'] as const;
export const COLLECTIONS = ['Comeback 2025', 'Limited Edition', 'Pre-Order', 'Regular'] as const;

export type Brand = typeof BRANDS[number];
export type ProductType = typeof TYPES[number];
export type Collection = typeof COLLECTIONS[number];
