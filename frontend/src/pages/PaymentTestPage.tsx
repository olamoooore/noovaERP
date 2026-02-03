import { useState, useEffect } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import api from '@/lib/api'

interface Invoice {
  id: number
  invoice_number: string
  customer: { name: string }
  total: number
  balance: number
  status: string
}

export function PaymentTestPage() {
  const navigate = useNavigate()
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const loadInvoices = async () => {
      try {
        const response = await api.get('/invoices?status=unpaid')
        setInvoices(response.data.data || response.data)
      } catch (error) {
        console.error('Failed to load invoices', error)
      } finally {
        setLoading(false)
      }
    }

    loadInvoices()
  }, [])

  const handlePayInvoice = (invoice: Invoice) => {
    navigate(`/checkout?invoice=${invoice.id}&amount=${invoice.balance}`)
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Icon icon="eos-icons:loading" className="h-8 w-8" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-bold tracking-tight">Test Payment</h2>
          <p className="text-muted-foreground">
            Select an invoice to test the payment flow
          </p>
        </div>
      </div>

      <div className="grid gap-4">
        {invoices.length === 0 ? (
          <Card>
            <CardContent className="pt-6">
              <div className="text-center py-12">
                <Icon icon="material-symbols:receipt-long-outline" className="h-16 w-16 mx-auto mb-4 text-muted-foreground" />
                <p className="text-muted-foreground">No unpaid invoices found</p>
                <Link to="/invoices" className="text-sm text-primary hover:underline mt-2 inline-block">
                  View all invoices
                </Link>
              </div>
            </CardContent>
          </Card>
        ) : (
          invoices.map((invoice) => (
            <Card key={invoice.id} className="hover:shadow-md transition-shadow">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="text-lg">
                    {invoice.invoice_number}
                  </CardTitle>
                  <Badge variant={invoice.status === 'paid' ? 'default' : 'secondary'}>
                    {invoice.status}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-4">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Customer</span>
                    <span className="font-medium">{invoice.customer.name}</span>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Total</span>
                    <span className="font-medium">${Number(invoice.total).toFixed(2)}</span>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Balance Due</span>
                    <span className="font-bold text-lg">${Number(invoice.balance).toFixed(2)}</span>
                  </div>
                  <Button 
                    onClick={() => handlePayInvoice(invoice)} 
                    className="w-full"
                    disabled={Number(invoice.balance) <= 0}
                  >
                    <Icon icon="material-symbols:payment" className="mr-2 h-4 w-4" />
                    Pay ${Number(invoice.balance).toFixed(2)}
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))
        )}
      </div>
    </div>
  )
}
