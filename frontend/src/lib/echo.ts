import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Make Pusher available globally for Echo
declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo: Echo | null
  }
}

window.Pusher = Pusher

// Create Echo instance only if WebSocket is enabled
// Temporarily disabled until Laravel Reverb is running
const ENABLE_WEBSOCKET = false

export const echo = ENABLE_WEBSOCKET ? new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'aeeb5ff9220243738b89372eb69f13',
  wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
  wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
  wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
}) : null

// Make Echo available globally for debugging
window.Echo = echo

export default echo
