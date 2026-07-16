<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AnalyticsReportRequest
 *
 * Validates analytics report generation requests with proper filtering
 * and date range validation for business intelligence queries.
 */
class AnalyticsReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admin users can generate analytics reports
        return $this->user() && $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'date_from' => [
                'sometimes',
                'date',
                'before_or_equal:date_to',
            ],
            'date_to' => [
                'sometimes',
                'date',
                'after_or_equal:date_from',
                'before_or_equal:today',
            ],
            'event_ids' => [
                'sometimes',
                'array',
                'max:50', // Limit to prevent performance issues
            ],
            'event_ids.*' => [
                'integer',
                'exists:events,id',
            ],
            'include_cancelled' => [
                'sometimes',
                'boolean',
            ],
            'group_by' => [
                'sometimes',
                Rule::in(['event', 'date', 'ticket_type', 'customer_segment']),
            ],
            'metrics' => [
                'sometimes',
                'array',
            ],
            'metrics.*' => [
                Rule::in([
                    'revenue',
                    'tickets_sold',
                    'capacity_utilization',
                    'no_show_rate',
                    'customer_count',
                    'repeat_customers',
                    'sales_velocity',
                ]),
            ],
            'format' => [
                'sometimes',
                Rule::in(['json', 'csv', 'excel']),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'date_from.before_or_equal' => 'Start date must be before or equal to end date.',
            'date_to.after_or_equal' => 'End date must be after or equal to start date.',
            'date_to.before_or_equal' => 'End date cannot be in the future.',
            'event_ids.max' => 'Cannot analyze more than 50 events at once.',
            'event_ids.*.exists' => 'One or more selected events do not exist.',
            'group_by.in' => 'Invalid grouping option selected.',
            'metrics.*.in' => 'Invalid metric selected.',
            'format.in' => 'Invalid export format selected.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // If date range is specified, ensure it's not too large
            if ($this->has('date_from') && $this->has('date_to')) {
                $dateFrom = \Carbon\Carbon::parse($this->date_from);
                $dateTo = \Carbon\Carbon::parse($this->date_to);

                if ($dateFrom->diffInDays($dateTo) > 365) {
                    $validator->errors()->add(
                        'date_range',
                        'Date range cannot exceed 365 days.'
                    );
                }
            }

            // Validate event_ids belong to current tenant
            if ($this->has('event_ids')) {
                $tenantId = tenant('id');
                $validEventIds = \Modules\EventManagement\Models\Event::where('tenant_id', $tenantId)
                    ->whereIn('id', $this->event_ids)
                    ->pluck('id')
                    ->toArray();

                if (count($validEventIds) !== count($this->event_ids)) {
                    $validator->errors()->add(
                        'event_ids',
                        'Some events do not belong to your organization.'
                    );
                }
            }
        });
    }

    /**
     * Get validated data with defaults applied.
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();

        // Apply defaults
        $validated['date_from'] = $validated['date_from'] ?? now()->subDays(30)->toDateString();
        $validated['date_to'] = $validated['date_to'] ?? now()->toDateString();
        $validated['include_cancelled'] = $validated['include_cancelled'] ?? false;
        $validated['group_by'] = $validated['group_by'] ?? 'event';
        $validated['format'] = $validated['format'] ?? 'json';

        // Default metrics if not specified
        if (! isset($validated['metrics'])) {
            $validated['metrics'] = [
                'revenue',
                'tickets_sold',
                'capacity_utilization',
                'customer_count',
            ];
        }

        return $validated;
    }
}
