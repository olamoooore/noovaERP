import React from 'react'
import { QuotationDocumentForm } from '@/components/sales/QuotationDocumentForm'

/**
 * Issue #314: thin wrapper — the form itself is shared with the edit page
 * (`QuotationDocumentForm`), so every behaviour fix lands once.
 */
export const QuotationCreatePage: React.FC = () => <QuotationDocumentForm mode="create" />

export default QuotationCreatePage
