/**
 * Enhanced Chart Component
 * Modern chart designs with design system colors and interactive features
 */

import {
  LineChart,
  Line,
  AreaChart,
  Area,
  BarChart,
  Bar,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts'
import { designTokens } from '@/lib/design-tokens'
import { ChartSkeleton } from './skeleton'

export interface EnhancedChartProps {
  type: 'line' | 'area' | 'bar' | 'pie'
  data: any[]
  theme?: 'light' | 'dark'
  interactive?: boolean
  loading?: boolean
  height?: number
  responsive?: boolean
  className?: string
}

// Design system colors for charts
const chartColors = {
  primary: designTokens.colors.primary['500'],
  secondary: designTokens.colors.secondary['500'],
  accent: designTokens.colors.accent['500'],
  success: designTokens.colors.semantic.success['500'],
  warning: designTokens.colors.semantic.warning['500'],
  error: designTokens.colors.semantic.error['500'],
  info: designTokens.colors.semantic.info['500'],
}

const chartColorPalette = [
  chartColors.primary,
  chartColors.secondary,
  chartColors.accent,
  chartColors.success,
  chartColors.warning,
  chartColors.error,
  chartColors.info,
]

// Custom tooltip component
const CustomTooltip = ({ active, payload, label }: any) => {
  if (active && payload && payload.length) {
    return (
      <div className="bg-white p-3 border border-gray-200 rounded-lg shadow-lg">
        <p className="text-sm font-medium text-gray-900">{`${label}`}</p>
        {payload.map((entry: any, index: number) => (
          <p key={index} className="text-sm" style={{ color: entry.color }}>
            {`${entry.name}: ${entry.value}`}
          </p>
        ))}
      </div>
    )
  }
  return null
}

// Custom legend component
const CustomLegend = ({ payload }: any) => {
  return (
    <div className="flex flex-wrap justify-center gap-4 mt-4">
      {payload.map((entry: any, index: number) => (
        <div key={index} className="flex items-center gap-2">
          <div
            className="w-3 h-3 rounded-full"
            style={{ backgroundColor: entry.color }}
          />
          <span className="text-sm text-gray-600">{entry.value}</span>
        </div>
      ))}
    </div>
  )
}

export function EnhancedChart({
  type,
  data,
  interactive = true,
  loading = false,
  height = 300,
  responsive = true,
  className = '',
}: EnhancedChartProps) {
  if (loading) {
    return (
      <ChartSkeleton 
        height={height} 
        type={type}
        className={className}
      />
    )
  }

  const commonProps = {
    data,
    margin: { top: 20, right: 30, left: 20, bottom: 20 },
  }

  const axisProps = {
    axisLine: { stroke: '#e5e7eb' },
    tickLine: { stroke: '#e5e7eb' },
    tick: { fill: '#6b7280', fontSize: 12 },
  }

  const gridProps = {
    strokeDasharray: '3 3',
    stroke: '#f3f4f6',
  }

  const renderChart = () => {
    switch (type) {
      case 'line':
        return (
          <LineChart {...commonProps}>
            <CartesianGrid {...gridProps} />
            <XAxis {...axisProps} />
            <YAxis {...axisProps} />
            {interactive && <Tooltip content={<CustomTooltip />} />}
            {interactive && <Legend content={<CustomLegend />} />}
            {Object.keys(data[0] || {})
              .filter(key => key !== 'name' && key !== 'label')
              .map((key, index) => (
                <Line
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stroke={chartColorPalette[index % chartColorPalette.length]}
                  strokeWidth={2}
                  dot={{ fill: chartColorPalette[index % chartColorPalette.length], strokeWidth: 2, r: 4 }}
                  activeDot={{ r: 6, stroke: chartColorPalette[index % chartColorPalette.length], strokeWidth: 2 }}
                />
              ))}
          </LineChart>
        )

      case 'area':
        return (
          <AreaChart {...commonProps}>
            <CartesianGrid {...gridProps} />
            <XAxis {...axisProps} />
            <YAxis {...axisProps} />
            {interactive && <Tooltip content={<CustomTooltip />} />}
            {interactive && <Legend content={<CustomLegend />} />}
            {Object.keys(data[0] || {})
              .filter(key => key !== 'name' && key !== 'label')
              .map((key, index) => (
                <Area
                  key={key}
                  type="monotone"
                  dataKey={key}
                  stackId="1"
                  stroke={chartColorPalette[index % chartColorPalette.length]}
                  fill={chartColorPalette[index % chartColorPalette.length]}
                  fillOpacity={0.6}
                />
              ))}
          </AreaChart>
        )

      case 'bar':
        return (
          <BarChart {...commonProps}>
            <CartesianGrid {...gridProps} />
            <XAxis {...axisProps} />
            <YAxis {...axisProps} />
            {interactive && <Tooltip content={<CustomTooltip />} />}
            {interactive && <Legend content={<CustomLegend />} />}
            {Object.keys(data[0] || {})
              .filter(key => key !== 'name' && key !== 'label')
              .map((key, index) => (
                <Bar
                  key={key}
                  dataKey={key}
                  fill={chartColorPalette[index % chartColorPalette.length]}
                  radius={[4, 4, 0, 0]}
                />
              ))}
          </BarChart>
        )

      case 'pie':
        return (
          <PieChart {...commonProps}>
            {interactive && <Tooltip content={<CustomTooltip />} />}
            <Pie
              data={data}
              cx="50%"
              cy="50%"
              labelLine={false}
              label={({ name, percent }) => `${name} ${((percent || 0) * 100).toFixed(0)}%`}
              outerRadius={80}
              fill="#8884d8"
              dataKey="value"
            >
              {data.map((_, index) => (
                <Cell key={`cell-${index}`} fill={chartColorPalette[index % chartColorPalette.length]} />
              ))}
            </Pie>
            {interactive && <Legend content={<CustomLegend />} />}
          </PieChart>
        )

      default:
        return <div>Unsupported chart type</div>
    }
  }

  if (responsive) {
    return (
      <div className={`w-full ${className}`}>
        <ResponsiveContainer width="100%" height={height}>
          {renderChart()}
        </ResponsiveContainer>
      </div>
    )
  }

  return (
    <div className={className} style={{ width: '100%', height }}>
      {renderChart()}
    </div>
  )
}

// Export functionality component
export function ChartExportButton({ 
  data, 
  filename = 'chart-data',
  className = '' 
}: { 
  data: any[]
  filename?: string
  className?: string 
}) {
  const exportToCSV = () => {
    if (!data || data.length === 0) return

    const headers = Object.keys(data[0])
    const csvContent = [
      headers.join(','),
      ...data.map(row => headers.map(header => row[header]).join(','))
    ].join('\n')

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    const url = URL.createObjectURL(blob)
    link.setAttribute('href', url)
    link.setAttribute('download', `${filename}.csv`)
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  const exportToJSON = () => {
    if (!data || data.length === 0) return

    const jsonContent = JSON.stringify(data, null, 2)
    const blob = new Blob([jsonContent], { type: 'application/json;charset=utf-8;' })
    const link = document.createElement('a')
    const url = URL.createObjectURL(blob)
    link.setAttribute('href', url)
    link.setAttribute('download', `${filename}.json`)
    link.style.visibility = 'hidden'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  return (
    <div className={`flex gap-2 ${className}`}>
      <button
        onClick={exportToCSV}
        className="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors duration-200"
      >
        Export CSV
      </button>
      <button
        onClick={exportToJSON}
        className="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors duration-200"
      >
        Export JSON
      </button>
    </div>
  )
}