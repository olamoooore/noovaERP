import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export interface PosTerminalProfile {
  id: string
  name: string
  warehouseId?: string
}

interface PosSessionState {
  terminals: PosTerminalProfile[]
  activeTerminalId: string | null
  warehouseId: string | null
  staffId: string | null
  openedAt: string | null

  addTerminal: (name: string, warehouseId?: string) => PosTerminalProfile
  removeTerminal: (terminalId: string) => void
  setActiveTerminal: (terminalId: string | null) => void
  setWarehouse: (warehouseId: string | null) => void
  startSession: (input: { terminalId: string; warehouseId: string | null; staffId: string | null }) => void
  clearSession: () => void
}

const makeId = () => `term_${Math.random().toString(36).slice(2, 10)}_${Date.now()}`

export const usePosSessionStore = create<PosSessionState>()(
  persist(
    (set, get) => ({
      terminals: [],
      activeTerminalId: null,
      warehouseId: null,
      staffId: null,
      openedAt: null,

      addTerminal: (name: string, warehouseId?: string) => {
        const trimmed = name.trim()
        const terminal: PosTerminalProfile = {
          id: makeId(),
          name: trimmed || 'Terminal',
          warehouseId,
        }

        set((state) => ({
          terminals: [terminal, ...state.terminals],
        }))

        return terminal
      },

      removeTerminal: (terminalId: string) => {
        set((state) => {
          const nextTerminals = state.terminals.filter((t) => t.id !== terminalId)
          const activeTerminalId = state.activeTerminalId === terminalId ? null : state.activeTerminalId

          return {
            terminals: nextTerminals,
            activeTerminalId,
          }
        })
      },

      setActiveTerminal: (terminalId: string | null) => set({ activeTerminalId: terminalId }),

      setWarehouse: (warehouseId: string | null) => set({ warehouseId }),

      startSession: ({ terminalId, warehouseId, staffId }) => {
        set({
          activeTerminalId: terminalId,
          warehouseId,
          staffId,
          openedAt: new Date().toISOString(),
        })
      },

      clearSession: () => {
        set({
          activeTerminalId: null,
          warehouseId: null,
          staffId: null,
          openedAt: null,
        })
      },
    }),
    {
      name: 'pos-session-storage',
    }
  )
)
