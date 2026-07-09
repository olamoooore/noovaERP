import type { BlockType } from '@/types/template-builder'

export const DND = {
  NEW_BLOCK: 'TB_NEW_BLOCK',
  BLOCK: 'TB_BLOCK',
} as const

export interface NewBlockDragItem {
  blockType: BlockType
}

export interface BlockDragItem {
  id: string
}
