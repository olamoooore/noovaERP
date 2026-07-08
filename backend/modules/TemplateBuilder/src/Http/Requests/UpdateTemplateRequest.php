<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Requests;

class UpdateTemplateRequest extends StoreTemplateRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'string', 'max:150'];
        $rules['document_type'] = ['sometimes', 'string', 'max:40'];

        return $rules;
    }
}
