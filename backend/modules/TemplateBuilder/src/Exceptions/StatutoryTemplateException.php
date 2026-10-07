<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Exceptions;

use RuntimeException;

/**
 * #627: publishing a tax-document template that is missing fields the tenant's
 * country pack declares as required (e.g. SG UEN, MY TIN).
 */
class StatutoryTemplateException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing  field labels, e.g. ['Unique Entity Number (UEN)']
     */
    public function __construct(
        public readonly array $missing,
        string $documentType = 'invoice',
    ) {
        $label = trim(str_replace('_', ' ', $documentType));

        parent::__construct('This '.$label.' template is missing: '.implode(', ', $missing));
    }
}
