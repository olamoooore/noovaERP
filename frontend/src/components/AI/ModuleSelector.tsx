import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Loader2 } from 'lucide-react';
import api from '@/lib/api';

interface Module {
  name: string;
  display_name: string;
  icon: string;
  description: string;
  functions_count: number;
}

interface ModuleSelectorProps {
  onSelectModule: (moduleName: string) => void;
  selectedModule?: string;
}

export function ModuleSelector({ onSelectModule, selectedModule }: ModuleSelectorProps) {
  const { data: modules, isLoading } = useQuery<Module[]>({
    queryKey: ['ai-modules'],
    queryFn: async () => {
      const response = await api.get('/ai/modules');
      return response.data.data;
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-gray-400" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="text-center">
        <h2 className="text-2xl font-bold text-gray-900 mb-2">
          👋 Hi! How can I help you today?
        </h2>
        <p className="text-gray-600">
          Please select a topic to get started
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {modules?.map((module) => (
          <Card
            key={module.name}
            className={`cursor-pointer transition-all hover:shadow-lg hover:scale-105 ${
              selectedModule === module.name
                ? 'ring-2 ring-blue-500 bg-blue-50'
                : 'hover:bg-gray-50'
            }`}
            onClick={() => onSelectModule(module.name)}
          >
            <CardHeader>
              <div className="flex items-center gap-3">
                <span className="text-4xl">{module.icon}</span>
                <div className="flex-1">
                  <CardTitle className="text-lg">{module.display_name}</CardTitle>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <CardDescription className="text-sm">
                {module.description}
              </CardDescription>
              {module.functions_count > 0 && (
                <p className="text-xs text-gray-500 mt-2">
                  {module.functions_count} functions available
                </p>
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="text-center">
        <p className="text-sm text-gray-500">
          Or type your question directly in the chat below
        </p>
      </div>
    </div>
  );
}
