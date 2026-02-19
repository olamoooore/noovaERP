import { Button } from '@/components/ui/button';

interface QuickOption {
  id: string;
  icon: string;
  label: string;
  description: string;
}

interface QuickOptionsProps {
  options: QuickOption[];
  onSelect: (optionId: string) => void;
}

export function QuickOptions({ options, onSelect }: QuickOptionsProps) {
  return (
    <div className="space-y-2 my-4">
      <p className="text-sm text-gray-600 mb-3">🔧 Choose an option:</p>
      {options.map((option) => (
        <Button
          key={option.id}
          variant="outline"
          className="w-full justify-start text-left h-auto py-3 px-4 hover:bg-orange-50 hover:border-orange-300 transition-all"
          onClick={() => onSelect(option.id)}
        >
          <div className="flex items-center gap-3 w-full">
            <span className="text-2xl flex-shrink-0">{option.icon}</span>
            <div className="flex-1 min-w-0">
              <div className="font-semibold text-gray-900">{option.label}</div>
              <div className="text-xs text-gray-500 mt-0.5">{option.description}</div>
            </div>
            <span className="text-gray-400">›</span>
          </div>
        </Button>
      ))}
    </div>
  );
}
