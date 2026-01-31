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

interface Department {
  id: number;
  name: string;
  code?: string;
}

interface DepartmentSelectorProps {
  value?: number;
  onChange: (departmentId: number | undefined) => void;
  label?: string;
  placeholder?: string;
  allowClear?: boolean;
}

export const DepartmentSelector: React.FC<DepartmentSelectorProps> = ({
  value,
  onChange,
  label = 'Department',
  placeholder = 'Select a department',
  allowClear = true,
}) => {
  const [departments, setDepartments] = useState<Department[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    fetchDepartments();
  }, []);

  const fetchDepartments = async () => {
    setLoading(true);
    try {
      const response = await api.get('/departments');
      setDepartments(response.data.data || []);
    } catch (error) {
      // Silently fail - departments endpoint may not be available
      // This is a non-critical feature
      console.warn('Departments endpoint not available');
      setDepartments([]);
    } finally {
      setLoading(false);
    }
  };

  const filteredDepartments = departments.filter((department) =>
    department.name.toLowerCase().includes(searchTerm.toLowerCase())
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
              placeholder="Search departments..."
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
          {filteredDepartments.length === 0 ? (
            <div className="p-2 text-sm text-muted-foreground text-center">
              No departments found
            </div>
          ) : (
            filteredDepartments.map((department) => (
              <SelectItem key={department.id} value={department.id.toString()}>
                {department.name}
              </SelectItem>
            ))
          )}
        </SelectContent>
      </Select>
    </div>
  );
};
