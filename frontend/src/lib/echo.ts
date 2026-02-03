// WebSocket support temporarily disabled until Laravel Reverb is running
// To enable: npm install laravel-echo pusher-js

// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare global {
  interface Window {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    Pusher: any
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    Echo: any
  }
}

// WebSocket is disabled - no Echo instance
export const echo = null

// Make Echo available globally for debugging (null when disabled)
if (typeof window !== 'undefined') {
  window.Echo = echo
}

export default echo
