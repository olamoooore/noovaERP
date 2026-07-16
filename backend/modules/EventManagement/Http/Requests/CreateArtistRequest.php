<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Artist Request
 *
 * Validates data for creating new K-POP artists with comprehensive
 * metadata and social media information.
 *
 * Requirements: 12.1, 12.2
 */
class CreateArtistRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'stage_name' => ['nullable', 'string', 'max:255'],
            'korean_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'non-binary', 'other'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'debut_date' => ['nullable', 'date', 'before_or_equal:today'],
            'agency' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', Rule::in([
                'leader', 'main_vocalist', 'lead_vocalist', 'vocalist',
                'main_dancer', 'lead_dancer', 'dancer',
                'main_rapper', 'lead_rapper', 'rapper',
                'visual', 'center', 'face_of_group', 'maknae',
            ])],
            'profile_image_url' => ['nullable', 'url', 'max:500'],
            'social_media_links' => ['nullable', 'array'],
            'social_media_links.*.platform' => ['required_with:social_media_links', 'string', Rule::in([
                'instagram', 'twitter', 'tiktok', 'youtube', 'weibo', 'facebook', 'vlive', 'other',
            ])],
            'social_media_links.*.url' => ['required_with:social_media_links', 'url', 'max:500'],
            'social_media_links.*.handle' => ['nullable', 'string', 'max:100'],
            'official_colors' => ['nullable', 'array'],
            'official_colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'hiatus', 'military_service', 'retired'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Artist name is required.',
            'name.max' => 'Artist name cannot exceed 255 characters.',
            'birth_date.before' => 'Birth date must be in the past.',
            'debut_date.before_or_equal' => 'Debut date cannot be in the future.',
            'positions.*.in' => 'Invalid position specified.',
            'social_media_links.*.platform.in' => 'Invalid social media platform.',
            'social_media_links.*.url.url' => 'Social media URL must be a valid URL.',
            'official_colors.*.regex' => 'Official colors must be valid hex color codes (e.g., #FF0000).',
            'status.in' => 'Invalid artist status.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'stage_name' => 'stage name',
            'korean_name' => 'Korean name',
            'birth_date' => 'birth date',
            'debut_date' => 'debut date',
            'profile_image_url' => 'profile image URL',
            'social_media_links' => 'social media links',
            'official_colors' => 'official colors',
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

        if ($this->has('social_media_links') && is_string($this->social_media_links)) {
            $this->merge([
                'social_media_links' => json_decode($this->social_media_links, true) ?: [],
            ]);
        }

        if ($this->has('official_colors') && is_string($this->official_colors)) {
            $this->merge([
                'official_colors' => json_decode($this->official_colors, true) ?: [],
            ]);
        }

        if ($this->has('metadata') && is_string($this->metadata)) {
            $this->merge([
                'metadata' => json_decode($this->metadata, true) ?: [],
            ]);
        }
    }
}
