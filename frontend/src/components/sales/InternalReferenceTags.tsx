import React, { useState } from 'react'
import { Icon } from '@iconify/react'

interface InternalReferenceTagsProps {
  value: string
  onChange: (value: string) => void
}

const parseTags = (value: string) => value
  .split(',')
  .map((tag) => tag.trim())
  .filter(Boolean)

export function InternalReferenceTags({ value, onChange }: InternalReferenceTagsProps) {
  const [draft, setDraft] = useState('')
  const tags = parseTags(value)

  const addTag = (rawValue: string) => {
    const additions = parseTags(rawValue)
    if (!additions.length) return

    const next = [...tags]
    additions.forEach((tag) => {
      if (!next.some((existing) => existing.toLowerCase() === tag.toLowerCase())) next.push(tag)
    })
    onChange(next.join(', '))
    setDraft('')
  }

  const removeTag = (index: number) => {
    onChange(tags.filter((_, tagIndex) => tagIndex !== index).join(', '))
  }

  return (
    <div className="flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm focus-within:ring-2 focus-within:ring-primary/30 dark:border-gray-600 dark:bg-gray-800">
      {tags.map((tag, index) => (
        <span key={`${tag}-${index}`} className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-1 text-primary">
          {tag}
          <button type="button" onClick={() => removeTag(index)} aria-label={`Remove ${tag}`} className="rounded-full hover:bg-primary/15">
            <Icon icon="mdi:close" className="h-3.5 w-3.5" />
          </button>
        </span>
      ))}
      <input
        type="text"
        value={draft}
        onChange={(event) => {
          const next = event.target.value
          if (next.includes(',')) addTag(next)
          else setDraft(next)
        }}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault()
            addTag(draft)
          } else if (event.key === 'Backspace' && !draft && tags.length) {
            removeTag(tags.length - 1)
          }
        }}
        onBlur={() => addTag(draft)}
        className="min-w-40 flex-1 border-0 bg-transparent px-1 py-1 outline-none"
        placeholder={tags.length ? 'Add another reference' : 'Project no. or field service no.'}
      />
    </div>
  )
}
