<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search Artists Request
 *
 * Validates search and filtering parameters for artist queries.
 * All parameters are optional for flexible searching.
 *
 * Requirements: 12.1, 12.4
 */
class SearchArtistsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'hiatus', 'military_service', 'retired'])],
            'agency' => ['nullable', 'string', 'max:255'],
            'debut_year' => ['nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'solo' => ['nullable', 'boolean'],
            'position' => ['nullable', 'string', Rule::in([
                'leader', 'main_vocalist', 'lead_vocalist', 'vocalist',
                'main_dancer', 'lead_dancer', 'dancer',
                'main_rapper', 'lead_rapper', 'rapper',
                'visual', 'center', 'face_of_group', 'maknae',
            ])],
            'gender' => ['nullable', Rule::in(['male', 'female', 'non-binary', 'other'])],
            'sort_by' => ['nullable', 'string', Rule::in([
                'name', 'stage_name', 'debut_date', 'birth_date', 'agency', 'created_at',
            ])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'search.max' => 'Search term cannot exceed 255 characters.',
            'status.in' => 'Invalid status filter.',
            'agency.max' => 'Agency name cannot exceed 255 characters.',
            'debut_year.integer' => 'Debut year must be a valid year.',
            'debut_year.min' => 'Debut year cannot be before 1900.',
            'debut_year.max' => 'Debut year cannot be in the future.',
            'position.in' => 'Invalid position filter.',
            'gender.in' => 'Invalid gender filter.',
            'sort_by.in' => 'Invalid sort field.',
            'sort_order.in' => 'Sort order must be either "asc" or "desc".',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'search' => 'search term',
            'debut_year' => 'debut year',
            'sort_by' => 'sort field',
            'sort_order' => 'sort order',
            'per_page' => 'items per page',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean to actual boolean
        if ($this->has('solo')) {
            $this->merge([
                'solo' => filter_var($this->solo, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        // Set default values
        $this->merge([
            'sort_by' => $this->sort_by ?? 'name',
            'sort_order' => $this->sort_order ?? 'asc',
            'per_page' => $this->per_page ?? 15,
        ]);
    }
}
