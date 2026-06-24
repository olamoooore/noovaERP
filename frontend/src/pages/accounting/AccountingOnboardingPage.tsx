import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import api from '@/lib/api';
import { useState, useMemo } from 'react';
import { toast } from '@/components/ui/use-toast';

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
/*  Step Form Components                                               */
/* ------------------------------------------------------------------ */

function GroupSetupForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  return (
    <div className="space-y-4">
      <div>
        <Label>Group Name *</Label>
        <Input
          placeholder="e.g. Acme Holdings"
          value={data?.group_name || ''}
          onChange={(e) => onChange({ ...data, group_name: e.target.value })}
        />
      </div>
      <div>
        <Label>Group Code *</Label>
        <Input
          placeholder="e.g. VHOLD"
          value={data?.group_code || ''}
          onChange={(e) => onChange({ ...data, group_code: e.target.value })}
        />
        <p className="text-xs text-muted-foreground mt-1">Unique identifier for this group</p>
      </div>
      <div>
        <Label>Base Currency</Label>
        <Select
          value={data?.base_currency || 'SGD'}
          onValueChange={(v) => onChange({ ...data, base_currency: v })}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="SGD">SGD — Singapore Dollar</SelectItem>
            <SelectItem value="USD">USD — US Dollar</SelectItem>
            <SelectItem value="MYR">MYR — Malaysian Ringgit</SelectItem>
            <SelectItem value="IDR">IDR — Indonesian Rupiah</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div>
        <Label>Fiscal Year Start</Label>
        <Select
          value={data?.fiscal_year_start || '01-01'}
          onValueChange={(v) => onChange({ ...data, fiscal_year_start: v })}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="01-01">January 1</SelectItem>
            <SelectItem value="04-01">April 1</SelectItem>
            <SelectItem value="07-01">July 1</SelectItem>
            <SelectItem value="10-01">October 1</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>
  );
}

function EntitySelectionForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  return (
    <div className="space-y-4">
      <div>
        <Label>Entity Name *</Label>
        <Input
          placeholder="e.g. Acme Pte Ltd"
          value={data?.entity_name || ''}
          onChange={(e) => onChange({ ...data, entity_name: e.target.value })}
        />
      </div>
      <div>
        <Label>Entity Code *</Label>
        <Input
          placeholder="e.g. ACME"
          value={data?.entity_code || ''}
          onChange={(e) => onChange({ ...data, entity_code: e.target.value })}
        />
      </div>
      <div>
        <Label>Country of Operation *</Label>
        <Select
          value={data?.country_code || ''}
          onValueChange={(v) => onChange({ ...data, country_code: v })}
        >
          <SelectTrigger>
            <SelectValue placeholder="Select country" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="SG">Singapore</SelectItem>
            <SelectItem value="MY">Malaysia</SelectItem>
            <SelectItem value="ID">Indonesia</SelectItem>
            <SelectItem value="TH">Thailand</SelectItem>
            <SelectItem value="VN">Vietnam</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div>
        <Label>Business Registration Number</Label>
        <Input
          placeholder="e.g. 201612345K"
          value={data?.registration_number || ''}
          onChange={(e) => onChange({ ...data, registration_number: e.target.value })}
        />
      </div>
      <div>
        <Label>Tax ID (GST/VAT)</Label>
        <Input
          placeholder="e.g. M90312345"
          value={data?.tax_id || ''}
          onChange={(e) => onChange({ ...data, tax_id: e.target.value })}
        />
      </div>
      <div>
        <Label>Address</Label>
        <Textarea
          placeholder="Registered business address"
          value={data?.address || ''}
          onChange={(e) => onChange({ ...data, address: e.target.value })}
        />
      </div>
    </div>
  );
}

function CountryComplianceForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  const countries = [
    { code: 'SG', name: 'Singapore', compliance: 'ACRA-SFRS-2024' },
    { code: 'MY', name: 'Malaysia', compliance: 'MFRS-2024' },
    { code: 'ID', name: 'Indonesia', compliance: 'PSAK-2024' },
  ];

  return (
    <div className="space-y-4">
      <div>
        <Label className="text-base font-semibold">Select Country & Compliance Profile</Label>
        <p className="text-sm text-muted-foreground mb-4">
          Choose your country of operation. The compliance profile determines tax rules, reporting standards, and XBRL taxonomy.
        </p>
      </div>
      
      <RadioGroup
        value={data?.country_code || ''}
        onValueChange={(v) => {
          const country = countries.find((c) => c.code === v);
          onChange({
            ...data,
            country_code: v,
            compliance_profile: country?.compliance,
          });
        }}
        className="space-y-3"
      >
        {countries.map((country) => (
          <div key={country.code} className="flex items-start space-x-3 rounded-lg border p-4 hover:bg-muted/50">
            <RadioGroupItem value={country.code} id={country.code} className="mt-1" />
            <div className="flex-1">
              <Label htmlFor={country.code} className="font-semibold cursor-pointer">
                {country.name}
              </Label>
              <p className="text-sm text-muted-foreground">
                Compliance: <Badge variant="outline" className="text-xs">{country.compliance}</Badge>
              </p>
            </div>
          </div>
        ))}
      </RadioGroup>

      {data?.country_code && (
        <div className="rounded-lg bg-primary/5 p-4 mt-4">
          <h4 className="font-semibold text-sm mb-2">Selected Profile</h4>
          <div className="text-sm space-y-1">
            <p><strong>Country:</strong> {countries.find((c) => c.code === data.country_code)?.name}</p>
            <p><strong>Compliance:</strong> {data.compliance_profile}</p>
            <p className="text-muted-foreground text-xs mt-2">
              This determines your chart of accounts structure, tax codes, and statutory reporting requirements.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

function CoAImportForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  const templates = [
    { code: '4-digit', name: '4-Digit Code Structure', description: '1000-9999 range, suitable for small businesses' },
    { code: '5-digit', name: '5-Digit Code Structure', description: '10000-99999 range, suitable for medium businesses' },
    { code: '6-digit', name: '6-Digit Code Structure', description: '100000-999999 range, suitable for enterprises with multiple divisions' },
  ];

  return (
    <div className="space-y-4">
      <div>
        <Label className="text-base font-semibold">Chart of Accounts Template</Label>
        <p className="text-sm text-muted-foreground mb-4">
          Select your preferred account code structure. This cannot be changed after setup.
        </p>
      </div>

      <RadioGroup
        value={data?.coa_template || ''}
        onValueChange={(v) => onChange({ ...data, coa_template: v })}
        className="space-y-3"
      >
        {templates.map((t) => (
          <div key={t.code} className="flex items-start space-x-3 rounded-lg border p-4 hover:bg-muted/50">
            <RadioGroupItem value={t.code} id={t.code} className="mt-1" />
            <div className="flex-1">
              <Label htmlFor={t.code} className="font-semibold cursor-pointer">
                {t.name}
              </Label>
              <p className="text-sm text-muted-foreground">{t.description}</p>
            </div>
          </div>
        ))}
      </RadioGroup>

      {data?.coa_template && (
        <div className="rounded-lg bg-primary/5 p-4 mt-4">
          <h4 className="font-semibold text-sm mb-2">Template Preview</h4>
          <div className="text-sm space-y-1">
            <p><strong>Structure:</strong> {data.coa_template}</p>
            <p><strong>Sample Codes:</strong></p>
            <ul className="list-disc list-inside text-muted-foreground text-xs ml-2">
              {data.coa_template === '4-digit' && (
                <>
                  <li>1000 — Assets</li>
                  <li>2000 — Liabilities</li>
                  <li>3000 — Equity</li>
                  <li>4000 — Revenue</li>
                  <li>5000 — Expenses</li>
                </>
              )}
              {data.coa_template === '5-digit' && (
                <>
                  <li>10000 — Current Assets</li>
                  <li>11000 — Cash & Bank</li>
                  <li>20000 — Current Liabilities</li>
                  <li>40000 — Operating Revenue</li>
                  <li>50000 — Operating Expenses</li>
                </>
              )}
              {data.coa_template === '6-digit' && (
                <>
                  <li>100000 — Current Assets</li>
                  <li>101000 — Cash & Equivalents</li>
                  <li>101100 — Petty Cash</li>
                  <li>200000 — Current Liabilities</li>
                  <li>500000 — Operating Expenses</li>
                </>
              )}
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}

function RoleAssignmentForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  const defaultRoles = [
    { key: 'admin', name: 'Admin', description: 'Full access to all accounting functions', permissions: ['view', 'create', 'edit', 'delete', 'post', 'approve'] },
    { key: 'accountant', name: 'Accountant', description: 'Can create and post transactions, run reports', permissions: ['view', 'create', 'edit', 'post'] },
    { key: 'billing_clerk', name: 'Billing Clerk', description: 'Can create invoices and bills, cannot post', permissions: ['view', 'create', 'edit'] },
    { key: 'viewer', name: 'Viewer', description: 'Read-only access to reports and transactions', permissions: ['view'] },
    { key: 'auditor', name: 'Auditor', description: 'Read-only with export access for audit purposes', permissions: ['view', 'export'] },
  ];

  return (
    <div className="space-y-4">
      <div>
        <Label className="text-base font-semibold">Default Accounting Roles</Label>
        <p className="text-sm text-muted-foreground mb-4">
          These roles will be created for your tenant. You can customize them later.
        </p>
      </div>

      <div className="space-y-3">
        {defaultRoles.map((role) => (
          <div key={role.key} className="flex items-start space-x-3 rounded-lg border p-4">
            <Checkbox
              id={role.key}
              checked={data?.selected_roles?.includes(role.key) ?? true}
              onCheckedChange={(checked) => {
                const current = data?.selected_roles || defaultRoles.map((r) => r.key);
                const updated = checked
                  ? [...current, role.key]
                  : current.filter((k: string) => k !== role.key);
                onChange({ ...data, selected_roles: updated });
              }}
            />
            <div className="flex-1">
              <Label htmlFor={role.key} className="font-semibold cursor-pointer">
                {role.name}
              </Label>
              <p className="text-sm text-muted-foreground">{role.description}</p>
              <div className="flex flex-wrap gap-1 mt-2">
                {role.permissions.map((p) => (
                  <Badge key={p} variant="secondary" className="text-[10px]">
                    {p}
                  </Badge>
                ))}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function ValidationForm({ data, onChange, stepStates }: { data: any; onChange: (d: any) => void; stepStates: any[] }) {
  const checks = [
    { key: 'group_setup', label: 'Group Setup', required: true },
    { key: 'entity_selection', label: 'Entity Selection', required: true },
    { key: 'country_compliance', label: 'Country & Compliance', required: true },
    { key: 'coa_import', label: 'Chart of Accounts', required: true },
    { key: 'role_assignment', label: 'Account Roles', required: true },
  ];

  const completedSteps = stepStates.filter((s) => s.status === 'completed').map((s) => s.step_code);
  const allCompleted = checks.every((c) => completedSteps.includes(c.key));

  return (
    <div className="space-y-4">
      <div>
        <Label className="text-base font-semibold">Review & Validate Setup</Label>
        <p className="text-sm text-muted-foreground mb-4">
          Review your configuration before going live. All required steps must be completed.
        </p>
      </div>

      <div className="space-y-2">
        {checks.map((check) => {
          const isDone = completedSteps.includes(check.key);
          return (
            <div
              key={check.key}
              className={`flex items-center justify-between rounded-lg border p-3 ${
                isDone ? 'bg-green-50/50 border-green-200' : 'bg-amber-50/50 border-amber-200'
              }`}
            >
              <div className="flex items-center gap-3">
                <div
                  className={`h-6 w-6 rounded-full flex items-center justify-center ${
                    isDone ? 'bg-green-100' : 'bg-amber-100'
                  }`}
                >
                  <Icon
                    icon={isDone ? 'solar:check-read-linear' : 'solar:danger-triangle-bold-duotone'}
                    className={`h-3.5 w-3.5 ${isDone ? 'text-green-600' : 'text-amber-600'}`}
                  />
                </div>
                <span className="text-sm font-medium">{check.label}</span>
              </div>
              <Badge variant={isDone ? 'default' : 'destructive'} className="text-xs">
                {isDone ? 'Complete' : 'Pending'}
              </Badge>
            </div>
          );
        })}
      </div>

      {!allCompleted && (
        <div className="rounded-lg bg-destructive/10 p-4 text-sm text-destructive">
          <div className="flex items-center gap-2">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
            <strong>Cannot proceed to Go Live</strong>
          </div>
          <p className="mt-1">Please complete all required steps above before going live.</p>
        </div>
      )}

      {allCompleted && (
        <div className="rounded-lg bg-green-50 p-4 text-sm text-green-800">
          <div className="flex items-center gap-2">
            <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
            <strong>All checks passed</strong>
          </div>
          <p className="mt-1">Your accounting system is ready to go live.</p>
        </div>
      )}

      <div className="flex items-center gap-2 mt-4">
        <Checkbox
          id="confirm-ready"
          checked={data?.confirmed || false}
          onCheckedChange={(checked) => onChange({ ...data, confirmed: checked })}
        />
        <Label htmlFor="confirm-ready" className="text-sm cursor-pointer">
          I confirm that all setup information is accurate and complete
        </Label>
      </div>
    </div>
  );
}

function GoLiveForm({ data, onChange }: { data: any; onChange: (d: any) => void }) {
  return (
    <div className="space-y-4">
      <div className="rounded-lg bg-amber-50 border border-amber-200 p-4">
        <div className="flex items-start gap-3">
          <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 text-amber-600 mt-0.5" />
          <div>
            <h4 className="font-semibold text-amber-800 text-sm">Important: Post-Go-Live Restrictions</h4>
            <p className="text-sm text-amber-700 mt-1">
              After going live, certain settings will be locked to maintain data integrity:
            </p>
            <ul className="list-disc list-inside text-sm text-amber-700 mt-2 space-y-1">
              <li>Chart of accounts structure cannot be changed</li>
              <li>Fiscal year start date is locked</li>
              <li>Base currency cannot be modified</li>
              <li>Posted transactions cannot be edited or deleted</li>
              <li>Entity and group structure requires admin approval to change</li>
            </ul>
          </div>
        </div>
      </div>

      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Checkbox
            id="understand-restrictions"
            checked={data?.understand_restrictions || false}
            onCheckedChange={(checked) => onChange({ ...data, understand_restrictions: checked })}
          />
          <Label htmlFor="understand-restrictions" className="text-sm cursor-pointer">
            I understand the post-go-live restrictions listed above
          </Label>
        </div>

        <div className="flex items-center gap-2">
          <Checkbox
            id="backup-data"
            checked={data?.backup_confirmed || false}
            onCheckedChange={(checked) => onChange({ ...data, backup_confirmed: checked })}
          />
          <Label htmlFor="backup-data" className="text-sm cursor-pointer">
            I have reviewed my setup and confirm all data is accurate
          </Label>
        </div>

        <div className="flex items-center gap-2">
          <Checkbox
            id="authorize-go-live"
            checked={data?.authorized || false}
            onCheckedChange={(checked) => onChange({ ...data, authorized: checked })}
          />
          <Label htmlFor="authorize-go-live" className="text-sm cursor-pointer font-semibold">
            I authorize Go Live and accept full responsibility for this action
          </Label>
        </div>
      </div>
    </div>
  );
}

const STEP_FORMS: Record<string, React.FC<any>> = {
  group_setup: GroupSetupForm,
  entity_selection: EntitySelectionForm,
  country_compliance: CountryComplianceForm,
  coa_import: CoAImportForm,
  role_assignment: RoleAssignmentForm,
  validation: ValidationForm,
  go_live: GoLiveForm,
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
  onBack,
  onAbandon,
  onSkip,
  isAdvancing,
  isBacking,
  stepData,
  onStepDataChange,
}: {
  session: any;
  steps: any[];
  stepStates: any[];
  onAdvance: (data: any) => void;
  onBack: () => void;
  onAbandon: () => void;
  onSkip: () => void;
  isAdvancing: boolean;
  isBacking: boolean;
  stepData: Record<string, any>;
  onStepDataChange: (step: string, data: any) => void;
}) {
  const currentStepKey = session.current_step;
  const currentIndex = steps.findIndex((s: any) => s.key === currentStepKey);
  const completedCount = stepStates.filter((s: any) => s.status === 'completed').length;
  const progress = Math.round((completedCount / steps.length) * 100);
  const isCompleted = session.status === 'completed';
  const isLastStep = currentIndex === steps.length - 1;
  const isFirstStep = currentIndex === 0;

  const currentStepData = stepData[currentStepKey] || {};
  const StepForm = STEP_FORMS[currentStepKey];

  // Validation for current step
  const canAdvance = useMemo(() => {
    if (!StepForm) return true;
    
    switch (currentStepKey) {
      case 'group_setup':
        return !!currentStepData?.group_name?.trim() && !!currentStepData?.group_code?.trim();
      case 'entity_selection':
        return !!currentStepData?.entity_name?.trim() && !!currentStepData?.entity_code?.trim() && !!currentStepData?.country_code;
      case 'country_compliance':
        return !!currentStepData?.country_code;
      case 'coa_import':
        return !!currentStepData?.coa_template;
      case 'role_assignment':
        return (currentStepData?.selected_roles?.length || 0) > 0;
      case 'validation':
        return currentStepData?.confirmed === true;
      case 'go_live':
        return currentStepData?.understand_restrictions === true && 
               currentStepData?.backup_confirmed === true && 
               currentStepData?.authorized === true;
      default:
        return true;
    }
  }, [currentStepKey, currentStepData, StepForm]);

  const completedSteps = stepStates.filter((s: any) => s.status === 'completed').map((s: any) => s.step_code);

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
          <Button className="mt-4 gap-2" onClick={() => window.location.href = '/accounting'}>
            <Icon icon="solar:buildings-bold-duotone" className="h-4 w-4" />
            Go to Accounting
          </Button>
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

            {/* Step Form */}
            {StepForm && (
              <div className="mb-6">
                <StepForm
                  data={currentStepData}
                  onChange={(data: any) => onStepDataChange(currentStepKey, data)}
                  stepStates={stepStates}
                />
              </div>
            )}

            {!StepForm && (
              <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground mb-6">
                <Icon icon={STEP_ICONS[steps[currentIndex].key] || 'solar:settings-bold-duotone'} className="h-8 w-8 mx-auto mb-3 opacity-40" />
                Configure this step in the relevant section, then mark it complete to continue.
              </div>
            )}

            {/* Navigation */}
            <div className="flex items-center justify-between pt-4 border-t">
              <div className="flex items-center gap-2">
                {!isFirstStep && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={onBack}
                    disabled={isBacking || isAdvancing}
                    className="gap-1.5"
                  >
                    {isBacking ? (
                      <Icon icon="solar:refresh-bold-duotone" className="h-4 w-4 animate-spin" />
                    ) : (
                      <Icon icon="solar:arrow-left-linear" className="h-4 w-4" />
                    )}
                    Back
                  </Button>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={onAbandon}
                  disabled={isAdvancing || isBacking}
                  className="text-muted-foreground"
                >
                  <Icon icon="solar:close-circle-linear" className="h-4 w-4 mr-1.5" />
                  Cancel
                </Button>
              </div>

              <div className="flex items-center gap-2">
                {session.mode === 'expert' && !isLastStep && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={onSkip}
                    disabled={isAdvancing || isBacking}
                    className="text-muted-foreground"
                  >
                    Skip
                  </Button>
                )}
                <Button
                  onClick={() => onAdvance(currentStepData)}
                  disabled={isAdvancing || !canAdvance}
                  className="gap-2"
                >
                  {isAdvancing ? (
                    <>
                      <Icon icon="solar:refresh-bold-duotone" className="h-4 w-4 animate-spin" />
                      Saving...
                    </>
                  ) : isLastStep ? (
                    <>
                      <Icon icon="solar:rocket-bold-duotone" className="h-4 w-4" />
                      Go Live
                    </>
                  ) : (
                    <>
                      Continue
                      <Icon icon="solar:arrow-right-linear" className="h-4 w-4" />
                    </>
                  )}
                </Button>
              </div>
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
  const [stepData, setStepData] = useState<Record<string, any>>({});

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

  // Find the active or most recently completed session
  const activeSession = useMemo(() => {
    if (!sessionsData?.length) return null;
    return (
      sessionsData.find((s: any) => s.status === 'in_progress') ||
      sessionsData.find((s: any) => s.status === 'completed') ||
      null
    );
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
    mutationFn: ({ id, data }: { id: number; data: any }) =>
      api.post(`/accounting/onboarding/sessions/${id}/advance`, { step_data: data }),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-session', activeSession!.id] });
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-sessions'] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to advance step');
    },
  });

  // Back step
  const backMutation = useMutation({
    mutationFn: (id: number) => api.post(`/accounting/onboarding/sessions/${id}/back`),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-session', activeSession!.id] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to go back');
    },
  });

  // Skip step
  const skipMutation = useMutation({
    mutationFn: (id: number) => api.post(`/accounting/onboarding/sessions/${id}/skip`, { step_code: activeSession?.current_step }),
    onSuccess: () => {
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['accounting-onboarding-session', activeSession!.id] });
    },
    onError: (err: any) => {
      setError(err?.response?.data?.message || err?.message || 'Failed to skip step');
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
          onAdvance={(data) => advanceMutation.mutate({ id: activeSession.id, data })}
          onBack={() => backMutation.mutate(activeSession.id)}
          onAbandon={() => abandonMutation.mutate()}
          onSkip={() => skipMutation.mutate(activeSession.id)}
          isAdvancing={advanceMutation.isPending}
          isBacking={backMutation.isPending}
          stepData={stepData}
          onStepDataChange={(step, data) => setStepData((prev) => ({ ...prev, [step]: data }))}
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
