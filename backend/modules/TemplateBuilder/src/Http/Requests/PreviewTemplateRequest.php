<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\TemplateBuilder\Models\DocumentTemplate;

class PreviewTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'layout_config' => ['nullable', 'array'],
            'html' => ['nullable', 'string'],
            'css' => ['nullable', 'string'],
            'mode' => ['nullable', 'in:'.implode(',', [DocumentTemplate::MODE_BLOCK, DocumentTemplate::MODE_CODE])],
            'sample_data' => ['nullable', 'array'],
            'document_type' => ['nullable', 'string', 'max:40'],
        ];
    }
}
