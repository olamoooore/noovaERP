interface Props {
  title: string
  description?: string
}

export function ComingSoon({ title, description }: Props) {
  return (
    <div className="flex h-64 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 text-center">
      <p className="text-lg font-semibold text-gray-700">{title}</p>
      <p className="text-sm text-gray-400">
        {description ?? 'Coming in a future phase.'}
      </p>
    </div>
  )
}
