import { Fragment, type ReactNode } from 'react'
import { Link } from 'react-router-dom'

/**
 * Minimal markdown renderer for chat replies: **bold**, *italic*, `code`,
 * bullet/numbered lists, #-headings and [links](/path). Deliberately
 * dependency-free and HTML-free — models sometimes emit raw markdown, and
 * this shows it as formatted text instead of asterisks. Internal links
 * (paths starting with /) navigate in-app via the router.
 */

function renderInline(text: string, keyPrefix: string): ReactNode[] {
  const parts = text.split(/(\[[^\]]+\]\([^)\s]+\)|\*\*[^*\n]+\*\*|`[^`\n]+`|\*[^*\n]+\*)/g)

  return parts.filter(Boolean).map((part, index) => {
    const key = `${keyPrefix}-${index}`

    const link = part.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/)
    if (link) {
      const [, label, href] = link
      if (href.startsWith('/')) {
        return (
          <Link key={key} to={href} className="font-medium underline underline-offset-2 hover:opacity-80">
            {label}
          </Link>
        )
      }

      return (
        <a
          key={key}
          href={href}
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium underline underline-offset-2 hover:opacity-80"
        >
          {label}
        </a>
      )
    }

    if (part.length > 4 && part.startsWith('**') && part.endsWith('**')) {
      return (
        <strong key={key} className="font-semibold">
          {part.slice(2, -2)}
        </strong>
      )
    }

    if (part.length > 2 && part.startsWith('`') && part.endsWith('`')) {
      return (
        <code key={key} className="rounded bg-black/10 px-1 py-0.5 text-[0.85em] dark:bg-white/15">
          {part.slice(1, -1)}
        </code>
      )
    }

    if (part.length > 2 && part.startsWith('*') && part.endsWith('*')) {
      return <em key={key}>{part.slice(1, -1)}</em>
    }

    return <Fragment key={key}>{part}</Fragment>
  })
}

const BULLET = /^\s*[-•*]\s+(.*)$/
const ORDERED = /^\s*\d+[.)]\s+(.*)$/
const HEADING = /^\s*#{1,4}\s+(.*)$/

type ListState = { ordered: boolean; items: string[] } | null

export function MessageBody({ content, className = '' }: { content: string; className?: string }) {
  const lines = content.replace(/\r\n/g, '\n').split('\n')
  const blocks: ReactNode[] = []
  let list: ListState = null

  const flush = () => {
    if (!list) return

    const items = list.items.map((item, index) => (
      <li key={index}>{renderInline(item, `li-${blocks.length}-${index}`)}</li>
    ))

    blocks.push(
      list.ordered ? (
        <ol key={`ol-${blocks.length}`} className="ml-4 list-decimal space-y-0.5">
          {items}
        </ol>
      ) : (
        <ul key={`ul-${blocks.length}`} className="ml-4 list-disc space-y-0.5">
          {items}
        </ul>
      ),
    )

    list = null
  }

  lines.forEach((rawLine, lineIndex) => {
    const line = rawLine.trimEnd()

    const bullet = line.match(BULLET)
    if (bullet) {
      if (!list) {
        list = { ordered: false, items: [] }
      } else if (list.ordered) {
        flush()
        list = { ordered: false, items: [] }
      }
      list.items.push(bullet[1])
      return
    }

    const ordered = line.match(ORDERED)
    if (ordered) {
      if (!list) {
        list = { ordered: true, items: [] }
      } else if (!list.ordered) {
        flush()
        list = { ordered: true, items: [] }
      }
      list.items.push(ordered[1])
      return
    }

    flush()

    if (line.trim() === '') return

    const heading = line.match(HEADING)
    if (heading) {
      blocks.push(
        <p key={`heading-${lineIndex}`} className="pt-1 font-semibold">
          {renderInline(heading[1], `heading-${lineIndex}`)}
        </p>,
      )
      return
    }

    blocks.push(<p key={`line-${lineIndex}`}>{renderInline(line, `line-${lineIndex}`)}</p>)
  })

  flush()

  return <div className={`space-y-1 leading-relaxed ${className}`.trim()}>{blocks}</div>
}
