import { useParams } from 'react-router-dom'
import { EditorShell } from '@/components/template-builder/EditorShell'

export default function TemplateEditorPage() {
  const { id } = useParams<{ id: string }>()
  if (!id) return null
  return <EditorShell templateId={id} />
}
