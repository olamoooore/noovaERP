import React, { useEffect, useState } from 'react';
import { Icon } from '@iconify/react';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import api from '@/lib/api';

interface Account {
  id: number;
  code: string;
  name: string;
  account_type: string;
}

interface AccountSelectorProps {
  value?: number;
  onChange: (accountId: number | undefined) => void;
  accountType?: string;
  label?: string;
  placeholder?: string;
  allowClear?: boolean;
}

export const AccountSelector: React.FC<AccountSelectorProps> = ({
  value,
  onChange,
  accountType,
  label = 'Account',
  placeholder = 'Select an account',
  allowClear = true,
}) => {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    fetchAccounts();
  }, [accountType]);

  const fetchAccounts = async () => {
    setLoading(true);
    try {
      const params: any = {};
      if (accountType) {
        params.account_type = accountType;
      }

      const response = await api.get('/chart-of-accounts', { params });
      setAccounts(response.data.data || []);
    } catch (error) {
      console.error('Failed to fetch accounts:', error);
      setAccounts([]);
    } finally {
      setLoading(false);
    }
  };

  const filteredAccounts = accounts.filter(
    (account) =>
      account.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      account.code.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const handleValueChange = (val: string) => {
    if (val === 'clear') {
      onChange(undefined);
    } else {
      onChange(Number(val));
    }
  };

  return (
    <div className="space-y-2">
      <Label>{label}</Label>
      <Select
        value={value?.toString()}
        onValueChange={handleValueChange}
        disabled={loading}
      >
        <SelectTrigger className="w-full">
          <SelectValue placeholder={loading ? 'Loading...' : placeholder} />
        </SelectTrigger>
        <SelectContent>
          <div className="p-2">
            <Input
              placeholder="Search accounts..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="mb-2"
              onClick={(e) => e.stopPropagation()}
            />
          </div>
          {allowClear && value && (
            <>
              <SelectItem value="clear">
                <span className="flex items-center gap-2">
                  <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                  Clear Selection
                </span>
              </SelectItem>
            </>
          )}
          {filteredAccounts.length === 0 ? (
            <div className="p-2 text-sm text-muted-foreground text-center">
              No accounts found
            </div>
          ) : (
            filteredAccounts.map((account) => (
              <SelectItem key={account.id} value={account.id.toString()}>
                <span className="flex items-center gap-2">
                  <span className="font-mono text-xs text-muted-foreground">
                    {account.code}
                  </span>
                  <span>{account.name}</span>
                </span>
              </SelectItem>
            ))
          )}
        </SelectContent>
      </Select>
    </div>
  );
};
