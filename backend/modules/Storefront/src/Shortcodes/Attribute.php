<?php

namespace Modules\Storefront\Shortcodes;

/**
 * Typed shortcode attribute (Appendix B §B3.2). Attributes are DATA, never
 * code: each has a scalar kind, a default, and — for numeric/list kinds —
 * hard bounds enforced at coercion time (§B3.5 query-budget/anti-abuse caps).
 *
 * Coercion is total: unknown keys are dropped, invalid scalars fall back to
 * the default (they never throw). This is what makes a malformed or hostile
 * `[storefront.*]` token degrade to a safe render rather than a 500.
 */
final class Attribute
{
    public const KIND_STRING = 'string';

    public const KIND_INT = 'int';

    public const KIND_BOOL = 'bool';

    public const KIND_ENUM = 'enum';

    public const KIND_LIST = 'list';   // comma-separated integers (e.g. ids)

    public const KIND_SLUG = 'slug';   // restricted token charset

    /**
     * @param  array<int,string|int>  $enum  allowed values (KIND_ENUM)
     * @param  int|null  $min  inclusive lower bound (KIND_INT)
     * @param  int|null  $max  inclusive upper bound (KIND_INT / KIND_LIST length)
     * @param  int|null  $maxLength  character cap (KIND_STRING / KIND_SLUG)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly mixed $default = null,
        public readonly array $enum = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?int $maxLength = null,
        public readonly string $help = '',
    ) {}

    public static function string(string $name, mixed $default = null, ?int $maxLength = null, string $help = ''): self
    {
        return new self($name, self::KIND_STRING, $default, maxLength: $maxLength ?? 200, help: $help);
    }

    public static function slug(string $name, mixed $default = null, string $help = ''): self
    {
        return new self($name, self::KIND_SLUG, $default, maxLength: 120, help: $help);
    }

    public static function int(string $name, mixed $default = null, ?int $min = null, ?int $max = null, string $help = ''): self
    {
        return new self($name, self::KIND_INT, $default, min: $min, max: $max, help: $help);
    }

    public static function bool(string $name, bool $default = false, string $help = ''): self
    {
        return new self($name, self::KIND_BOOL, $default, help: $help);
    }

    /**
     * @param  array<int,string|int>  $values
     */
    public static function enum(string $name, array $values, mixed $default = null, string $help = ''): self
    {
        return new self($name, self::KIND_ENUM, $default ?? ($values[0] ?? null), enum: $values, help: $help);
    }

    /**
     * Comma-separated integer id list, capped to $max entries.
     */
    public static function idList(string $name, int $max = 100, string $help = ''): self
    {
        return new self($name, self::KIND_LIST, default: [], max: $max, help: $help);
    }

    /**
     * Serialize for the editor blocks schema (the single source of truth the
     * editor settings panel and in-app reference render from — §B3.2).
     *
     * @return array<string,mixed>
     */
    public function toSchema(): array
    {
        return array_filter([
            'name' => $this->name,
            'kind' => $this->kind,
            'default' => $this->default,
            'enum' => $this->enum !== [] ? $this->enum : null,
            'min' => $this->min,
            'max' => $this->max,
            'max_length' => $this->maxLength,
            'help' => $this->help ?: null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Coerce a raw token value into this attribute's typed, bounded value.
     * Always returns a safe value; never throws.
     */
    public function coerce(mixed $raw): mixed
    {
        return match ($this->kind) {
            self::KIND_BOOL => $this->coerceBool($raw),
            self::KIND_INT => $this->coerceInt($raw),
            self::KIND_ENUM => $this->coerceEnum($raw),
            self::KIND_LIST => $this->coerceList($raw),
            self::KIND_SLUG => $this->coerceSlug($raw),
            default => $this->coerceString($raw),
        };
    }

    private function coerceBool(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return (bool) $this->default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function coerceInt(mixed $raw): int
    {
        if (is_array($raw) || $raw === null || $raw === '') {
            return (int) $this->default;
        }
        $n = (int) $raw;
        if ($this->min !== null && $n < $this->min) {
            $n = $this->min;
        }
        if ($this->max !== null && $n > $this->max) {
            $n = $this->max;
        }

        return $n;
    }

    private function coerceEnum(mixed $raw): mixed
    {
        if (is_array($raw) || $raw === null) {
            return $this->default;
        }
        $s = trim((string) $raw);
        foreach ($this->enum as $allowed) {
            if ((string) $allowed === $s) {
                return $allowed;
            }
        }

        return $this->default;
    }

    /**
     * @return array<int,int>
     */
    private function coerceList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($parts as $p) {
            $trimmed = trim((string) $p);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                continue;
            }
            $ids[] = (int) $trimmed;
            if ($this->max !== null && count($ids) >= $this->max) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    private function coerceSlug(mixed $raw): ?string
    {
        $s = $this->coerceString($raw);
        if ($s === null) {
            return null;
        }
        // Restricted charset: lowercase alnum + dash/underscore. Anything
        // else is stripped, so a slug can never carry a query/fragment/path.
        $s = preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));

        return $s === '' ? null : $s;
    }

    private function coerceString(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return $this->default === null ? null : (string) $this->default;
        }
        if ($this->maxLength !== null && $this->maxLength > 0) {
            $s = mb_substr($s, 0, $this->maxLength);
        }

        return $s;
    }
}
