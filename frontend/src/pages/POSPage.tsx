import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'

interface Product {
  id: number
  name: string
  price: number
  sku: string
  stock: number
  image_url?: string
}

interface CartItem extends Product {
  quantity: number
}

export function POSPage() {
  const { toast } = useToast()
  const [search, setSearch] = useState('')
  const [cart, setCart] = useState<CartItem[]>([])

  const { data: products, isLoading } = useQuery<Product[]>({
    queryKey: ['products', search],
    queryFn: async () => {
      const response = await api.get('/products', { params: { search } })
      // Handle both paginated and non-paginated responses
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const addToCart = (product: Product) => {
    setCart(prev => {
      const existing = prev.find(item => item.id === product.id)
      if (existing) {
        return prev.map(item => 
          item.id === product.id 
            ? { ...item, quantity: item.quantity + 1 } 
            : item
        )
      }
      return [...prev, { ...product, quantity: 1 }]
    })
  }

  const updateQuantity = (productId: number, delta: number) => {
    setCart(prev => prev.map(item => {
      if (item.id === productId) {
        const newQty = item.quantity + delta
        return newQty > 0 ? { ...item, quantity: newQty } : item
      }
      return item
    }))
  }

  const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0)
  const tax = subtotal * 0.1 // 10% tax example
  const total = subtotal + tax

  const handleCheckout = () => {
    toast({
      title: "Checkout",
      description: `Processing payment for ${formatCurrency(total)}`,
    })
    // Implement checkout logic here
  }

  return (
    <div className="flex h-[calc(100vh-4rem)] gap-4 p-4">
      {/* Left: Product List */}
      <div className="flex flex-1 flex-col gap-4">
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Icon icon="solar:magnifer-linear" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <Input 
              placeholder="Search products..." 
              className="pl-9" 
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Button variant="outline">
            <Icon icon="solar:filter-bold-duotone" className="mr-2 h-4 w-4" />
            Filter
          </Button>
        </div>

        <ScrollArea className="flex-1 rounded-md border bg-card p-4">
          {isLoading ? (
            <div>Loading products...</div>
          ) : (
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
              {products?.map((product) => (
                <Card 
                  key={product.id} 
                  className="cursor-pointer transition-colors hover:bg-accent"
                  onClick={() => addToCart(product)}
                >
                  <CardContent className="p-4">
                    <div className="aspect-square mb-2 rounded-md bg-muted flex items-center justify-center">
                      {product.image_url ? (
                        <img src={product.image_url} alt={product.name} className="object-cover" />
                      ) : (
                        <Icon icon="solar:box-bold-duotone" className="h-12 w-12 text-muted-foreground" />
                      )}
                    </div>
                    <h3 className="font-medium truncate">{product.name}</h3>
                    <div className="flex items-center justify-between mt-1">
                      <span className="font-bold">{formatCurrency(product.price)}</span>
                      <Badge variant="secondary" className="text-xs">{product.stock} in stock</Badge>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </ScrollArea>
      </div>

      {/* Right: Cart */}
      <div className="flex w-96 flex-col rounded-md border bg-card">
        <div className="p-4 border-b">
          <h2 className="font-semibold flex items-center gap-2">
            <Icon icon="solar:cart-large-minimalistic-bold-duotone" />
            Current Order
          </h2>
        </div>

        <ScrollArea className="flex-1 p-4">
          <div className="space-y-4">
            {cart.map((item) => (
              <div key={item.id} className="flex gap-3">
                <div className="h-12 w-12 rounded bg-muted flex items-center justify-center shrink-0">
                   <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                </div>
                <div className="flex-1 overflow-hidden">
                  <h4 className="font-medium truncate">{item.name}</h4>
                  <p className="text-sm text-muted-foreground">{formatCurrency(item.price)} x {item.quantity}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className="font-bold">{formatCurrency(item.price * item.quantity)}</span>
                  <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon" className="h-6 w-6" onClick={(e) => { e.stopPropagation(); updateQuantity(item.id, -1); }}>-</Button>
                    <span className="text-xs w-4 text-center">{item.quantity}</span>
                    <Button variant="ghost" size="icon" className="h-6 w-6" onClick={(e) => { e.stopPropagation(); updateQuantity(item.id, 1); }}>+</Button>
                  </div>
                </div>
              </div>
            ))}
            {cart.length === 0 && (
              <div className="text-center text-muted-foreground py-8">
                Cart is empty
              </div>
            )}
          </div>
        </ScrollArea>

        <div className="p-4 border-t bg-muted/20 space-y-4">
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Subtotal</span>
              <span>{formatCurrency(subtotal)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Tax (10%)</span>
              <span>{formatCurrency(tax)}</span>
            </div>
            <Separator />
            <div className="flex justify-between font-bold text-lg">
              <span>Total</span>
              <span>{formatCurrency(total)}</span>
            </div>
          </div>

          <Button className="w-full" size="lg" onClick={handleCheckout} disabled={cart.length === 0}>
            Checkout
          </Button>
        </div>
      </div>
    </div>
  )
}
