import React from 'react'
import { useParams } from 'react-router-dom'
import { SalesOrderDocumentForm } from '@/components/sales/SalesOrderDocumentForm'

/**
 * Issue #374: thin wrapper — create and edit share SalesOrderDocumentForm.
 */
export const SalesOrderEditPage: React.FC = () => {
  const { id } = useParams()
  return <SalesOrderDocumentForm mode="edit" documentId={id} />
}

export default SalesOrderEditPage
