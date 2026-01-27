<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Group Request
 * 
 * Validates data for creating new K-POP groups with comprehensive
 * metadata, member information, and social media links.
 * 
 * Requirements: 12.1, 12.2
 */
class CreateGroupRequest extends FormRequest
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
            'korean_name' => ['nullable', 'string', 'max:255'],
            'english_name' => ['nullable', 'string', 'max:255'],
            'name_meaning' => ['nullable', 'string', 'max:1000'],
            'debut_date' => ['nullable', 'date', 'before_or_equal:today'],
            'formation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'agency' => ['nullable', 'string', 'max:255'],
            'sub_labels' => ['nullable', 'array'],
            'sub_labels.*' => ['string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'concept' => ['nullable', 'string', 'max:1000'],
            'genres' => ['nullable', 'array'],
            'genres.*' => ['string', Rule::in([
                'k-pop', 'pop', 'dance', 'ballad', 'r&b', 'hip-hop', 'rock', 'electronic',
                'trot', 'indie', 'folk', 'jazz', 'classical', 'experimental', 'other'
            ])],
            'official_colors' => ['nullable', 'array'],
            'official_colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'fandom_name' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'profile_image_url' => ['nullable', 'url', 'max:500'],
            'social_media_links' => ['nullable', 'array'],
            'social_media_links.*.platform' => ['required_with:social_media_links', 'string', Rule::in([
                'instagram', 'twitter', 'tiktok', 'youtube', 'weibo', 'facebook', 'vlive', 'other'
            ])],
            'social_media_links.*.url' => ['required_with:social_media_links', 'url', 'max:500'],
            'social_media_links.*.handle' => ['nullable', 'string', 'max:100'],
            'official_websites' => ['nullable', 'array'],
            'official_websites.*' => ['url', 'max:500'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'hiatus', 'disbanded'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Group name is required.',
            'name.max' => 'Group name cannot exceed 255 characters.',
            'debut_date.before_or_equal' => 'Debut date cannot be in the future.',
            'formation_date.before_or_equal' => 'Formation date cannot be in the future.',
            'sub_labels.*.max' => 'Sub-label name cannot exceed 255 characters.',
            'description.max' => 'Description cannot exceed 5000 characters.',
            'concept.max' => 'Concept description cannot exceed 1000 characters.',
            'genres.*.in' => 'Invalid genre specified.',
            'official_colors.*.regex' => 'Official colors must be valid hex color codes (e.g., #FF0000).',
            'fandom_name.max' => 'Fandom name cannot exceed 255 characters.',
            'logo_url.url' => 'Logo URL must be a valid URL.',
            'profile_image_url.url' => 'Profile image URL must be a valid URL.',
            'social_media_links.*.platform.in' => 'Invalid social media platform.',
            'social_media_links.*.url.url' => 'Social media URL must be a valid URL.',
            'official_websites.*.url' => 'Official website must be a valid URL.',
            'status.in' => 'Invalid group status.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'korean_name' => 'Korean name',
            'english_name' => 'English name',
            'name_meaning' => 'name meaning',
            'debut_date' => 'debut date',
            'formation_date' => 'formation date',
            'sub_labels' => 'sub-labels',
            'fandom_name' => 'fandom name',
            'logo_url' => 'logo URL',
            'profile_image_url' => 'profile image URL',
            'social_media_links' => 'social media links',
            'official_websites' => 'official websites',
            'official_colors' => 'official colors',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure arrays are properly formatted
        if ($this->has('sub_labels') && is_string($this->sub_labels)) {
            $this->merge([
                'sub_labels' => json_decode($this->sub_labels, true) ?: []
            ]);
        }

        if ($this->has('genres') && is_string($this->genres)) {
            $this->merge([
                'genres' => json_decode($this->genres, true) ?: []
            ]);
        }

        if ($this->has('official_colors') && is_string($this->official_colors)) {
            $this->merge([
                'official_colors' => json_decode($this->official_colors, true) ?: []
            ]);
        }

        if ($this->has('social_media_links') && is_string($this->social_media_links)) {
            $this->merge([
                'social_media_links' => json_decode($this->social_media_links, true) ?: []
            ]);
        }

        if ($this->has('official_websites') && is_string($this->official_websites)) {
            $this->merge([
                'official_websites' => json_decode($this->official_websites, true) ?: []
            ]);
        }

        if ($this->has('metadata') && is_string($this->metadata)) {
            $this->merge([
                'metadata' => json_decode($this->metadata, true) ?: []
            ]);
        }
    }
}