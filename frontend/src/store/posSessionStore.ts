import { create } from "zustand";
import { persist } from "zustand/middleware";

export interface CartItem {
  id: number;
  name: string;
  price: number;
  sku: string;
  image?: string;
  quantity: number;
  line_subtotal: number;
  line_total: number;
  tax_amount: number;
  tax_rate?: number;
  effective_tax_rate?: number;  // Computed by backend: includes fallback to default system tax
  effective_tax_is_inclusive?: boolean;  // Computed by backend: whether tax is included in price
  // Store original product data if needed for re-calculation
  product_data?: any;
}

export interface Discount {
  type: "fixed" | "percent";
  value: number;
}

// Terminal data from database
export interface PosTerminal {
  id: string;
  name: string;
  code?: string;
  warehouse_id?: string;
  warehouse?: {
    id: number;
    name: string;
  } | null;
  is_active?: boolean;
  has_open_session?: boolean;
  current_session?: {
    id: number;
    session_number: string;
    user_id: number;
  } | null;
}

// Session data from database
export interface PosSession {
  id: number;
  session_number: string;
  terminal_id: number;
  user_id: number;
  status: 'open' | 'closed';
  opening_balance: number;
  opened_at: string;
  terminal?: PosTerminal;
}

interface PosSessionState {
  // Session State (persisted - only session data, not terminal list)
  activeSessionId: number | null;
  activeTerminalId: string | null;
  warehouseId: string | null;
  staffId: string | null;
  openedAt: string | null;

  // Cart State (persisted)
  cart: CartItem[];
  discount: Discount;
  customerId: string;

  // Actions
  setActiveSession: (session: PosSession | null) => void;
  setWarehouse: (warehouseId: string | null) => void;
  startSession: (input: {
    sessionId: number;
    terminalId: string;
    warehouseId: string | null;
    staffId: string | null;
  }) => void;
  clearSession: () => void;

  // Cart Actions
  setCart: (cart: CartItem[]) => void;
  addToCart: (item: CartItem) => void;
  removeFromCart: (itemId: number) => void;
  updateQuantity: (itemId: number, quantity: number) => void;
  clearCart: () => void;
  setDiscount: (discount: Discount) => void;
  setCustomerId: (id: string) => void;
}

export const usePosSessionStore = create<PosSessionState>()(
  persist(
    (set) => ({
      // Session State
      activeSessionId: null,
      activeTerminalId: null,
      warehouseId: null,
      staffId: null,
      openedAt: null,

      // Cart State
      cart: [],
      discount: { type: "fixed", value: 0 },
      customerId: "walk-in",

      setActiveSession: (session) => {
        if (session) {
          set({
            activeSessionId: session.id,
            activeTerminalId: String(session.terminal_id),
            warehouseId: session.terminal?.warehouse_id || null,
            staffId: String(session.user_id),
            openedAt: session.opened_at,
          });
        } else {
          set({
            activeSessionId: null,
            activeTerminalId: null,
            warehouseId: null,
            staffId: null,
            openedAt: null,
          });
        }
      },

      setWarehouse: (warehouseId: string | null) => set({ warehouseId }),

      startSession: ({ sessionId, terminalId, warehouseId, staffId }) => {
        set({
          activeSessionId: sessionId,
          activeTerminalId: terminalId,
          warehouseId,
          staffId,
          openedAt: new Date().toISOString(),
          cart: [],
          discount: { type: "fixed", value: 0 },
          customerId: "walk-in",
        });
      },

      clearSession: () => {
        set({
          activeSessionId: null,
          activeTerminalId: null,
          warehouseId: null,
          staffId: null,
          openedAt: null,
          cart: [],
          discount: { type: "fixed", value: 0 },
          customerId: "walk-in",
        });
      },

      // Cart Actions
      setCart: (cart) => set({ cart }),

      addToCart: (newItem) =>
        set((state) => {
          const existing = state.cart.find((item) => item.id === newItem.id);
          if (existing) {
            const nextCart = state.cart.map((i) =>
              i.id === newItem.id ? newItem : i
            );
            return { cart: nextCart };
          }
          return { cart: [...state.cart, newItem] };
        }),

      removeFromCart: (itemId) =>
        set((state) => ({
          cart: state.cart.filter((i) => i.id !== itemId),
        })),

      updateQuantity: (itemId, quantity) =>
        set((state) => ({
          cart: state.cart.map((item) =>
            item.id === itemId
              ? { ...item, quantity }
              : item
          ),
        })),

      clearCart: () =>
        set({
          cart: [],
          discount: { type: "fixed", value: 0 },
          customerId: "walk-in",
        }),

      setDiscount: (discount) => set({ discount }),

      setCustomerId: (customerId) => set({ customerId }),
    }),
    {
      name: "pos-session-storage-v2",
      // Only persist session and cart data, NOT terminal list
      partialize: (state) => ({
        activeSessionId: state.activeSessionId,
        activeTerminalId: state.activeTerminalId,
        warehouseId: state.warehouseId,
        staffId: state.staffId,
        openedAt: state.openedAt,
        cart: state.cart,
        discount: state.discount,
        customerId: state.customerId,
      }),
    }
  )
);
