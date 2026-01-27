<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search Groups Request
 * 
 * Validates search and filtering parameters for group queries.
 * All parameters are optional for flexible searching.
 * 
 * Requirements: 12.1, 12.4
 */
class SearchGroupsRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['active', 'inactive', 'hiatus', 'disbanded'])],
            'agency' => ['nullable', 'string', 'max:255'],
            'debut_year' => ['nullable', 'integer', 'min:1900', 'max:' . date('Y')],
            'min_members' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_members' => ['nullable', 'integer', 'min:1', 'max:50', 'gte:min_members'],
            'genre' => ['nullable', 'string', Rule::in([
                'k-pop', 'pop', 'dance', 'ballad', 'r&b', 'hip-hop', 'rock', 'electronic',
                'trot', 'indie', 'folk', 'jazz', 'classical', 'experimental', 'other'
            ])],
            'sort_by' => ['nullable', 'string', Rule::in([
                'name', 'korean_name', 'debut_date', 'formation_date', 'agency', 'member_count', 'created_at'
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
            'min_members.integer' => 'Minimum members must be a number.',
            'min_members.min' => 'Minimum members must be at least 1.',
            'min_members.max' => 'Minimum members cannot exceed 50.',
            'max_members.integer' => 'Maximum members must be a number.',
            'max_members.min' => 'Maximum members must be at least 1.',
            'max_members.max' => 'Maximum members cannot exceed 50.',
            'max_members.gte' => 'Maximum members must be greater than or equal to minimum members.',
            'genre.in' => 'Invalid genre filter.',
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
            'min_members' => 'minimum members',
            'max_members' => 'maximum members',
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
        // Set default values
        $this->merge([
            'sort_by' => $this->sort_by ?? 'name',
            'sort_order' => $this->sort_order ?? 'asc',
            'per_page' => $this->per_page ?? 15,
        ]);
    }
}