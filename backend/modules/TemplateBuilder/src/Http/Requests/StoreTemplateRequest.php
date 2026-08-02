<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\TemplateBuilder\Models\DocumentTemplate;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:40'],
            'code' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'mode' => ['nullable', 'in:'.implode(',', [DocumentTemplate::MODE_BLOCK, DocumentTemplate::MODE_CODE, DocumentTemplate::MODE_BLADE])],
            'format' => ['nullable', 'string', 'max:12'],
            'orientation' => ['nullable', 'in:portrait,landscape'],
            'margin' => ['nullable', 'array'],
            'default_font' => ['nullable', 'string', 'max:60'],
            'default_locale' => ['nullable', 'string', 'max:8'],
            'layout_config' => ['nullable', 'array'],
            'html' => ['nullable', 'string'],
            'css' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', 'in:'.implode(',', [DocumentTemplate::STATUS_DRAFT, DocumentTemplate::STATUS_PUBLISHED, DocumentTemplate::STATUS_ARCHIVED])],
        ];
    }
}
