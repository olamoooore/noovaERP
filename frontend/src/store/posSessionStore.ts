import { create } from "zustand";
import { persist } from "zustand/middleware";

export interface PosTerminalProfile {
  id: string;
  name: string;
  warehouseId?: string;
}

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
  // Store original product data if needed for re-calculation
  product_data?: any;
}

export interface Discount {
  type: "fixed" | "percent";
  value: number;
}

interface PosSessionState {
  terminals: PosTerminalProfile[];
  activeTerminalId: string | null;
  warehouseId: string | null;
  staffId: string | null;
  openedAt: string | null;

  // Cart State
  cart: CartItem[];
  discount: Discount;
  customerId: string;

  // Actions
  addTerminal: (name: string, warehouseId?: string) => PosTerminalProfile;
  removeTerminal: (terminalId: string) => void;
  setActiveTerminal: (terminalId: string | null) => void;
  setWarehouse: (warehouseId: string | null) => void;
  startSession: (input: {
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

const makeId = () =>
  `term_${Math.random().toString(36).slice(2, 10)}_${Date.now()}`;

export const usePosSessionStore = create<PosSessionState>()(
  persist(
    (set, get) => ({
      terminals: [],
      activeTerminalId: null,
      warehouseId: null,
      staffId: null,
      openedAt: null,

      cart: [],
      discount: { type: "fixed", value: 0 },
      customerId: "walk-in",

      addTerminal: (name: string, warehouseId?: string) => {
        const trimmed = name.trim();
        const terminal: PosTerminalProfile = {
          id: makeId(),
          name: trimmed || "Terminal",
          warehouseId,
        };

        set((state) => ({
          terminals: [terminal, ...state.terminals],
        }));

        return terminal;
      },

      removeTerminal: (terminalId: string) => {
        set((state) => {
          const nextTerminals = state.terminals.filter(
            (t) => t.id !== terminalId
          );
          const activeTerminalId =
            state.activeTerminalId === terminalId
              ? null
              : state.activeTerminalId;

          return {
            terminals: nextTerminals,
            activeTerminalId,
          };
        });
      },

      setActiveTerminal: (terminalId: string | null) =>
        set({ activeTerminalId: terminalId }),

      setWarehouse: (warehouseId: string | null) => set({ warehouseId }),

      startSession: ({ terminalId, warehouseId, staffId }) => {
        set({
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
          // Logic to merge duplicates handled by caller or basic merge here
          const existing = state.cart.find((item) => item.id === newItem.id);
          if (existing) {
            // If caller fully calculated line totals, we might just replace it or update qty
            // But simpler to just replace if caller handles logic, or update qty if simple
            // For now, assume caller passes a completely calculated item to ADD.
            // But if it exists, we usually want to increment qty.
            // To keep store simple, let's assume the caller will use updateQuantity for edits,
            // and addToCart for new items. But if it exists, we replace it?
            // Best pattern: Caller calculates everything.
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
              ? { ...item, quantity } // Note: Caller must recalculate totals if they only call this.
              : // Warning: This implementation assumes caller will RE-CALCULATE totals and call setCart or we need to recalc here.
                // Let's stick to: "Store holds state, Components handle business logic" for now to minimize refactor risk,
                // OR update POSTerminal to use `setCart` for everything.
                item
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
      name: "pos-session-storage",
    }
  )
);
