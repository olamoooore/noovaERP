import React, { useState } from 'react';
import { format } from 'date-fns';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import reportApi from '@/lib/reportApi';

interface DateRangeSelectorProps {
  startDate: string;
  endDate: string;
  onStartDateChange: (date: string) => void;
  onEndDateChange: (date: string) => void;
  showPresets?: boolean;
}

export const DateRangeSelector: React.FC<DateRangeSelectorProps> = ({
  startDate,
  endDate,
  onStartDateChange,
  onEndDateChange,
  showPresets = true,
}) => {
  const [isOpen, setIsOpen] = useState(false);

  const presets = reportApi.getDateRangePresets();

  const handlePresetChange = (preset: string) => {
    const presetData = presets[preset as keyof typeof presets];
    if (presetData) {
      onStartDateChange(presetData.start_date);
      onEndDateChange(presetData.end_date);
    }
  };

  const formatDisplayDate = (dateStr: string) => {
    if (!dateStr) return '';
    try {
      return format(new Date(dateStr), 'MMM dd, yyyy');
    } catch {
      return dateStr;
    }
  };

  return (
    <div className="flex flex-col gap-4">
      {showPresets && (
        <div className="space-y-2">
          <Label>Quick Select</Label>
          <Select onValueChange={handlePresetChange}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Select a preset" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="today">Today</SelectItem>
              <SelectItem value="thisWeek">This Week</SelectItem>
              <SelectItem value="thisMonth">This Month</SelectItem>
              <SelectItem value="lastMonth">Last Month</SelectItem>
              <SelectItem value="thisQuarter">This Quarter</SelectItem>
              <SelectItem value="thisYear">This Year</SelectItem>
              <SelectItem value="lastYear">Last Year</SelectItem>
            </SelectContent>
          </Select>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="start-date">Start Date</Label>
          <Popover open={isOpen} onOpenChange={setIsOpen}>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                className="w-full justify-start text-left font-normal"
              >
                <Icon icon="solar:calendar-bold" className="mr-2 h-4 w-4" />
                {startDate ? formatDisplayDate(startDate) : 'Pick a date'}
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
              <div className="p-3">
                <Input
                  id="start-date"
                  type="date"
                  value={startDate}
                  onChange={(e) => {
                    onStartDateChange(e.target.value);
                    setIsOpen(false);
                  }}
                  className="w-full"
                />
              </div>
            </PopoverContent>
          </Popover>
        </div>

        <div className="space-y-2">
          <Label htmlFor="end-date">End Date</Label>
          <Popover>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                className="w-full justify-start text-left font-normal"
              >
                <Icon icon="solar:calendar-bold" className="mr-2 h-4 w-4" />
                {endDate ? formatDisplayDate(endDate) : 'Pick a date'}
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
              <div className="p-3">
                <Input
                  id="end-date"
                  type="date"
                  value={endDate}
                  onChange={(e) => onEndDateChange(e.target.value)}
                  className="w-full"
                />
              </div>
            </PopoverContent>
          </Popover>
        </div>
      </div>
    </div>
  );
};
