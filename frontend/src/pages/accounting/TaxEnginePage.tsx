import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import { useTaxConfig, useUpdateSettings } from '@/hooks/queries/useSettings';
import api from '@/lib/api';

interface EngineRule {
  id: number;
  name: string;
  code: string;
  type: string;
  regime?: string | null;
  jurisdiction?: string | null;
  scope?: string | null;
  treatment?: string | null;
  rate: number;
  is_active: boolean;
  is_default: boolean;
  is_inclusive: boolean;
  effective_from?: string | null;
  effective_to?: string | null;
}

interface RulesResponse {
  data: {
    jurisdiction: string;
    date: string;
    country_defaults: { regime: string; currency: string | null; rates: Record<string, number> };
    configured_rules: EngineRule[];
  };
}

interface AdvisoryResponse {
  data: {
    advisory_status: string;
    basis: {
      rate: number;
      is_inclusive: boolean;
      tax_code: string;
      tax_name: string | null;
      jurisdiction: string;
      regime: string;
      scope: string;
      treatment: string;
      source: string;
      reason?: string;
    };
    calculation: { net: number; tax: number; gross: number };
    warnings: string[];
    disclaimer: string;
  };
}

const SOURCE_LABELS: Record<string, string> = {
  product_custom: 'Product override',
  product: 'Product tax',
  tenant_default: 'Default rule',
  localized_rule: 'Configured rule',
  none: 'No rule found',
};

const treatmentBadge = (treatment?: string | null) => {
  switch ((treatment || '').toLowerCase()) {
    case 'standard_rated':
      return <Badge variant="secondary" className="whitespace-nowrap bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300">Standard rated</Badge>;
    case 'zero_rated':
      return <Badge variant="secondary" className="whitespace-nowrap bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">Zero rated</Badge>;
    case 'exempt':
      return <Badge variant="secondary" className="whitespace-nowrap bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300">Exempt</Badge>;
    case 'out_of_scope':
      return <Badge variant="secondary" className="whitespace-nowrap bg-muted text-muted-foreground">Out of scope</Badge>;
    default:
      return <Badge variant="secondary" className="whitespace-nowrap">{treatment || '—'}</Badge>;
  }
};

export default function TaxEnginePage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const taxConfig = useTaxConfig();
  const updateSettings = useUpdateSettings();

  const [jurisdiction, setJurisdiction] = useState<string>(
    (taxConfig as { jurisdiction?: string | null }).jurisdiction?.toUpperCase() || 'SG',
  );

  const queryClient = useQueryClient();
  const [ruleDialogOpen, setRuleDialogOpen] = useState(false);
  const [editingRuleId, setEditingRuleId] = useState<number | null>(null);
  const [draft, setDraft] = useState({
    name: '', code: '', type: 'gst', jurisdiction: 'SG', regime: '', scope: 'output',
    treatment: 'standard_rated', rate: '', is_inclusive: false, is_default: false,
    is_active: true, effective_from: '', effective_to: '',
  });

  const invalidateRules = () => {
    queryClient.invalidateQueries({ queryKey: ['tax-engine-rules'] });
    queryClient.invalidateQueries({ queryKey: ['taxes'] });
    queryClient.invalidateQueries({ queryKey: ['settings'] });
  };

  const openCreateRule = () => {
    setEditingRuleId(null);
    setDraft({
      name: '', code: '', type: 'gst', jurisdiction, regime: '', scope: 'output',
      treatment: 'standard_rated', rate: '', is_inclusive: false, is_default: false,
      is_active: true, effective_from: '', effective_to: '',
    });
    setRuleDialogOpen(true);
  };

  const openEditRule = (rule: EngineRule) => {
    setEditingRuleId(rule.id);
    setDraft({
      name: rule.name,
      code: rule.code ?? '',
      type: rule.type || 'gst',
      jurisdiction: rule.jurisdiction ?? jurisdiction,
      regime: rule.regime ?? '',
      scope: rule.scope || 'both',
      treatment: rule.treatment || 'standard_rated',
      rate: String(Number(rule.rate)),
      is_inclusive: !!rule.is_inclusive,
      is_default: !!rule.is_default,
      is_active: !!rule.is_active,
      effective_from: rule.effective_from ? String(rule.effective_from).slice(0, 10) : '',
      effective_to: rule.effective_to ? String(rule.effective_to).slice(0, 10) : '',
    });
    setRuleDialogOpen(true);
  };

  const saveRule = useMutation({
    mutationFn: async () => {
      const payload = {
        name: draft.name,
        code: draft.code || null,
        type: draft.type,
        jurisdiction: draft.jurisdiction || null,
        regime: draft.regime ? draft.regime.toUpperCase() : null,
        scope: draft.scope,
        treatment: draft.treatment,
        rate: parseFloat(draft.rate || '0'),
        is_inclusive: draft.is_inclusive,
        is_default: draft.is_default,
        is_active: draft.is_active,
        effective_from: draft.effective_from || null,
        effective_to: draft.effective_to || null,
      };
      if (editingRuleId) {
        return (await api.put(`/taxes/${editingRuleId}`, payload)).data;
      }
      return (await api.post('/taxes', payload)).data;
    },
    onSuccess: () => {
      invalidateRules();
      setRuleDialogOpen(false);
      toast({ title: editingRuleId ? 'Tax rule updated' : 'Tax rule created' });
    },
    onError: (error: unknown) => {
      const resp = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response;
      const first = resp?.data?.errors ? Object.values(resp.data.errors)[0]?.[0] : undefined;
      toast({
        title: 'Could not save rule',
        description: first ?? resp?.data?.message ?? 'Check the highlighted fields.',
        variant: 'destructive',
      });
    },
  });

  const deleteRule = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/taxes/${id}`)).data,
    onSuccess: () => {
      invalidateRules();
      toast({ title: 'Tax rule deleted' });
    },
    onError: () => toast({ title: 'Could not delete rule', variant: 'destructive' }),
  });

  const { data: rulesData, isLoading: rulesLoading, refetch: refetchRules } = useQuery<RulesResponse>({
    queryKey: ['tax-engine-rules', jurisdiction],
    queryFn: async () => (await api.get('/accounting/tax-engine/rules', { params: { jurisdiction } })).data,
  });

  const [previewInput, setPreviewInput] = useState<{ enabled: boolean; req: Record<string, string | number | boolean> }>({
    enabled: false,
    req: {},
  });
  const [aiAdvice, setAiAdvice] = useState<{
    mode: string;
    advice: { summary?: string; risks?: string[]; actions?: string[] };
  } | null>(null);
  const [aiPending, setAiPending] = useState(false);
  const [previewInclusive, setPreviewInclusive] = useState(taxConfig.pricing_mode === 'inclusive');

  const {
    data: advisory,
    isFetching: previewFetching,
    error: advisoryError,
  } = useQuery<AdvisoryResponse>({
    queryKey: ['tax-advisory', previewInput.req],
    queryFn: async () => (await api.get('/accounting/tax-engine/advisory', { params: previewInput.req })).data,
    enabled: previewInput.enabled,
  });

  const runAiAdvisory = async () => {
    if (!previewInput.enabled || aiPending) {
      if (!previewInput.enabled) {
        toast({ title: 'Run the deterministic preview first', variant: 'destructive' });
      }
      return;
    }
    setAiPending(true);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 150_000);
    try {
      const response = await api.get('/accounting/tax-engine/ai-advisory', {
        params: previewInput.req,
        signal: controller.signal,
      });
      setAiAdvice({ mode: response.data?.data?.mode, advice: response.data?.data?.advice });
    } catch (error) {
      if ((error as { code?: string })?.code === 'ERR_CANCELED') {
        toast({
          title: 'AI review is taking too long',
          description: 'The local model answers in ~30–90s. Wait for the current review to finish before clicking again.',
          variant: 'destructive',
        });
      } else {
        const status = (error as { response?: { status?: number; data?: { message?: string } } })?.response?.status;
        toast({
          title: 'AI advisory unavailable',
          description: status === 403
            ? 'AI access is not enabled for this account.'
            : (error as { response?: { data?: { message?: string } } })?.response?.data?.message
              || 'The AI review endpoint rejected the request.',
          variant: 'destructive',
        });
      }
    } finally {
      clearTimeout(timer);
      setAiPending(false);
    }
  };

  const handlePreview = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const amount = parseFloat((form.get('amount') as string) || '0');
    if (!(amount > 0)) {
      toast({ title: 'Enter a preview amount greater than 0', variant: 'destructive' });
      return;
    }
    const taxType = (form.get('tax_type') as string) || 'auto';
    setPreviewInput({
      enabled: true,
      req: {
        jurisdiction,
        direction: (form.get('direction') as string) || 'output',
        tax_type: taxType === 'auto' ? '' : taxType,
        amount,
        inclusive: previewInclusive ? '1' : '0',
      },
    });
    setAiAdvice(null);
  };

  const saveJurisdiction = () => {
    updateSettings.mutate(
      { tax: { ...taxConfig, jurisdiction } },
      {
        onSuccess: () => toast({ title: `Home jurisdiction set to ${jurisdiction}` }),
        onError: () => {
          toast({
            title: 'Could not save jurisdiction',
            description: 'Engine view updated; saving needs settings.write.',
            variant: 'destructive',
          });
        },
      },
    );
  };

  const rules = rulesData?.data?.configured_rules ?? [];
  const countryDefaults = rulesData?.data?.country_defaults;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <HeaderActionButton
          variant="outline"
          icon="solar:alt-arrow-left-bold-duotone"
          onClick={() => navigate('/accounting')}
          className="mb-3"
        >
          Back to Accounting
        </HeaderActionButton>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Tax Engine</h1>
            <p className="mt-1 text-muted-foreground">
              Authoritative tax rules, resolution and advisory for sales, purchase and service tax
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <HeaderActionButton
              variant="outline"
              icon="solar:document-text-bold-duotone"
              onClick={() => navigate('/accounting/tax-returns')}
            >
              Statutory Returns
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:add-circle-bold-duotone"
              onClick={openCreateRule}
            >
              Add tax rule
            </HeaderActionButton>
          </div>
        </div>
      </div>

      {/* How the engine decides */}
      <div className="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50/70 p-4 dark:border-blue-900 dark:bg-blue-950/40">
        <Icon icon="solar:cpu-bolt-bold-duotone" className="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" />
        <p className="text-sm text-blue-900 dark:text-blue-200">
          <span className="font-semibold">How the engine decides tax:</span> product override → product-linked
          rule → default rule → jurisdiction rule effective on the transaction date →{' '}
          <strong>no tax with a warning</strong>. Country rates below are preview reference only — the
          engine never charges a rate that is not configured. Tax is{' '}
          {taxConfig.enabled ? 'enabled' : 'disabled'}; pricing mode{' '}
          {taxConfig.pricing_mode}; default rule:{' '}
          {taxConfig.default_tax_name && taxConfig.default_tax_name !== 'None'
            ? taxConfig.default_tax_name
            : 'not set'}.
        </p>
      </div>

      {/* Home jurisdiction */}
      <Card className="overflow-hidden rounded-xl">
        <CardHeader className="border-b">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
              <Icon icon="solar:globus-bold-duotone" className="h-5 w-5 text-primary" />
            </div>
            <div>
              <CardTitle className="text-base">Home jurisdiction</CardTitle>
              <p className="mt-1 text-sm text-muted-foreground">Drives which statutory rules and reference rates apply.</p>
            </div>
          </div>
        </CardHeader>
        <CardContent className="flex flex-wrap items-end gap-4 p-4">
          <div className="space-y-2">
            <Label>Jurisdiction</Label>
            <Select value={jurisdiction} onValueChange={setJurisdiction}>
              <SelectTrigger className="h-8 w-48 rounded-full text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="SG">SG — GST (IRAS)</SelectItem>
                <SelectItem value="MY">MY — SST (LHDN/RMCD)</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <HeaderActionButton
            variant="outline"
            icon="solar:diskette-bold-duotone"
            loading={updateSettings.isPending}
            onClick={saveJurisdiction}
            disabled={updateSettings.isPending}
          >
            Save as default
          </HeaderActionButton>
          <div className="flex-1" />
          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold-duotone"
            loading={rulesLoading}
            onClick={() => refetchRules()}
            disabled={rulesLoading}
          >
            Refresh
          </HeaderActionButton>
        </CardContent>
      </Card>

      {/* Country reference rates */}
      {countryDefaults && (
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                  <Icon icon="solar:eye-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <CardTitle className="text-base">Country reference rates for {jurisdiction}</CardTitle>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Regime: {countryDefaults.regime}
                    {countryDefaults.currency ? ` · Currency: ${countryDefaults.currency}` : ''}
                  </p>
                </div>
              </div>
              <Badge variant="outline" className="whitespace-nowrap border-transparent bg-muted font-normal text-muted-foreground">
                Preview only — never auto-applied
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-3 p-4">
            {Object.entries(countryDefaults.rates ?? {}).map(([label, rate]) => (
              <div key={label} className="flex items-center justify-between gap-6 rounded-xl border bg-card px-5 py-3">
                <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</span>
                <span className="text-xl font-bold tabular-nums">{rate}%</span>
              </div>
            ))}
            {Object.keys(countryDefaults.rates ?? {}).length === 0 && (
              <p className="text-sm text-muted-foreground">No reference rates published for {jurisdiction}.</p>
            )}
          </CardContent>
        </Card>
      )}

      {/* Configured rules */}
      <Card className="overflow-hidden rounded-xl">
        <CardHeader className="border-b">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                <Icon icon="solar:list-check-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <CardTitle className="text-base">Tax rules — managed here</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  All sales, purchase and service tax rules are managed in the Tax Engine; only
                  configured rules are ever charged. Product-specific custom overrides stay on the
                  product itself (Products → Settings → Tax).
                </p>
              </div>
            </div>
            <Badge variant="outline" className="border-transparent bg-muted font-normal text-muted-foreground">
              {rules.length} rule{rules.length === 1 ? '' : 's'}
            </Badge>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {rulesLoading ? (
            <div className="flex items-center justify-center py-16">
              <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
            </div>
          ) : rules.length === 0 ? (
            <div className="flex flex-col items-center gap-2 px-6 py-16 text-center text-muted-foreground">
              <Icon icon="solar:shield-keyhole-bold-duotone" className="h-10 w-10 opacity-30" />
              <p className="text-sm font-medium">No active rules for {jurisdiction}</p>
              <p className="max-w-md text-xs">
                Until a rule is configured, documents resolve to NO-TAX with a warning — the engine
                will not guess a rate.
              </p>
              <HeaderActionButton
                variant="outline"
                icon="solar:add-circle-bold-duotone"
                className="mt-2"
                onClick={openCreateRule}
              >
                Add tax rule
              </HeaderActionButton>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/50">
                    <TableHead>Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Regime</TableHead>
                    <TableHead>Scope</TableHead>
                    <TableHead>Treatment</TableHead>
                    <TableHead className="text-right">Rate</TableHead>
                    <TableHead>Pricing</TableHead>
                    <TableHead>Effective</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rules.map((rule) => (
                    <TableRow key={rule.id} className="hover:bg-muted/50">
                      <TableCell className="font-mono text-xs">{rule.code}</TableCell>
                      <TableCell className="font-medium">
                        <span className="flex items-center gap-2">
                          {rule.name}
                          {rule.is_default && <Badge variant="outline" className="font-normal">default</Badge>}
                        </span>
                      </TableCell>
                      <TableCell>{rule.regime || rule.type?.toUpperCase()}</TableCell>
                      <TableCell className="text-sm capitalize">{rule.scope || 'both'}</TableCell>
                      <TableCell>{treatmentBadge(rule.treatment)}</TableCell>
                      <TableCell className="text-right font-semibold tabular-nums">{Number(rule.rate)}%</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {rule.is_inclusive ? 'Inclusive' : 'Exclusive'}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        {rule.effective_from || '—'} {rule.effective_to ? `→ ${rule.effective_to}` : ''}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant="secondary"
                          className={rule.is_active
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                            : 'bg-muted text-muted-foreground'}
                        >
                          {rule.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Button variant="ghost" size="icon" className="h-7 w-7" aria-label="Edit rule" onClick={() => openEditRule(rule)}>
                            <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7 text-destructive hover:text-destructive"
                            aria-label="Delete rule"
                            onClick={() => { if (confirm(`Delete tax rule ${rule.code || rule.name}?`)) deleteRule.mutate(rule.id); }}
                          >
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
          <div className="border-t px-4 py-3">
            <button
              type="button"
              className="text-xs text-muted-foreground underline-offset-2 hover:underline"
              onClick={() => navigate('/accounting/configuration/taxes')}
            >
              Legacy tax rates (being retired) →
            </button>
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Preview */}
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                <Icon icon="solar:calculator-minimalistic-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <CardTitle className="text-base">Tax preview (runs the live engine)</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  Same resolution pipeline used by invoices, POS and web orders.
                </p>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <form onSubmit={handlePreview} className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="preview-amount">Amount</Label>
                  <Input id="preview-amount" name="amount" type="number" step="0.01" min="0" placeholder="1000.00" required />
                </div>
                <div className="space-y-2">
                  <Label>Direction</Label>
                  <Select name="direction" defaultValue="output">
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="output">Sales / Output</SelectItem>
                      <SelectItem value="input">Purchase / Input</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label>Tax type (optional)</Label>
                  <Select name="tax_type" defaultValue="auto">
                    <SelectTrigger><SelectValue placeholder="Auto" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="auto">Auto (by jurisdiction)</SelectItem>
                      <SelectItem value="sales">Sales tax</SelectItem>
                      <SelectItem value="service">Service tax</SelectItem>
                      <SelectItem value="gst">GST</SelectItem>
                      <SelectItem value="vat">VAT</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-center justify-between gap-3 self-end rounded-xl border bg-muted/30 px-3 py-2.5">
                  <Label htmlFor="preview-inclusive" className="cursor-pointer">Price includes tax</Label>
                  <Switch
                    id="preview-inclusive"
                    checked={previewInclusive}
                    onCheckedChange={setPreviewInclusive}
                  />
                </div>
              </div>
              <HeaderActionButton
                type="submit"
                icon="solar:calculator-minimalistic-bold-duotone"
                loading={previewFetching}
                disabled={previewFetching}
              >
                Calculate with engine
              </HeaderActionButton>
            </form>

            {advisoryError && (
              <div className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                <Icon icon="solar:danger-circle-bold-duotone" className="mt-0.5 h-5 w-5 shrink-0" />
                <span>
                  {(advisoryError as { response?: { data?: { message?: string } } })?.response?.data?.message
                    ?? 'Engine preview failed — this view requires the reports.view permission.'}
                </span>
              </div>
            )}

            {!previewInput.enabled && !advisoryError && (
              <p className="text-xs text-muted-foreground">
                Runs the same live resolution pipeline used when posting invoices, taking POS
                payments and confirming web orders. Use “AI Tax Advisory” on the right to have AI
                explain this result.
              </p>
            )}

            {advisory?.data && (
              <div className="space-y-4 border-t pt-4">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="outline" className="font-normal">
                    Source: {SOURCE_LABELS[advisory.data.basis.source] || advisory.data.basis.source}
                  </Badge>
                  <Badge variant="secondary" className="font-normal">{advisory.data.basis.tax_code}</Badge>
                  <Badge variant="secondary" className="tabular-nums">{Number(advisory.data.basis.rate)}%</Badge>
                  {treatmentBadge(advisory.data.basis.treatment)}
                </div>

                <div className="grid grid-cols-3 gap-3">
                  <div className="rounded-xl border bg-card px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Net</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums">{advisory.data.calculation.net.toFixed(2)}</p>
                  </div>
                  <div className="rounded-xl border bg-card px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Tax</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums">{advisory.data.calculation.tax.toFixed(2)}</p>
                  </div>
                  <div className="rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Gross</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums text-primary">{advisory.data.calculation.gross.toFixed(2)}</p>
                  </div>
                </div>

                {advisory.data.warnings.length > 0 && (
                  <div className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
                    {advisory.data.warnings.map((warning) => (
                      <p key={warning} className="flex gap-2 text-sm text-amber-900 dark:text-amber-200">
                        <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0" />
                        {warning}
                      </p>
                    ))}
                  </div>
                )}

                <p className="text-xs text-muted-foreground">
                  {advisory.data.disclaimer}
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* AI Tax Advisory */}
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-fuchsia-100 text-fuchsia-600 dark:bg-fuchsia-950/60 dark:text-fuchsia-400">
                <Icon icon="solar:magic-stick-3-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <CardTitle className="text-base">AI Tax Advisory</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  AI explains the engine's decision and flags gaps — using the local model.
                </p>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <ul className="space-y-3 text-sm text-muted-foreground">
              {[
                'Advisory only — AI never changes rules, posts entries, or files returns.',
                'The deterministic engine result stays the system of record; AI just reviews it.',
                'Every answer reminds you to verify current IRAS / LHDN-RMCD guidance before filing.',
                'If AI access is unavailable, you still get the engine warnings as a fallback.',
              ].map((item) => (
                <li key={item} className="flex gap-2">
                  <Icon icon="solar:shield-check-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-fuchsia-600 dark:text-fuchsia-400" />
                  <span>{item}</span>
                </li>
              ))}
            </ul>

            <HeaderActionButton
              variant="outline"
              icon="solar:magic-stick-3-bold-duotone"
              loading={aiPending}
              disabled={aiPending || !previewInput.enabled}
              onClick={runAiAdvisory}
            >
              {previewInput.enabled ? 'Review latest engine result' : 'Run a tax preview first'}
            </HeaderActionButton>

            {aiPending && (
              <p className="text-xs text-muted-foreground">
                The local model is writing the review — typically 30–90 seconds. Please wait;
                extra clicks queue behind it and make the whole app slower.
              </p>
            )}

            {aiAdvice && (
              <div className="space-y-3 rounded-xl border bg-muted/30 p-4">
                <div className="flex items-center gap-2">
                  <Badge variant="outline" className="font-normal">
                    {aiAdvice.mode === 'ai' ? 'AI advisory' : 'Deterministic fallback'}
                  </Badge>
                </div>

                {(aiAdvice.advice.summary ?? '') !== '' && (
                  <p className="text-sm leading-relaxed text-foreground">{aiAdvice.advice.summary}</p>
                )}

                {(aiAdvice.advice.risks ?? []).length > 0 && (
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                      Risks to watch
                    </p>
                    <ul className="space-y-1">
                      {(aiAdvice.advice.risks ?? []).map((risk) => (
                        <li key={risk} className="flex gap-2 text-sm text-muted-foreground">
                          <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                          <span>{risk}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {(aiAdvice.advice.actions ?? []).length > 0 && (
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                      Suggested review actions
                    </p>
                    <ol className="space-y-1">
                      {(aiAdvice.advice.actions ?? []).map((action, index) => (
                        <li key={action} className="flex gap-2 text-sm text-muted-foreground">
                          <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                            {index + 1}
                          </span>
                          <span>{action}</span>
                        </li>
                      ))}
                    </ol>
                  </div>
                )}

                <p className="border-t pt-2 text-xs text-muted-foreground">
                  Advisory only — the engine result is authoritative. Verify current IRAS /
                  LHDN-RMCD guidance before filing.
                </p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
      <Dialog open={ruleDialogOpen} onOpenChange={setRuleDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingRuleId ? 'Edit tax rule' : 'Add tax rule'}</DialogTitle>
            <DialogDescription>
              Rules are effective-dated: changing a rate for the future means adding a new rule with a
              start date, not editing history. The engine resolves by transaction date.
            </DialogDescription>
          </DialogHeader>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault();
              saveRule.mutate();
            }}
          >
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Name *</Label>
                <Input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} placeholder="e.g. SG GST Output 9%" required />
              </div>
              <div className="space-y-2">
                <Label>Code</Label>
                <Input value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value })} placeholder="SG-GST-9" />
              </div>
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label>Tax type</Label>
                <Select value={draft.type} onValueChange={(v) => setDraft({ ...draft, type: v })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="gst">GST</SelectItem>
                    <SelectItem value="vat">VAT</SelectItem>
                    <SelectItem value="sales">Sales tax</SelectItem>
                    <SelectItem value="service">Service tax</SelectItem>
                    <SelectItem value="withholding">Withholding</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Scope</Label>
                <Select value={draft.scope} onValueChange={(v) => setDraft({ ...draft, scope: v })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="output">Output (sales)</SelectItem>
                    <SelectItem value="input">Input (purchases)</SelectItem>
                    <SelectItem value="both">Both</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Treatment</Label>
                <Select value={draft.treatment} onValueChange={(v) => setDraft({ ...draft, treatment: v })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="standard_rated">Standard rated</SelectItem>
                    <SelectItem value="zero_rated">Zero rated</SelectItem>
                    <SelectItem value="exempt">Exempt</SelectItem>
                    <SelectItem value="out_of_scope">Out of scope</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label>Jurisdiction</Label>
                <Select value={draft.jurisdiction || 'none'} onValueChange={(v) => setDraft({ ...draft, jurisdiction: v === 'none' ? '' : v })}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="SG">SG</SelectItem>
                    <SelectItem value="MY">MY</SelectItem>
                    <SelectItem value="none">Global (any)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Regime</Label>
                <Input value={draft.regime} onChange={(e) => setDraft({ ...draft, regime: e.target.value })} placeholder="GST / SST (auto if blank)" />
              </div>
              <div className="space-y-2">
                <Label>Rate (%) *</Label>
                <Input type="number" step="0.01" min="0" max="100" value={draft.rate} onChange={(e) => setDraft({ ...draft, rate: e.target.value })} required />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Effective from</Label>
                <Input type="date" value={draft.effective_from} onChange={(e) => setDraft({ ...draft, effective_from: e.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Effective to</Label>
                <Input type="date" value={draft.effective_to} onChange={(e) => setDraft({ ...draft, effective_to: e.target.value })} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex items-center gap-2">
                <Switch id="rule-inclusive" checked={draft.is_inclusive} onCheckedChange={(v) => setDraft({ ...draft, is_inclusive: v })} />
                <Label htmlFor="rule-inclusive" className="cursor-pointer">Prices include this tax</Label>
              </div>
              <div className="flex items-center gap-2">
                <Switch id="rule-default" checked={draft.is_default} onCheckedChange={(v) => setDraft({ ...draft, is_default: v })} />
                <Label htmlFor="rule-default" className="cursor-pointer">Default rule</Label>
              </div>
              <div className="flex items-center gap-2">
                <Switch id="rule-active" checked={draft.is_active} onCheckedChange={(v) => setDraft({ ...draft, is_active: v })} />
                <Label htmlFor="rule-active" className="cursor-pointer">Active</Label>
              </div>
            </div>
            <DialogFooter>
              <HeaderActionButton
                type="button"
                variant="outline"
                icon="solar:close-circle-bold-duotone"
                onClick={() => setRuleDialogOpen(false)}
              >
                Cancel
              </HeaderActionButton>
              <HeaderActionButton
                type="submit"
                icon="solar:diskette-bold-duotone"
                loading={saveRule.isPending}
                disabled={saveRule.isPending}
              >
                {editingRuleId ? 'Update rule' : 'Create rule'}
              </HeaderActionButton>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}