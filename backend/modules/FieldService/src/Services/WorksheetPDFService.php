<?php

namespace Modules\FieldService\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorksheetResponse;

class WorksheetPDFService
{
    /**
     * Generate a PDF for the completed worksheet and store it.
     *
     * Returns the storage-relative path (e.g. "field-service/worksheets/FS-2026-00001.pdf").
     */
    public function generate(WorkOrder $workOrder, WorksheetResponse $worksheetResponse): string
    {
        $workOrder->load(['type', 'customer', 'leadTechnician.user', 'technicians.user']);
        $worksheetResponse->load('template');

        $template = $worksheetResponse->template;
        $fields   = collect($template->fields ?? [])->sortBy('sort_order');
        $responses = $worksheetResponse->responses ?? [];

        // Build data for the view
        $rows = $fields->map(function (array $field) use ($responses) {
            $raw   = $responses[$field['id']] ?? null;
            $value = $this->formatFieldValue($field, $raw);
            return [
                'label'     => $field['label'],
                'type'      => $field['type'],
                'value'     => $value,
                'raw'       => $raw,
                'is_photo'  => $field['type'] === 'photo',
                'is_sig'    => $field['type'] === 'signature',
            ];
        })->values()->all();

        $pdf = Pdf::loadView('field-service.worksheet-report', [
            'workOrder'    => $workOrder,
            'worksheet'    => $worksheetResponse,
            'templateName' => $template->name,
            'rows'         => $rows,
        ])->setPaper('a4', 'portrait');

        $directory = 'field-service/worksheets';
        Storage::makeDirectory($directory);

        $filename  = $directory . '/WS-' . $workOrder->reference . '-' . $workOrder->id . '.pdf';
        Storage::put($filename, $pdf->output());

        return $filename;
    }

    private function formatFieldValue(array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field['type']) {
            'checkbox' => $value ? 'Yes' : 'No',
            'photo'    => '[Photo attached]',
            'signature'=> '[Signature captured]',
            default    => (string) $value,
        };
    }
}
