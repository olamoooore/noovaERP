<?php

namespace App\Platform\DataSecurity;

/**
 * Decision on #919 (ADR-007 v0.3 §16A): sensitive fields are masked on the
 * model when it is converted to JSON, by the viewer's access, so every
 * `response()->json($model)` path is covered — not only resource classes.
 *
 * The using model implements sensitiveFields():
 *   ['column' => ['permission' => '<read permission>', 'mask' => 'name|email|phone|default']]
 * and sensitiveReferenceType() for the audit summary.
 */
trait MasksSensitiveFields
{
    /** @return array<string, array{permission: string, mask: string}> */
    abstract public function sensitiveFields(): array;

    abstract public function sensitiveReferenceType(): string;

    public static function bootMasksSensitiveFields(): void
    {
        // A masked value must never be saved, whatever path writes it.
        static::saving(function ($model) {
            foreach ($model->sensitiveFields() as $field => $rule) {
                $original = $model->getOriginal($field);

                if (! $model->isDirty($field) || ! is_string($original) || $original === '') {
                    continue;
                }

                if ($model->getAttribute($field) === SensitiveFieldGuard::mask($original, $rule['mask'])) {
                    $model->setAttribute($field, $original);
                }
            }
        });
    }

    public function attributesToArray()
    {
        return app(SensitiveFieldGuard::class)->applyToAttributes(
            $this,
            $this->sensitiveFields(),
            $this->sensitiveReferenceType(),
            parent::attributesToArray(),
        );
    }

    /**
     * Strip/refuse writes to sensitive fields the current viewer can't read.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filterSensitiveWrite(array $input): array
    {
        return app(SensitiveFieldGuard::class)->filterWrite($this, $this->sensitiveFields(), $input);
    }
}
