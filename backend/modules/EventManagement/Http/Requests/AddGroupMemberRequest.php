<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add Group Member Request
 *
 * Validates data for adding an artist as a member to a K-POP group
 * with position assignments and member-specific metadata.
 *
 * Requirements: 12.1, 12.2
 */
class AddGroupMemberRequest extends FormRequest
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
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'stage_name_in_group' => ['nullable', 'string', 'max:255'],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', Rule::in([
                'leader', 'main_vocalist', 'lead_vocalist', 'vocalist',
                'main_dancer', 'lead_dancer', 'dancer',
                'main_rapper', 'lead_rapper', 'rapper',
                'visual', 'center', 'face_of_group', 'maknae',
            ])],
            'is_leader' => ['nullable', 'boolean'],
            'is_main_vocalist' => ['nullable', 'boolean'],
            'is_main_dancer' => ['nullable', 'boolean'],
            'is_main_rapper' => ['nullable', 'boolean'],
            'member_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'joined_date' => ['nullable', 'date', 'before_or_equal:today'],
            'representative_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'emoji' => ['nullable', 'string', 'max:10'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'hiatus', 'left'])],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'artist_id.required' => 'Artist selection is required.',
            'artist_id.exists' => 'Selected artist does not exist.',
            'stage_name_in_group.max' => 'Stage name in group cannot exceed 255 characters.',
            'positions.*.in' => 'Invalid position specified.',
            'member_number.integer' => 'Member number must be a valid number.',
            'member_number.min' => 'Member number must be at least 1.',
            'member_number.max' => 'Member number cannot exceed 50.',
            'joined_date.before_or_equal' => 'Joined date cannot be in the future.',
            'representative_color.regex' => 'Representative color must be a valid hex color code (e.g., #FF0000).',
            'emoji.max' => 'Emoji cannot exceed 10 characters.',
            'nickname.max' => 'Nickname cannot exceed 255 characters.',
            'special_notes.max' => 'Special notes cannot exceed 1000 characters.',
            'status.in' => 'Invalid member status.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'artist_id' => 'artist',
            'stage_name_in_group' => 'stage name in group',
            'is_leader' => 'leader status',
            'is_main_vocalist' => 'main vocalist status',
            'is_main_dancer' => 'main dancer status',
            'is_main_rapper' => 'main rapper status',
            'member_number' => 'member number',
            'joined_date' => 'joined date',
            'representative_color' => 'representative color',
            'special_notes' => 'special notes',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure arrays are properly formatted
        if ($this->has('positions') && is_string($this->positions)) {
            $this->merge([
                'positions' => json_decode($this->positions, true) ?: [],
            ]);
        }

        // Convert string booleans to actual booleans
        $booleanFields = ['is_leader', 'is_main_vocalist', 'is_main_dancer', 'is_main_rapper'];
        foreach ($booleanFields as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => filter_var($this->$field, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        // Set default status
        if (! $this->has('status')) {
            $this->merge(['status' => 'active']);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Ensure only one leader per group
            if ($this->is_leader) {
                $groupId = $this->route('id');
                $existingLeader = \Modules\EventManagement\Models\Group::find($groupId)
                    ?->groupMemberships()
                    ->where('is_leader', true)
                    ->where('status', 'active')
                    ->exists();

                if ($existingLeader) {
                    $validator->errors()->add('is_leader', 'This group already has a leader.');
                }
            }

            // Validate member number uniqueness within group
            if ($this->member_number) {
                $groupId = $this->route('id');
                $existingMemberNumber = \Modules\EventManagement\Models\Group::find($groupId)
                    ?->groupMemberships()
                    ->where('member_number', $this->member_number)
                    ->where('status', 'active')
                    ->exists();

                if ($existingMemberNumber) {
                    $validator->errors()->add('member_number', 'This member number is already taken in the group.');
                }
            }
        });
    }
}
