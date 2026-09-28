<?php

namespace Modules\Storefront\Shortcodes;

/**
 * One storefront function registration (Appendix B §B3.2). The Definition is
 * the single source of truth consumed by: the server renderer, the editor
 * blocks endpoint, the settings panel, the function reference and the
 * registry-driven test harness — nothing duplicates it.
 */
final class Definition
{
    /** @var array<string,Attribute> */
    private array $attributes = [];

    /** @var callable(Context, array<string,mixed>): string */
    private $renderer;

    private string $label = '';

    private string $icon = '';

    private string $category = 'Storefront';

    private Tier $tier = Tier::CACHEABLE;

    private int $cacheTtl = 300;

    private string $version = '1';

    private string $help = '';

    private string $example = '';

    private function __construct(public readonly string $key) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function tier(Tier $tier): self
    {
        $this->tier = $tier;

        return $this;
    }

    public function cache(int $ttl = 300): self
    {
        $this->cacheTtl = max(0, $ttl);

        return $this;
    }

    public function version(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function example(string $example): self
    {
        $this->example = $example;

        return $this;
    }

    /**
     * @param  array<int,Attribute>  $attributes
     */
    public function attributes(array $attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->name] = $attribute;
        }

        return $this;
    }

    /**
     * @param  callable(Context, array<string,mixed>): string  $renderer
     */
    public function render(callable $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * @return array<string,Attribute>
     */
    public function attributeDefinitions(): array
    {
        return $this->attributes;
    }

    /**
     * Coerce raw parser attributes to typed values. Unknown names are
     * reported (never executed) and ignored — §B3.1/§B3.5.
     *
     * @param  array<string,mixed>  $raw
     * @return array{values: array<string,mixed>, ignored: array<int,string>}
     */
    public function validate(array $raw): array
    {
        $values = [];
        $ignored = [];

        foreach ($this->attributes as $name => $attribute) {
            $values[$name] = array_key_exists($name, $raw)
                ? $attribute->coerce($raw[$name])
                : $attribute->coerce(null);
        }

        foreach (array_keys($raw) as $name) {
            if (! isset($this->attributes[$name])) {
                $ignored[] = (string) $name;
            }
        }

        return ['values' => $values, 'ignored' => $ignored];
    }

    public function renderFragment(Context $context, array $attributes): string
    {
        return ($this->renderer)($context, $attributes);
    }

    public function isCacheable(): bool
    {
        return $this->tier === Tier::CACHEABLE && $this->cacheTtl > 0;
    }

    public function cacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Editor/registry-facing schema (GET /website-builder/storefront/blocks).
     *
     * @return array<string,mixed>
     */
    public function toSchema(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'category' => $this->category,
            'tier' => $this->tier->value,
            'cacheable' => $this->isCacheable(),
            'version' => $this->version,
            'help' => $this->help,
            'example' => $this->example ?: $this->defaultExample(),
            'attributes' => array_map(
                static fn (Attribute $a) => $a->toSchema(),
                array_values($this->attributes)
            ),
        ];
    }

    public function defaultExample(): string
    {
        $parts = [];
        foreach ($this->attributes as $attribute) {
            if ($attribute->kind === Attribute::KIND_BOOL && $attribute->default === false) {
                continue;
            }
            if ($attribute->default === null || $attribute->default === [] || $attribute->default === '') {
                continue;
            }
            $parts[] = $attribute->name.'="'.$attribute->default.'"';
        }

        $attr = $parts === [] ? '' : ' '.implode(' ', $parts);

        return "[{$this->key}{$attr}]";
    }
}
