import { X } from 'lucide-react';

interface ModuleBadgeProps {
  module: string;
  onClose?: () => void;
}

const moduleConfig: Record<string, { icon: string; label: string }> = {
  orders: { icon: '📦', label: 'Orders' },
  loyalty: { icon: '🎁', label: 'Loyalty' },
  products: { icon: '🛍️', label: 'Products' },
  invoices: { icon: '💰', label: 'Invoices' },
  account: { icon: '👤', label: 'Account' },
  general: { icon: '❓', label: 'General' },
};

export function ModuleBadge({ module, onClose }: ModuleBadgeProps) {
  if (module === 'general' || !module) {
    return null; // Don't show badge for general module
  }

  const config = moduleConfig[module] || { icon: '💬', label: module };

  return (
    <div className="flex items-center gap-1 px-3 py-1.5 bg-white border border-gray-200 rounded-md text-sm shadow-sm">
      <span className="text-base">{config.icon}</span>
      <span className="font-medium text-gray-900">{config.label}</span>
      {onClose && (
        <button
          onClick={onClose}
          className="ml-1 p-0.5 hover:bg-gray-100 rounded transition-colors"
          title="Change topic"
        >
          <X className="h-3.5 w-3.5 text-gray-600" />
        </button>
      )}
    </div>
  );
}
