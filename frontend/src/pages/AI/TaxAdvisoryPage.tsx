import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
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
import { Badge } from '@/components/ui/badge';
import { SOURCE_LABELS, type StructuredAdvice } from '@/lib/taxDisplay';
import { AiAdviceResult, TreatmentBadge } from '@/components/tax/TaxDisplay';
import { useToast } from '@/hooks/use-toast';
import { useTaxConfig } from '@/hooks/queries/useSettings';
import api from '@/lib/api';

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

interface AiResponse {
  data: {
    mode: string;
    review: AdvisoryResponse['data'];
    advice: StructuredAdvice;
  };
}

export default function AiTaxAdvisoryPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const taxConfig = useTaxConfig();

  const jurisdiction = ((taxConfig as { jurisdiction?: string | null }).jurisdiction ?? 'SG').toUpperCase();
  const [direction, setDirection] = useState('output');
  const [taxType, setTaxType] = useState('auto');
  const [amount, setAmount] = useState('');
  const [inclusive, setInclusive] = useState(taxConfig.pricing_mode === 'inclusive');
  const [engine, setEngine] = useState<AdvisoryResponse['data'] | null>(null);
  const [advice, setAdvice] = useState<AiResponse['data'] | null>(null);
  const [advising, setAdvising] = useState(false);

  const enginePreview = useQuery<AdvisoryResponse>({
    queryKey: ['ai-suite-engine', jurisdiction, direction, taxType, amount, inclusive],
    queryFn: async () =>
      (await api.get('/accounting/tax-engine/advisory', {
        params: {
          jurisdiction,
          direction,
          tax_type: taxType === 'auto' ? '' : taxType,
          amount,
          inclusive: inclusive ? '1' : '0',
        },
      })).data,
    enabled: false,
  });

  const runEngine = async () => {
    if (!(parseFloat(amount) > 0)) {
      toast({ title: 'Enter an amount greater than 0', variant: 'destructive' });
      return;
    }
    setAdvice(null);
    try {
      const data = await enginePreview.refetch();
      setEngine(data.data?.data ?? null);
      if (!data.data) {
        throw new Error((data.error as Error)?.message ?? 'Engine review failed');
      }
    } catch {
      toast({
        title: 'Engine review failed',
        description: 'Check the message or your reports.view permission.',
        variant: 'destructive',
      });
    }
  };

  const aiReview = useMutation<AiResponse, Error>({
    mutationFn: async () =>
      (await api.get('/accounting/tax-engine/ai-advisory', {
        params: {
          jurisdiction,
          direction,
          tax_type: taxType === 'auto' ? '' : taxType,
          amount,
          inclusive: inclusive ? '1' : '0',
        },
        timeout: 150_000,
      })).data,
  });

  const runAi = () => {
    if (!engine) {
      toast({ title: 'Run the deterministic engine review first', variant: 'destructive' });
      return;
    }
    setAdvising(true);
    aiReview.mutate(undefined, {
      onSuccess: (response) => setAdvice(response.data),
      onError: (error) =>
        toast({
          title: 'AI advisory unavailable',
          description: (error as Error & { response?: { status?: number } })?.response?.status === 403
            ? 'AI access is not enabled for this account.'
            : 'The local model did not answer. The engine result above remains authoritative.',
          variant: 'destructive',
        }),
      onSettled: () => setAdvising(false),
    });
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-3xl font-bold tracking-tight">
            <Icon icon="solar:magic-stick-3-bold-duotone" className="h-7 w-7 text-fuchsia-600" />
            AI Tax Advisory
          </h1>
          <p className="mt-1 max-w-2xl text-muted-foreground">
            Deterministic-first tax review: the engine answers first, the local model then
            explains, flags gaps and proposes review actions. It never changes rules, posts
            entries or files returns.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <HeaderActionButton variant="outline" icon="solar:cpu-bold-duotone" onClick={() => navigate('/accounting/tax-engine')}>
            Tax Engine
          </HeaderActionButton>
          <HeaderActionButton variant="outline" icon="solar:chart-square-bold-duotone" onClick={() => navigate('/accounting/tax-report')}>
            Tax Report
          </HeaderActionButton>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <CardTitle className="text-base">1 — Engine review (system of record)</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Direction</Label>
                <Select value={direction} onValueChange={setDirection}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="output">Sales / Output</SelectItem>
                    <SelectItem value="input">Purchase / Input</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Tax type (optional)</Label>
                <Select value={taxType} onValueChange={setTaxType}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="auto">Auto (by jurisdiction)</SelectItem>
                    <SelectItem value="sales">Sales tax</SelectItem>
                    <SelectItem value="service">Service tax</SelectItem>
                    <SelectItem value="gst">GST</SelectItem>
                    <SelectItem value="vat">VAT</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="ai-amount">Amount ({jurisdiction})</Label>
                <Input id="ai-amount" type="number" min="0" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="1000.00" />
              </div>
              <div className="flex items-center justify-between gap-3 self-end rounded-xl border bg-muted/30 px-3 py-2.5">
                <Label htmlFor="ai-inclusive" className="cursor-pointer">Price includes tax</Label>
                <Switch id="ai-inclusive" checked={inclusive} onCheckedChange={setInclusive} />
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              Home jurisdiction: <span className="font-medium">{jurisdiction}</span> — change it in
              Tax Engine settings. The review runs the same pipeline used by invoices, POS and web orders.
            </p>
            <HeaderActionButton icon="solar:calculator-minimalistic-bold-duotone" loading={enginePreview.isFetching} disabled={enginePreview.isFetching} onClick={runEngine}>
              Run engine review
            </HeaderActionButton>

            {enginePreview.isError && (
              <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                Engine review failed — this view requires the reports.view permission.
              </div>
            )}

            {engine && (
              <div className="space-y-3 border-t pt-4">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="outline" className="font-normal">Source: {SOURCE_LABELS[engine.basis.source] || engine.basis.source}</Badge>
                  <Badge variant="secondary" className="font-normal">{engine.basis.tax_code}</Badge>
                  <Badge variant="secondary" className="tabular-nums">{Number(engine.basis.rate)}%</Badge>
                  {<TreatmentBadge treatment={engine.basis.treatment} />}
                </div>
                <div className="grid grid-cols-3 gap-3">
                  <div className="rounded-xl border bg-card px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Net</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums">{engine.calculation.net.toFixed(2)}</p>
                  </div>
                  <div className="rounded-xl border bg-card px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Tax</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums">{engine.calculation.tax.toFixed(2)}</p>
                  </div>
                  <div className="rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-center">
                    <p className="text-xs text-muted-foreground">Gross</p>
                    <p className="mt-0.5 text-lg font-bold tabular-nums text-primary">{engine.calculation.gross.toFixed(2)}</p>
                  </div>
                </div>
                {engine.warnings.length > 0 && (
                  <div className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40">
                    {engine.warnings.map((warning) => (
                      <p key={warning} className="flex gap-2 text-sm text-amber-900 dark:text-amber-200">
                        <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0" />
                        {warning}
                      </p>
                    ))}
                  </div>
                )}
                <Button onClick={runAi} disabled={advising || aiReview.isPending}>
                  <Icon icon="solar:magic-stick-3-linear" className="mr-2 h-4 w-4" />
                  {advising || aiReview.isPending ? 'Model is reviewing (30–90s)…' : '2 — Ask AI to review this result'}
                </Button>
                <p className="text-xs text-muted-foreground">{engine.disclaimer}</p>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <CardTitle className="text-base">About this advisor</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <ul className="space-y-3 text-sm text-muted-foreground">
              {[
                'Advisory only — AI never changes rules, posts entries, or files returns.',
                'The deterministic engine result (left) stays the system of record; AI just reviews it.',
                'Every answer reminds you to verify current IRAS / LHDN-RMCD guidance before filing.',
                'If AI access is unavailable, you still get the engine warnings as a fallback.',
                'Responses are normalized to summary / risks / actions — raw model text never reaches this screen.',
              ].map((item) => (
                <li key={item} className="flex gap-2">
                  <Icon icon="solar:shield-check-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-fuchsia-600 dark:text-fuchsia-400" />
                  <span>{item}</span>
                </li>
              ))}
            </ul>
            {advice && <AiAdviceResult mode={advice.mode} advice={advice.advice} />}
            {!advice && (
              <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                The AI review of your engine result will appear here.
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
