import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { generateFullHtml } from '@/components/website-builder/utils/renderStyles'
import './PublicSiteViewer.css'

interface PageContent {
  id: number
  title: string
  slug: string
  is_home: boolean
  seo_title?: string
  seo_description?: string
  content: {
    html?: string
    css?: string
    components?: any
    styles?: any
    header_js?: string
    body_js?: string
  }
  website: {
    id: number
    name: string
    slug: string
    settings?: any
  }
}

interface ApiError {
  response?: {
    status: number
    data?: {
      error?: string
      message?: string
      debug?: {
        searched_slug?: string
        available_websites?: string[]
        website_slug?: string
        searched_page_slug?: string
        available_pages?: string[]
      }
    }
  }
}

export function PublicSiteViewer() {
  const { websiteSlug, pageSlug } = useParams<{ websiteSlug: string; pageSlug?: string }>()
  const [isLoading, setIsLoading] = useState(true)

  // Fetch page content
  const { data: pageData, isLoading: isPageLoading, error } = useQuery<PageContent, ApiError>({
    queryKey: ['public-page', websiteSlug, pageSlug],
    queryFn: async () => {
      const url = pageSlug
        ? `/website-public/sites/${websiteSlug}/pages/${pageSlug}`
        : `/website-public/sites/${websiteSlug}`
      console.log('Fetching page from:', url)
      const response = await api.get(url)
      console.log('Page data received:', response.data)
      return response.data
    },
    enabled: !!websiteSlug,
    retry: 1,
  })

  useEffect(() => {
    if (!isPageLoading) {
      setIsLoading(false)
    }
  }, [isPageLoading])

  useEffect(() => {
    if (pageData?.content) {
      // Update document title
      document.title = pageData.seo_title || pageData.title || pageData.website.name

      // Update meta description
      const metaDescription = document.querySelector('meta[name="description"]')
      if (metaDescription && pageData.seo_description) {
        metaDescription.setAttribute('content', pageData.seo_description)
      }

      // Inject header JS
      if (pageData.content.header_js) {
        const script = document.createElement('script')
        script.textContent = pageData.content.header_js
        document.head.appendChild(script)
        return () => {
          document.head.removeChild(script)
        }
      }
    }
  }, [pageData])

  useEffect(() => {
    if (pageData?.content?.body_js) {
      // Inject body JS
      const script = document.createElement('script')
      script.textContent = pageData.content.body_js
      document.body.appendChild(script)
      return () => {
        document.body.removeChild(script)
      }
    }
  }, [pageData?.content?.body_js])

  if (isLoading || isPageLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-white">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-zinc-400" />
      </div>
    )
  }

  if (error) {
    const apiError = error as ApiError
    const errorMessage = apiError?.response?.data?.message || 'The page you\'re looking for doesn\'t exist or hasn\'t been published yet.'
    const debugInfo = apiError?.response?.data?.debug
    console.error('Page load error:', error)
    console.error('API URL attempted:', pageSlug 
      ? `/website-public/sites/${websiteSlug}/pages/${pageSlug}`
      : `/website-public/sites/${websiteSlug}`)
    if (debugInfo) {
      console.error('Debug info:', debugInfo)
    }
    
    return (
      <div className="flex flex-col items-center justify-center min-h-screen bg-white">
        <Icon icon="solar:danger-circle-bold" className="h-16 w-16 text-red-500 mb-4" />
        <h1 className="text-2xl font-bold mb-2">Page Not Found</h1>
        <p className="text-gray-500 text-center max-w-md">
          {errorMessage}
        </p>
        <p className="text-xs text-gray-400 mt-4">
          URL: /sites/{websiteSlug}{pageSlug ? `/${pageSlug}` : ''}
        </p>
        {debugInfo && (
          <div className="mt-4 p-4 bg-gray-100 rounded text-xs text-gray-600 max-w-md">
            <p className="font-semibold mb-2">Debug Info:</p>
            {debugInfo.available_websites && (
              <p>Available websites: {debugInfo.available_websites.join(', ') || 'none'}</p>
            )}
            {debugInfo.available_pages && (
              <p>Available pages: {debugInfo.available_pages.join(', ') || 'none'}</p>
            )}
          </div>
        )}
      </div>
    )
  }

  if (!pageData) {
    return null
  }

  // Get shop settings from website settings
  const shopSettings = pageData.website.settings?.shop

  // Generate the full HTML document using the shared utility
  const fullHtml = generateFullHtml({
    title: pageData.seo_title || pageData.title || pageData.website.name,
    description: pageData.seo_description || '',
    html: pageData.content.html || '',
    css: pageData.content.css || '',
    headerJs: pageData.content.header_js || '',
    bodyJs: pageData.content.body_js || '',
    apiBaseUrl: `${window.location.origin}/api/v1/website-public`,
    shopSettings: shopSettings?.enabled ? shopSettings : undefined,
  })

  return (
    <iframe
      srcDoc={fullHtml}
      className="public-site-iframe"
      title={pageData.title}
      sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-top-navigation-by-user-activation"
    />
  )
}
