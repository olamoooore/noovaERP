import { create } from 'zustand'

interface InventoryFilters {
  search: string
  warehouseId: string
  status: string
  fromDate: string
  toDate: string
}

interface InventoryState {
  filters: InventoryFilters
  setFilter: (key: keyof InventoryFilters, value: string) => void
  resetFilters: () => void
  selectedWarehouseId: number | null
  setSelectedWarehouse: (id: number | null) => void
}

const defaultFilters: InventoryFilters = {
  search: '',
  warehouseId: 'all',
  status: 'all',
  fromDate: '',
  toDate: '',
}

export const useInventoryStore = create<InventoryState>((set) => ({
  filters: { ...defaultFilters },
  setFilter: (key, value) =>
    set((state) => ({
      filters: { ...state.filters, [key]: value },
    })),
  resetFilters: () => set({ filters: { ...defaultFilters } }),
  selectedWarehouseId: null,
  setSelectedWarehouse: (id) => set({ selectedWarehouseId: id }),
}))
