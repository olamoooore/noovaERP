import React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { useNavigate } from 'react-router-dom';

interface PlaceholderPageProps {
  title: string;
  description: string;
  icon: string;
  comingSoon?: boolean;
}

export const PlaceholderPage: React.FC<PlaceholderPageProps> = ({
  title,
  description,
  icon,
  comingSoon = true,
}) => {
  const navigate = useNavigate();

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{title}</h1>
          <p className="text-muted-foreground mt-1">{description}</p>
        </div>
        <Button variant="outline" onClick={() => navigate('/accounting/dashboard')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Coming Soon Card */}
      <Card>
        <CardContent className="pt-12 pb-12">
          <div className="text-center">
            <Icon icon={icon} className="h-24 w-24 mx-auto mb-6 text-primary opacity-50" />
            {comingSoon ? (
              <>
                <h2 className="text-2xl font-bold mb-2">Feature Available</h2>
                <p className="text-muted-foreground mb-6">
                  This module is live. Navigate to it from the accounting dashboard.
                </p>
              </>
            ) : (
              <>
                <h2 className="text-2xl font-bold mb-2">Page Not Available</h2>
                <p className="text-muted-foreground mb-6">
                  This page is not yet implemented.
                </p>
              </>
            )}
            <div className="flex gap-3 justify-center">
              <Button onClick={() => navigate('/accounting/dashboard')}>
                <Icon icon="solar:home-2-bold-duotone" className="h-4 w-4 mr-2" />
                Go to Accounting Dashboard
              </Button>
              <Button variant="outline" onClick={() => navigate('/')}>
                <Icon icon="solar:home-bold-duotone" className="h-4 w-4 mr-2" />
                Go to Main Dashboard
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Info Card */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-blue-500" />
            Development Roadmap
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-3">
            <div className="flex items-start gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
              <div>
                <p className="font-medium">Phase 1: Navigation Structure</p>
                <p className="text-sm text-muted-foreground">Completed - Odoo-style navigation implemented</p>
              </div>
            </div>
            <div className="flex items-start gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
              <div>
                <p className="font-medium">Phase 2: Customer & Vendor Sections</p>
                <p className="text-sm text-muted-foreground">Completed - Bills, Payments, Reports, Statements</p>
              </div>
            </div>
            <div className="flex items-start gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
              <div>
                <p className="font-medium">Phase 3: Accounting Section</p>
                <p className="text-sm text-muted-foreground">Completed - Chart of Accounts, Journal Entries, Journals, Reconciliation</p>
              </div>
            </div>
            <div className="flex items-start gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
              <div>
                <p className="font-medium">Phase 4: Reporting Section</p>
                <p className="text-sm text-muted-foreground">Completed - Balance Sheet, P&L, Cash Flow, etc.</p>
              </div>
            </div>
            <div className="flex items-start gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
              <div>
                <p className="font-medium">Phase 5: Configuration Section</p>
                <p className="text-sm text-muted-foreground">Completed - Company Settings, Policies, Tax Configuration</p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default PlaceholderPage;
