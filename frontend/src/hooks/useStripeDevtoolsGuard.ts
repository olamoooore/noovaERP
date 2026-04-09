import { useEffect } from 'react'

const STRIPE_DEVTOOLS_SELECTOR = [
  '[aria-label="Open Stripe Developer Tools"]',
  '[class*="__Easel"]',
  '[class*="__FloatingActionButton"]',
  '[class*="Easel-contentWrapper"]',
].join(', ')

function removeStripeDevtoolsOverlay(root: ParentNode = document) {
  const elements = root.querySelectorAll<HTMLElement>(STRIPE_DEVTOOLS_SELECTOR)

  elements.forEach((element) => {
    const overlay = element.closest<HTMLElement>('[class*="__Easel"]')
    const target = overlay || element
    target.remove()
  })
}

export function useStripeDevtoolsGuard() {
  useEffect(() => {
    if (typeof document === 'undefined') {
      return
    }

    if (!document.body) {
      return
    }

    removeStripeDevtoolsOverlay()

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof HTMLElement)) {
            return
          }

          if (node.matches(STRIPE_DEVTOOLS_SELECTOR)) {
            removeStripeDevtoolsOverlay(node.parentNode || document)
            return
          }

          if (node.querySelector(STRIPE_DEVTOOLS_SELECTOR)) {
            removeStripeDevtoolsOverlay(node)
          }
        })
      })
    })

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    })

    return () => observer.disconnect()
  }, [])
}