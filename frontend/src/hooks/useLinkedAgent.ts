import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import iotApi from '@/lib/iotApi'
import { getLinkedAgentId } from '@/services/printService'

export function useLinkedAgent() {
  const storedAgentId = getLinkedAgentId()

  const query = useQuery({
    queryKey: ['linked-agent'],
    queryFn: async () => {
      const response = await iotApi.getLinkedAgent()
      return response.success ? response.data?.agent_id ?? null : null
    },
    retry: false,
    staleTime: 60000,
    initialData: storedAgentId ?? undefined,
  })

  useEffect(() => {
    if (!query.isSuccess || typeof window === 'undefined') {
      return
    }

    if (query.data) {
      localStorage.setItem('ump_agent_id', query.data)
      return
    }

    localStorage.removeItem('ump_agent_id')
  }, [query.data, query.isSuccess])

  return {
    agentId: query.data ?? null,
    isLoading: query.isLoading,
  }
}