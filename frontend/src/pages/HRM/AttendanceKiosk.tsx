import { useState, useEffect, useRef, useCallback } from 'react'

// Kiosk uses the agent token so it works without a logged-in user session
const AGENT_ID    = 'agent-676da24f-97d3-4d74-bcd2-c235a30dbac7'
const AGENT_TOKEN = 'ScyZURdVnYFINQ9VFZFXvYbE9iriJ0GZK4i2FNbYVTEmIj5PcTSBE70ycVIwohQ8'
const API_BASE    = '/api/v1'

// ─── Types ────────────────────────────────────────────────────────────────────

interface KioskResult {
  success: boolean
  message: string
  employee_name?: string
  event_type?: 'check_in' | 'check_out'
  timestamp?: string
}

// ─── Attendance Kiosk Page ─────────────────────────────────────────────────────
// Designed for POS machine use. The built-in barcode scanner types the badge code
// as keyboard input — this page captures it in a hidden input, then submits.

export default function AttendanceKiosk() {
  const [result, setResult] = useState<KioskResult | null>(null)
  const [scanning, setScanning] = useState(false)
  const [currentTime, setCurrentTime] = useState(new Date())
  const [lastCode, setLastCode] = useState<string>('')
  const inputRef = useRef<HTMLInputElement>(null)
  const bufferRef = useRef('')
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const resultTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Update clock every second
  useEffect(() => {
    const interval = setInterval(() => setCurrentTime(new Date()), 1000)
    return () => clearInterval(interval)
  }, [])

  // Keep the hidden input focused at all times so scanner input is captured
  useEffect(() => {
    const refocus = () => {
      if (inputRef.current && document.activeElement !== inputRef.current) {
        inputRef.current.focus()
      }
    }
    document.addEventListener('click', refocus)
    document.addEventListener('keydown', refocus)
    refocus()
    return () => {
      document.removeEventListener('click', refocus)
      document.removeEventListener('keydown', refocus)
    }
  }, [])

  const submitScan = useCallback(async (barcode: string) => {
    const code = barcode.trim()
    if (!code) return

    setLastCode(code)   // show on screen for debug
    setScanning(true)
    setResult(null)

    try {
      const response = await fetch(
        `${API_BASE}/iot/agents/${AGENT_ID}/scanner/scan-result`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${AGENT_TOKEN}`,
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            barcode:    code,
            device_id:  null,
            device_name: 'POS Built-in Scanner',
            timestamp:  new Date().toISOString(),
          }),
        }
      )
      const res = { data: await response.json() }

      const attendance = res.data?.data?.attendance
      if (attendance?.success) {
        setResult({
          success: true,
          message: attendance.message,
          employee_name: attendance.employee?.name,
          event_type: attendance.event_type,
          timestamp: new Date().toLocaleTimeString(),
        })
      } else {
        setResult({
          success: false,
          message: attendance?.message ?? 'Badge not recognised. Please register first.',
        })
      }
    } catch {
      setResult({
        success: false,
        message: 'Connection error. Please try again.',
      })
    } finally {
      setScanning(false)
      // Auto-clear result after 4 seconds
      if (resultTimerRef.current) clearTimeout(resultTimerRef.current)
      resultTimerRef.current = setTimeout(() => setResult(null), 4000)
    }
  }, [])

  // Capture barcode from keyboard-emulation scanner.
  // Scanners type fast and finish with Enter. We buffer keystrokes and
  // submit either on Enter or after 100ms of silence.
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        if (timerRef.current) clearTimeout(timerRef.current)
        const code = bufferRef.current
        bufferRef.current = ''
        if (inputRef.current) inputRef.current.value = ''
        submitScan(code)
        return
      }

      // Accumulate characters
      if (e.key.length === 1) {
        bufferRef.current += e.key

        // Auto-submit after 100ms silence (handles scanners that don't send Enter)
        if (timerRef.current) clearTimeout(timerRef.current)
        timerRef.current = setTimeout(() => {
          const code = bufferRef.current
          bufferRef.current = ''
          if (inputRef.current) inputRef.current.value = ''
          submitScan(code)
        }, 200)
      }
    },
    [submitScan]
  )

  const isCheckIn = result?.event_type === 'check_in'

  return (
    <div
      style={{
        minHeight: '100vh',
        background: '#0f172a',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: 'system-ui, sans-serif',
        color: 'white',
        userSelect: 'none',
      }}
      onClick={() => inputRef.current?.focus()}
    >
      {/* Hidden input captures scanner keyboard emulation */}
      <input
        ref={inputRef}
        onKeyDown={handleKeyDown}
        style={{
          position: 'absolute',
          opacity: 0,
          width: 1,
          height: 1,
          pointerEvents: 'none',
        }}
        readOnly={false}
        autoFocus
      />

      {/* Clock */}
      <div style={{ fontSize: 56, fontWeight: 700, letterSpacing: 4, marginBottom: 8 }}>
        {currentTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
      </div>
      <div style={{ fontSize: 18, color: '#94a3b8', marginBottom: 64 }}>
        {currentTime.toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
      </div>

      {/* Scan prompt / result */}
      {!result && !scanning && (
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 80, marginBottom: 24 }}>📛</div>
          <div style={{ fontSize: 28, fontWeight: 600, color: '#e2e8f0' }}>
            Scan your badge to check in/out
          </div>
          <div style={{ fontSize: 16, color: '#64748b', marginTop: 12 }}>
            Hold badge in front of the scanner
          </div>
        </div>
      )}

      {scanning && (
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: 80, marginBottom: 24 }}>⏳</div>
          <div style={{ fontSize: 28, color: '#94a3b8' }}>Processing…</div>
        </div>
      )}

      {result && (
        <div
          style={{
            background: result.success
              ? isCheckIn ? '#166534' : '#1e3a5f'
              : '#7f1d1d',
            borderRadius: 24,
            padding: '48px 64px',
            textAlign: 'center',
            minWidth: 380,
            boxShadow: '0 0 60px rgba(0,0,0,0.5)',
          }}
        >
          <div style={{ fontSize: 72, marginBottom: 16 }}>
            {result.success ? (isCheckIn ? '✅' : '👋') : '❌'}
          </div>

          {result.success ? (
            <>
              <div style={{ fontSize: 36, fontWeight: 700, marginBottom: 8 }}>
                {result.employee_name}
              </div>
              <div
                style={{
                  fontSize: 22,
                  fontWeight: 600,
                  color: isCheckIn ? '#86efac' : '#93c5fd',
                  marginBottom: 8,
                }}
              >
                {isCheckIn ? 'Checked In' : 'Checked Out'}
              </div>
              <div style={{ fontSize: 16, color: '#94a3b8' }}>{result.timestamp}</div>
            </>
          ) : (
            <div style={{ fontSize: 22, color: '#fca5a5' }}>{result.message}</div>
          )}
        </div>
      )}

      {/* Debug: show last scanned code */}
      {lastCode && (
        <div style={{ position: 'absolute', bottom: 50, color: '#475569', fontSize: 13 }}>
          Last scan: <code style={{ color: '#94a3b8' }}>{lastCode}</code> ({lastCode.length} chars)
        </div>
      )}

      {/* Footer */}
      <div style={{ position: 'absolute', bottom: 24, color: '#334155', fontSize: 13 }}>
        NoovaERP — Attendance Kiosk
      </div>
    </div>
  )
}
