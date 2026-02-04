import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface AccountingPolicy {
  id?: number;
  depreciation_method: string;
  inventory_valuation_method: string;
  revenue_recognition_policy: string;
  expense_recognition_policy: string;
  bad_debt_policy: string;
  capitalization_threshold: number;
  notes?: string;
}

export default function AccountingPoliciesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('depreciation');

  // Fetch accounting policies
  const { data: policies, isLoading } = useQuery({
    queryKey: ['accounting-policies'],
    queryFn: async () => {
      const response = await api.get('/accounting/policies');
      return response.data.data as AccountingPolicy;
    },
  });

  // Mutation for updating policies
  const updatePoliciesMutation = useMutation({
    mutationFn: async (data: Partial<AccountingPolicy>) => {
      const response = await api.put('/accounting/policies', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-policies'] });
      toast({
        title: 'Success',
        description: 'Accounting policies updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update accounting policies',
        variant: 'destructive',
      });
    },
  });

  const handleDepreciationSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      depreciation_method: formData.get('depreciation_method'),
      capitalization_threshold: parseFloat(formData.get('capitalization_threshold') as string),
    };
    updatePoliciesMutation.mutate(data);
  };

  const handleInventorySubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      inventory_valuation_method: formData.get('inventory_valuation_method'),
    };
    updatePoliciesMutation.mutate(data);
  };

  const handleRevenueSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      revenue_recognition_policy: formData.get('revenue_recognition_policy'),
      expense_recognition_policy: formData.get('expense_recognition_policy'),
    };
    updatePoliciesMutation.mutate(data);
  };

  const handleOtherSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      bad_debt_policy: formData.get('bad_debt_policy'),
      notes: formData.get('notes'),
    };
    updatePoliciesMutation.mutate(data);
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
          <p className="text-muted-foreground mt-2">Loading policies...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Accounting Policies</h1>
          <p className="text-muted-foreground mt-1">
            Define depreciation and valuation methods
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="depreciation">Depreciation</TabsTrigger>
          <TabsTrigger value="inventory">Inventory Valuation</TabsTrigger>
          <TabsTrigger value="revenue">Revenue & Expenses</TabsTrigger>
          <TabsTrigger value="other">Other Policies</TabsTrigger>
        </TabsList>

        {/* Depreciation Tab */}
        <TabsContent value="depreciation" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Depreciation Policy</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Define how fixed assets are depreciated over their useful life.
            </p>
            <form onSubmit={handleDepreciationSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="depreciation_method">Default Depreciation Method</Label>
                <Select 
                  name="depreciation_method" 
                  defaultValue={policies?.depreciation_method || 'straight_line'}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="straight_line">Straight-Line Method</SelectItem>
                    <SelectItem value="declining_balance">Declining Balance Method</SelectItem>
                    <SelectItem value="units_of_production">Units of Production Method</SelectItem>
                    <SelectItem value="sum_of_years_digits">Sum of Years' Digits Method</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  This method will be used as the default for new fixed assets.
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="capitalization_threshold">Capitalization Threshold</Label>
                <Input
                  id="capitalization_threshold"
                  name="capitalization_threshold"
                  type="number"
                  step="0.01"
                  defaultValue={policies?.capitalization_threshold || 1000}
                  placeholder="1000.00"
                />
                <p className="text-sm text-muted-foreground">
                  Assets below this amount will be expensed immediately rather than capitalized.
                </p>
              </div>

              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 className="font-semibold text-blue-900 mb-2">Depreciation Methods Explained</h3>
                <ul className="space-y-2 text-sm text-blue-800">
                  <li>
                    <strong>Straight-Line:</strong> Equal depreciation expense each year. Most common and simple method.
                  </li>
                  <li>
                    <strong>Declining Balance:</strong> Higher depreciation in early years, lower in later years. Good for assets that lose value quickly.
                  </li>
                  <li>
                    <strong>Units of Production:</strong> Depreciation based on actual usage. Ideal for machinery and equipment.
                  </li>
                  <li>
                    <strong>Sum of Years' Digits:</strong> Accelerated depreciation method. Higher expense in early years.
                  </li>
                </ul>
              </div>

              <Button type="submit" disabled={updatePoliciesMutation.isPending}>
                {updatePoliciesMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Depreciation Policy
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Inventory Valuation Tab */}
        <TabsContent value="inventory" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Inventory Valuation Policy</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Define how inventory costs are calculated and valued.
            </p>
            <form onSubmit={handleInventorySubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="inventory_valuation_method">Inventory Valuation Method</Label>
                <Select 
                  name="inventory_valuation_method" 
                  defaultValue={policies?.inventory_valuation_method || 'fifo'}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="fifo">FIFO (First In, First Out)</SelectItem>
                    <SelectItem value="lifo">LIFO (Last In, First Out)</SelectItem>
                    <SelectItem value="weighted_average">Weighted Average Cost</SelectItem>
                    <SelectItem value="specific_identification">Specific Identification</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  This method determines how inventory costs flow through your financial statements.
                </p>
              </div>

              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 className="font-semibold text-blue-900 mb-2">Inventory Methods Explained</h3>
                <ul className="space-y-2 text-sm text-blue-800">
                  <li>
                    <strong>FIFO:</strong> Assumes oldest inventory is sold first. Results in lower COGS during inflation.
                  </li>
                  <li>
                    <strong>LIFO:</strong> Assumes newest inventory is sold first. Results in higher COGS during inflation.
                  </li>
                  <li>
                    <strong>Weighted Average:</strong> Uses average cost of all units. Smooths out price fluctuations.
                  </li>
                  <li>
                    <strong>Specific Identification:</strong> Tracks actual cost of each item. Used for unique, high-value items.
                  </li>
                </ul>
              </div>

              <Button type="submit" disabled={updatePoliciesMutation.isPending}>
                {updatePoliciesMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Inventory Policy
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Revenue & Expenses Tab */}
        <TabsContent value="revenue" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Revenue & Expense Recognition</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Define when revenues and expenses are recognized in your financial statements.
            </p>
            <form onSubmit={handleRevenueSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="revenue_recognition_policy">Revenue Recognition Policy</Label>
                <Select 
                  name="revenue_recognition_policy" 
                  defaultValue={policies?.revenue_recognition_policy || 'accrual'}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="accrual">Accrual Basis</SelectItem>
                    <SelectItem value="cash">Cash Basis</SelectItem>
                    <SelectItem value="percentage_completion">Percentage of Completion</SelectItem>
                    <SelectItem value="completed_contract">Completed Contract</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  Determines when revenue is recognized in your financial statements.
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="expense_recognition_policy">Expense Recognition Policy</Label>
                <Select 
                  name="expense_recognition_policy" 
                  defaultValue={policies?.expense_recognition_policy || 'matching'}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="matching">Matching Principle</SelectItem>
                    <SelectItem value="immediate">Immediate Recognition</SelectItem>
                    <SelectItem value="systematic">Systematic Allocation</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  Determines when expenses are recognized in your financial statements.
                </p>
              </div>

              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 className="font-semibold text-blue-900 mb-2">Recognition Principles</h3>
                <ul className="space-y-2 text-sm text-blue-800">
                  <li>
                    <strong>Accrual Basis:</strong> Revenue recognized when earned, regardless of payment. Most common for businesses.
                  </li>
                  <li>
                    <strong>Cash Basis:</strong> Revenue recognized when cash is received. Simpler but less accurate.
                  </li>
                  <li>
                    <strong>Matching Principle:</strong> Expenses matched to related revenues in the same period.
                  </li>
                </ul>
              </div>

              <Button type="submit" disabled={updatePoliciesMutation.isPending}>
                {updatePoliciesMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Recognition Policies
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Other Policies Tab */}
        <TabsContent value="other" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Other Accounting Policies</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Define additional accounting policies and procedures.
            </p>
            <form onSubmit={handleOtherSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="bad_debt_policy">Bad Debt Policy</Label>
                <Select 
                  name="bad_debt_policy" 
                  defaultValue={policies?.bad_debt_policy || 'allowance_method'}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="allowance_method">Allowance Method</SelectItem>
                    <SelectItem value="direct_writeoff">Direct Write-off Method</SelectItem>
                    <SelectItem value="aging_analysis">Aging Analysis</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  Method for estimating and recording uncollectible accounts receivable.
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="notes">Additional Policy Notes</Label>
                <Textarea
                  id="notes"
                  name="notes"
                  defaultValue={policies?.notes || ''}
                  placeholder="Enter any additional accounting policies, procedures, or notes..."
                  rows={6}
                />
                <p className="text-sm text-muted-foreground">
                  Document any other accounting policies specific to your organization.
                </p>
              </div>

              <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <div className="flex items-start gap-2">
                  <Icon icon="solar:info-circle-bold" className="w-5 h-5 text-amber-600 mt-0.5" />
                  <div>
                    <h3 className="font-semibold text-amber-900 mb-1">Important Note</h3>
                    <p className="text-sm text-amber-800">
                      Changes to accounting policies may require restatement of prior period financial statements. 
                      Consult with your accountant or auditor before making significant policy changes.
                    </p>
                  </div>
                </div>
              </div>

              <Button type="submit" disabled={updatePoliciesMutation.isPending}>
                {updatePoliciesMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Other Policies
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
