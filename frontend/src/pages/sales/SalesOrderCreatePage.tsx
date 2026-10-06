import React from 'react'
import { SalesOrderDocumentForm } from '@/components/sales/SalesOrderDocumentForm'

/**
 * Issue #374: thin wrapper — create and edit share SalesOrderDocumentForm.
 */
export const SalesOrderCreatePage: React.FC = () => <SalesOrderDocumentForm mode="create" />

export default SalesOrderCreatePage
