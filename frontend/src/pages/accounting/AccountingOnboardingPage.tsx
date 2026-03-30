import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import api from '@/lib/api';

export default function AccountingOnboardingPage() {
  const { data: stepsData, isLoading: isLoadingSteps } = useQuery({
    queryKey: ['accounting-onboarding-steps'],
    queryFn: async () => {
      const response = await api.get('/accounting/onboarding/steps');
      return response.data.data || [];
    },
  });

  const { data: sessionsData, isLoading: isLoadingSessions, refetch } = useQuery({
    queryKey: ['accounting-onboarding-sessions'],
    queryFn: async () => {
      const response = await api.get('/accounting/onboarding/sessions');
      return response.data.data || [];
    },
  });

  const startSession = async () => {
    await api.post('/accounting/onboarding/sessions', {});
    refetch();
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Accounting Onboarding</h1>
          <p className="text-muted-foreground mt-1">
            Start and monitor accounting setup sessions from the UI.
          </p>
        </div>
        <Button onClick={startSession}>
          <Icon icon="solar:play-circle-bold-duotone" className="h-4 w-4 mr-2" />
          Start Session
        </Button>
      </div>

      <Card className="p-6">
        <h2 className="text-lg font-semibold mb-4">Setup Steps</h2>
        {isLoadingSteps ? (
          <div className="text-sm text-muted-foreground">Loading steps...</div>
        ) : (
          <div className="space-y-3">
            {(stepsData || []).map((step: any, index: number) => (
              <div key={step.key || index} className="flex items-start gap-3 border rounded-lg p-4">
                <div className="mt-0.5 h-8 w-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-semibold">
                  {index + 1}
                </div>
                <div>
                  <div className="font-medium">{step.label || step.name || step.key}</div>
                  <div className="text-sm text-muted-foreground">{step.description || 'No description provided.'}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-6">
        <h2 className="text-lg font-semibold mb-4">Sessions</h2>
        {isLoadingSessions ? (
          <div className="text-sm text-muted-foreground">Loading sessions...</div>
        ) : !sessionsData || sessionsData.length === 0 ? (
          <div className="text-sm text-muted-foreground">No onboarding sessions found.</div>
        ) : (
          <div className="space-y-3">
            {sessionsData.map((session: any) => (
              <div key={session.id} className="flex items-center justify-between border rounded-lg p-4">
                <div>
                  <div className="font-medium">Session #{session.id}</div>
                  <div className="text-sm text-muted-foreground">
                    Status: {session.status || 'unknown'}
                  </div>
                </div>
                <div className="text-sm text-muted-foreground">
                  Current step: {session.current_step_key || session.current_step || 'n/a'}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
