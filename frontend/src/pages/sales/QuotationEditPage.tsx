import React from 'react'
import { useParams } from 'react-router-dom'
import { QuotationDocumentForm } from '@/components/sales/QuotationDocumentForm'

/**
 * Issue #314: thin wrapper — the form itself is shared with the create page
 * (`QuotationDocumentForm`), so every behaviour fix lands once.
 */
export const QuotationEditPage: React.FC = () => {
  const { id } = useParams()
  return <QuotationDocumentForm mode="edit" documentId={id} />
}

export default QuotationEditPage
