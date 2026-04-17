import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'

interface Feature {
  id: string
  name: string
  key: string
  description: string
  is_core: boolean
  enabled: boolean
}

export function ModulesPage() {
  const { data: features, isLoading } = useQuery<Feature[]>({
    queryKey: ['features'],
    queryFn: async () => {
      const response = await api.get('/features')
      return response.data
    },
  })

  if (isLoading) {
    return <div className="p-6">Loading modules...</div>
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Modules & Add-ons</h1>
          <p className="text-muted-foreground">View the modules currently entitled for your organization.</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {features?.map((feature) => (
          <Card key={feature.id} className={feature.enabled ? 'border-primary/50 bg-primary/5' : ''}>
            <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
              <div className="space-y-1">
                <CardTitle className="text-base font-medium">
                  {feature.name}
                </CardTitle>
                <CardDescription className="text-xs">
                  {feature.key}
                </CardDescription>
              </div>
              <Badge variant={feature.is_core ? 'default' : (feature.enabled ? 'success' : 'secondary')}>
                {feature.is_core ? 'Core' : (feature.enabled ? 'Active' : 'Inactive')}
              </Badge>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground mb-4">
                {feature.description || 'No description available.'}
              </p>
              
              {!feature.is_core && (
                <Button 
                  variant="outline"
                  size="sm" 
                  className="w-full"
                  disabled
                >
                  Contact Admin
                </Button>
              )}
              
              {feature.is_core && (
                <Button variant="ghost" size="sm" className="w-full cursor-not-allowed opacity-50" disabled>
                  Core Module
                </Button>
              )}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
