import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/hooks/use-toast';
import { accountingApi } from '@/lib/api';

interface ImportResult {
  created: number;
  updated: number;
  errors: Array<{ row: number; message: string }>;
}

const TEMPLATE_COLUMNS = [
  { name: 'code', required: false, description: 'Vendor code. Leave empty to generate the next VEN code automatically.' },
  { name: 'name', required: true, description: 'Vendor name. Required.' },
  { name: 'email', required: false, description: 'Vendor email address.' },
  { name: 'phone', required: false, description: 'Vendor phone number.' },
  { name: 'company', required: false, description: 'Registered or trading company name.' },
  { name: 'address', required: false, description: 'Legacy general address for compatibility.' },
  { name: 'billing_address', required: false, description: 'Billing address in full-text format.' },
  { name: 'shipping_address', required: false, description: 'Shipping address in full-text format.' },
  { name: 'contact_person', required: false, description: 'Primary vendor contact person.' },
];

export function VendorImportPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [dragActive, setDragActive] = useState(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const data = new FormData();
      data.append('file', file);
      const response = await accountingApi.post('/accounting/vendors/import', data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return response.data as ImportResult;
    },
    onSuccess: (data) => {
      setResult(data);
      queryClient.invalidateQueries({ queryKey: ['vendors'] });
      toast({
        title: 'Vendor import complete',
        description: `${data.created} created, ${data.updated} updated, ${data.errors.length} errors.`,
        variant: data.errors.length > 0 && data.created + data.updated === 0 ? 'destructive' : 'default',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Import failed',
        description: error.response?.data?.message || 'An error occurred while importing vendors.',
        variant: 'destructive',
      });
    },
  });

  const reset = () => {
    setSelectedFile(null);
    setResult(null);
  };

  const selectFile = (file?: File) => {
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.csv')) {
      toast({ title: 'Invalid file type', description: 'Please select a CSV file.', variant: 'destructive' });
      return;
    }
    setSelectedFile(file);
    setResult(null);
  };

  const handleDrag = useCallback((event: React.DragEvent) => {
    event.preventDefault();
    event.stopPropagation();
    setDragActive(event.type === 'dragenter' || event.type === 'dragover');
  }, []);

  const handleDrop = useCallback((event: React.DragEvent) => {
    event.preventDefault();
    event.stopPropagation();
    setDragActive(false);
    selectFile(event.dataTransfer.files?.[0]);
  }, []);

  const downloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',');
    const sample = ',Example Vendor,vendor@example.com,+60123456789,Example Sdn Bhd,,123 Billing Street,456 Shipping Street,Jane Doe';
    const url = URL.createObjectURL(new Blob([`${headers}\n${sample}`], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'vendor_import_template.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it to import vendors.' });
  };

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div>
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/accounting/vendors')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Vendors</h1>
          <p className="text-muted-foreground">Bulk import vendors from a CSV file</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 1: Download Template
              </CardTitle>
              <CardDescription>Start with the CSV template containing the supported Vendor fields.</CardDescription>
            </CardHeader>
            <CardContent>
              <Button onClick={downloadTemplate} className="w-full" variant="outline">
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Download Import Template
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />
                Template Fields
              </CardTitle>
              <CardDescription>Required fields are marked with an asterisk (*).</CardDescription>
            </CardHeader>
            <CardContent className="max-h-[400px] overflow-y-auto">
              <Table>
                <TableHeader><TableRow><TableHead className="w-[150px]">Field</TableHead><TableHead>Description</TableHead></TableRow></TableHeader>
                <TableBody>
                  {TEMPLATE_COLUMNS.map((column) => (
                    <TableRow key={column.name}>
                      <TableCell className="font-medium">
                        {column.name}{column.required && <span className="text-destructive ml-1">*</span>}
                      </TableCell>
                      <TableCell className="text-muted-foreground text-sm">{column.description}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />
                {result ? 'Import Complete' : 'Step 2: Upload & Import'}
              </CardTitle>
              <CardDescription>{result ? 'Review the import results below.' : 'Fill in the template and upload the CSV file.'}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {!result && (
                <div
                  className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'} ${selectedFile ? 'bg-muted/50' : ''}`}
                  onDragEnter={handleDrag}
                  onDragLeave={handleDrag}
                  onDragOver={handleDrag}
                  onDrop={handleDrop}
                >
                  {selectedFile ? (
                    <div className="space-y-3">
                      <Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" />
                      <div><p className="font-medium">{selectedFile.name}</p><p className="text-sm text-muted-foreground">{(selectedFile.size / 1024).toFixed(2)} KB</p></div>
                      <Button variant="ghost" size="sm" onClick={reset}><Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />Remove</Button>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      <Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" />
                      <div><p className="font-medium">Drag and drop your CSV file here</p><p className="text-sm text-muted-foreground">or click below to browse</p></div>
                      <Button variant="outline" size="sm" asChild>
                        <label className="cursor-pointer"><Icon icon="solar:folder-open-bold" className="mr-2 h-4 w-4" />Browse Files<input type="file" accept=".csv,text/csv" onChange={(event) => selectFile(event.target.files?.[0])} className="hidden" /></label>
                      </Button>
                    </div>
                  )}
                </div>
              )}

              {selectedFile && !result && (
                <Button className="w-full" disabled={importMutation.isPending} onClick={() => importMutation.mutate(selectedFile)}>
                  {importMutation.isPending ? <><Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />Importing...</> : <><Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />Import Vendors</>}
                </Button>
              )}
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}

              {result && (
                <div className="space-y-4">
                  <Alert variant={result.errors.length > 0 ? 'destructive' : 'default'}>
                    <Icon icon={result.errors.length > 0 ? 'solar:danger-triangle-bold' : 'solar:check-circle-bold'} className="h-4 w-4" />
                    <AlertTitle>Import Complete</AlertTitle>
                    <AlertDescription>{result.created} vendors created, {result.updated} updated, and {result.errors.length} rows failed.</AlertDescription>
                  </Alert>
                  <div className="grid grid-cols-3 gap-4">
                    <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg"><div className="text-2xl font-bold text-green-600">{result.created}</div><div className="text-sm text-muted-foreground">Created</div></div>
                    <div className="text-center p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg"><div className="text-2xl font-bold text-blue-600">{result.updated}</div><div className="text-sm text-muted-foreground">Updated</div></div>
                    <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg"><div className="text-2xl font-bold text-red-600">{result.errors.length}</div><div className="text-sm text-muted-foreground">Errors</div></div>
                  </div>
                  {result.errors.length > 0 && <div className="max-h-[180px] overflow-y-auto rounded-md border p-3 text-sm text-destructive">{result.errors.map((error, index) => <p key={`${error.row}-${index}`}>Row {error.row}: {error.message}</p>)}</div>}
                  <div className="flex gap-3">
                    <Button variant="outline" onClick={reset} className="flex-1"><Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />Import Another File</Button>
                    <Button onClick={() => navigate('/accounting/vendors')} className="flex-1"><Icon icon="solar:users-group-rounded-bold" className="mr-2 h-4 w-4" />View Vendors</Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
