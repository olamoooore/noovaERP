// WebSocket support temporarily disabled until Laravel Reverb is running
// To enable: npm install laravel-echo pusher-js

type EchoChannel = {
  listen: (event: string, callback: (...args: any[]) => void) => EchoChannel
}

export type EchoClient = {
  channel: (name: string) => EchoChannel
  leaveChannel: (name: string) => void
} | null

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
export const echo: EchoClient = null

// Make Echo available globally for debugging (null when disabled)
if (typeof window !== 'undefined') {
  window.Echo = echo
}

export default echo
