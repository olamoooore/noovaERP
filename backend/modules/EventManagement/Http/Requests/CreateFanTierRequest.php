<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateFanTierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string|max:1000',
            'minimum_points' => 'integer|min:0',
            'minimum_events_attended' => 'integer|min:0',
            'minimum_total_spent' => 'numeric|min:0',
            'priority_level' => 'integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
            'benefits' => 'nullable|array',
            'benefits.*.benefit_type' => 'required_with:benefits|string|in:early_access,discount,exclusive_tickets,priority_support,points_multiplier',
            'benefits.*.benefit_name' => 'required_with:benefits|string|max:255',
            'benefits.*.description' => 'nullable|string|max:1000',
            'benefits.*.configuration' => 'required_with:benefits|array',
            'benefits.*.is_active' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'benefits.*.benefit_type.in' => 'Invalid benefit type. Must be one of: early_access, discount, exclusive_tickets, priority_support, points_multiplier.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Auto-generate slug from name if not provided
        if (! $this->has('slug') && $this->has('name')) {
            $this->merge([
                'slug' => \Str::slug($this->name),
            ]);
        }

        // Set defaults
        $this->merge([
            'minimum_points' => $this->minimum_points ?? 0,
            'minimum_events_attended' => $this->minimum_events_attended ?? 0,
            'minimum_total_spent' => $this->minimum_total_spent ?? 0,
            'priority_level' => $this->priority_level ?? 0,
            'is_active' => $this->is_active ?? true,
        ]);
    }
}
