import React from 'react';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { DepositAccountOption } from '@/lib/paymentDepositAccount';

/**
 * #485 — "Deposit to" bank/cash journal picker shared by every payment form.
 *
 * Loads the tenant's configured bank accounts; the PARENT owns the value,
 * per-method defaulting and the per-user memory (see
 * @/lib/paymentDepositAccount). Empty list renders an explanatory hint —
 * the backend then falls back to the default `bank` mapping.
 */
const DepositAccountSelect: React.FC<{
  id: string;
  value: string;
  onChange: (value: string) => void;
  options: DepositAccountOption[] | undefined;
  isLoading?: boolean;
}> = ({ id, value, onChange, options, isLoading }) => {
  const list = options ?? [];

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>Deposit To</Label>
      <Select value={value || undefined} onValueChange={onChange}>
        <SelectTrigger id={id} aria-label="Deposit to account">
          <SelectValue
            placeholder={
              isLoading
                ? 'Loading accounts…'
                : list.length
                  ? 'Select receiving account…'
                  : 'No bank accounts configured — default bank mapping will be used'
            }
          />
        </SelectTrigger>
        <SelectContent>
          {list.map((option) => (
            <SelectItem key={option.id} value={String(option.id)}>
              {option.account_name}
              {option.gl_account_code ? ` · ${option.gl_account_code}` : ''}
              {option.bank_name ? ` (${option.bank_name})` : ''}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {value ? null : (
        <p className="text-xs text-muted-foreground">
          Leave empty to post to the default bank mapping.
        </p>
      )}
    </div>
  );
};

export default DepositAccountSelect;
