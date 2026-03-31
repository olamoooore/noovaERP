import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import api from '@/lib/api';
import { useState, useMemo } from 'react';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function normalizeSteps(raw: unknown): any[] {
  if (Array.isArray(raw)) return raw;
  if (raw && typeof raw === 'object') {
    return Object.entries(raw).map(([key, val]: [string, any]) => ({ key, ...val }));
  }
  return [];
}

function normalizeArray(raw: unknown): any[] {
  if (Array.isArray(raw)) return raw;
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    return Object.values(raw);
  }
  return [];
}

const STEP_ICONS: Record<string, string> = {
  group_setup: 'solar:buildings-bold-duotone',
  entity_selection: 'solar:widget-add-bold-duotone',
  country_compliance: 'solar:flag-bold-duotone',
  coa_import: 'solar:document-add-bold-duotone',
  role_assignment: 'solar:user-check-bold-duotone',
  validation: 'solar:shield-check-bold-duotone',
  go_live: 'solar:rocket-bold-duotone',
};

/* ------------------------------------------------------------------ */
/*  Welcome Screen — no active session                                 */
/* ------------------------------------------------------------------ */

function WelcomeScreen({
  steps,
  onStart,
  isPending,
}: {
  steps: any[];
  onStart: () => void;
  isPending: boolean;
}) {
  return (
    <div className="flex flex-col items-center justify-center min-h-[60vh] px-4">
      <div className="w-16 h-16 rounded-2xl bg-primary/10 flex items-center justify-center mb-6">
        <Icon icon="solar:notebook-bold-duotone" className="h-8 w-8 text-primary" />
      </div>
      <h1 className="text-3xl font-bold tracking-tight mb-2">Set Up Your Accounting</h1>
      <p className="text-muted-foreground text-center max-w-md mb-8">
        We'll walk you through {steps.length} quick steps to configure your chart of accounts,
        compliance rules, and go live.
      </p>

      <div className="w-full max-w-lg mb-8">
        <div className="grid gap-3">
          {steps.map((step, i) => (
            <div key={step.key} className="flex items-center gap-3 text-sm">
              <div className="h-7 w-7 rounded-full bg-muted flex items-center justify-center text-xs font-semibold text-muted-foreground shrink-0">
                {i + 1}
              </div>
              <span className="font-medium">{step.label}</span>
            </div>
          ))}
        </div>
      </div>

      <Button size="lg" onClick={onStart} disabled={isPending} className="gap-2">
        <Icon icon="solar:play-circle-bold-duotone" className="h-5 w-5" />
        {isPending ? 'Starting...' : 'Begin Setup'}
      </Button>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  Wizard — active session                                            */
/* ------------------------------------------------------------------ */

function Wizard({
  session,
  steps,
  stepStates,
  onAdvance,
  onAbandon,
  isAdvancing,
}: {
  session: any;
  steps: any[];
  stepStates: any[];
  onAdvance: () => void;
  onAbandon: () => void;
  isAdvancing: boolean;
}) {
  const currentStepKey = session.current_step;
  const currentIndex = steps.findIndex((s: any) => s.key === currentStepKey);
  const completedCount = stepStates.filter((s: any) => s.status === 'completed').length;
  const progress = Math.round((completedCount / steps.length) * 100);
  const isCompleted = session.status === 'completed';
  const isLastStep = currentIndex === steps.length - 1;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Accounting Setup</h1>
        <div className="flex items-center gap-3 mt-2">
          <Progress value={isCompleted ? 100 : progress} className="flex-1 h-2" />
          <span className="text-sm font-medium text-muted-foreground whitespace-nowrap">
            {isCompleted ? steps.length : completedCount} / {steps.length} complete
          </span>
        </div>
      </div>

      {/* Completed state */}
      {isCompleted && (
        <Card className="p-8 text-center border-green-200 bg-green-50/50">
          <div className="w-14 h-14 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
            <Icon icon="solar:check-circle-bold-duotone" className="h-7 w-7 text-green-600" />
          </div>
          <h2 className="text-xl font-semibold mb-1">Setup Complete</h2>
          <p className="text-muted-foreground text-sm">
            Your accounting system is configured and ready to use.
          </p>
        </Card>
      )}

      {/* Step timeline + current step content */}
      <div className="grid md:grid-cols-[220px_1fr] gap-6">
        {/* Left: Step timeline */}
        <div className="space-y-1">
          {steps.map((step: any, i: number) => {
            const state = stepStates.find((s: any) => s.step_code === step.key);
            const status = state?.status || 'pending';
            const isCurrent = step.key === currentStepKey && !isCompleted;

            return (
              <div
                key={step.key}
                className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors ${
                  isCurrent
                    ? 'bg-primary/10 text-primary font-semibold'
                    : status === 'completed'
                    ? 'text-muted-foreground'
                    : 'text-muted-foreground/60'
                }`}
              >
                <div className="shrink-0">
                  {status === 'completed' ? (
                    <div className="h-6 w-6 rounded-full bg-green-100 flex items-center justify-center">
                      <Icon icon="solar:check-read-linear" className="h-3.5 w-3.5 text-green-600" />
                    </div>
                  ) : isCurrent ? (
                    <div className="h-6 w-6 rounded-full bg-primary flex items-center justify-center">
                      <span className="text-[11px] font-bold text-primary-foreground">{i + 1}</span>
                    </div>
                  ) : (
                    <div className="h-6 w-6 rounded-full border-2 border-muted flex items-center justify-center">
                      <span className="text-[11px] font-semibold">{i + 1}</span>
                    </div>
                  )}
                </div>
                <span className="truncate">{step.label}</span>
              </div>
            );
          })}
        </div>

        {/* Right: Current step content */}
        {!isCompleted && currentIndex >= 0 && (
          <Card className="p-6">
            <div className="flex items-start gap-4 mb-6">
              <div className="h-11 w-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                <Icon
                  icon={STEP_ICONS[steps[currentIndex].key] || 'solar:settings-bold-duotone'}
                  className="h-5 w-5 text-primary"
                />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <h2 className="text-lg font-semibold">{steps[currentIndex].label}</h2>
                  <Badge variant="outline" className="text-xs">
                    Step {currentIndex + 1} of {steps.length}
                  </Badge>
                </div>
                <p className="text-sm text-muted-foreground mt-1">
                  {steps[currentIndex].description}
                </p>
              </div>
            </div>

            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground mb-6">
              <Icon icon={STEP_ICONS[steps[currentIndex].key] || 'solar:settings-bold-duotone'} className="h-8 w-8 mx-auto mb-3 opacity-40" />
              Configure this step in the relevant section, then mark it complete to continue.
            </div>

            <div className="flex items-center justify-between">
              <Button variant="ghost" size="sm" onClick={onAbandon} className="text-muted-foreground">
                <Icon icon="solar:close-circle-linear" className="h-4 w-4 mr-1.5" />
                Abandon Setup
              </Button>
              <Button onClick={onAdvance} disabled={isAdvancing} className="gap-2">
                {isAdvancing ? (
                  <>
                    <Icon icon="solar:refresh-bold-duotone" className="h-4 w-4 animate-spin" />
                    Saving...
                  </>
                ) : isLastStep ? (
                  <>
                    <Icon icon="solar:rocket-bold-duotone" className="h-4 w-4" />
                    Complete Setup
                  </>
                ) : (
                  <>
                    Mark Complete & Continue
                    <Icon icon="solar:arrow-right-linear" className="h-4 w-4" />
                  </>
                )}
              </Button>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/*  Main Page                                                          */
/* ------------------------------------------------------------------ */

export default function AccountingOnboardingPage() {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);

  // Fetch step definitions
  const { data: stepsData, isLoading: isLoadingSteps } = useQuery({
    queryKey: ['accounting-onboarding-steps'],
    queryFn: async () => {
      const response = await api.get('/accounting/onboarding/steps');
      return normalizeSteps(response.data?.data ?? response.data);
    },
  });

  // Fetch sessions — find the latest active one
  const { data: sessionsData, isLoading: isLoadingSessions } = useQuery({
    queryKey: ['accounting-onboarding-sessions'],
    queryFn: async () => {
      const response = await api.get('/accounting/onboarding/sessions');
      return normalizeArray(response.data?.data ?? response.data);
    },
  });

  // Find the active (most recent in_progress) session
  const activeSession = useMemo(() => {
    if (!sessionsData?.length) return null;
    return sessionsData.find((s: any) => s.status === 'in_progress') || null;
  }, [sessionsData]);

  // Fetch active session detail (with step states)
  const { data: sessionDetail } = useQuery({
    queryKey: ['accounting-onboarding-session', activeSession?.id],
    queryFn: async () => {
      const response = await api.get(`/accounting/onboarding/sessions/${activeSession!.id}`);
      return response.data?.data ?? response.data;
    },
    enabled: !!activeSession?.id,
  });

  // Start session
  const startMutation = useMutation({
    mutationFn: () => api.post('/accounting/onboarding/sessions', {}),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-sessions'] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to start session');
    },
  });

  // Advance step
  const advanceMutation = useMutation({
    mutationFn: () => api.post(`/accounting/onboarding/sessions/${activeSession!.id}/advance`),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-session', activeSession!.id] });
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-sessions'] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to advance step');
    },
  });

  // Abandon session
  const abandonMutation = useMutation({
    mutationFn: () => api.post(`/accounting/onboarding/sessions/${activeSession!.id}/abandon`),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-sessions'] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to abandon session');
    },
  });

  const steps = stepsData || [];
  const isLoading = isLoadingSteps || isLoadingSessions;

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh] text-muted-foreground">
        <Icon icon="solar:refresh-bold-duotone" className="h-5 w-5 animate-spin mr-2" />
        Loading...
      </div>
    );
  }

  return (
    <>
      {error && (
        <div className="mx-6 mt-6 bg-destructive/10 text-destructive text-sm rounded-lg p-3 flex items-center gap-2">
          <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4 shrink-0" />
          {error}
          <button onClick={() => setError(null)} className="ml-auto text-destructive/60 hover:text-destructive">
            <Icon icon="solar:close-circle-linear" className="h-4 w-4" />
          </button>
        </div>
      )}

      {activeSession && sessionDetail ? (
        <Wizard
          session={activeSession}
          steps={steps}
          stepStates={normalizeArray(sessionDetail.steps)}
          onAdvance={() => advanceMutation.mutate()}
          onAbandon={() => abandonMutation.mutate()}
          isAdvancing={advanceMutation.isPending}
        />
      ) : (
        <WelcomeScreen
          steps={steps}
          onStart={() => startMutation.mutate()}
          isPending={startMutation.isPending}
        />
      )}
    </>
  );
}
