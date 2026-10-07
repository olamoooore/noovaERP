<?php

namespace App\Platform\Settings;

use App\Platform\Settings\Contracts\SettingsRegistryContract;
use ReflectionClass;
use ReflectionMethod;

/**
 * Issue #921 (SS5): static checks that keep the settings registry honest.
 *
 * - {@see readerProblems()}: every declared setting has at least one reader
 *   outside its own settings screen, and every declared reader (directly or
 *   through its declared accessors) still references the setting's key.
 * - {@see literalKeyUsages()}: every `Setting::getValue()` / `setValue()`
 *   call in app/ and modules/ whose key is a literal or a class constant, so
 *   CI can fail on a key nobody declared.
 *
 * This is source analysis, not a call graph: a reader is accepted when its
 * method body names the key (literal or constant) or calls a declared
 * accessor that does. Keys composed at runtime are matched on the literals
 * they are composed from ({@see SettingDefinition::$keyParts}), and keys built
 * from variables are reported as dynamic and not checked.
 */
class SettingsUsageScanner
{
    public function __construct(private readonly SettingsRegistryContract $registry) {}

    /**
     * @param  list<SettingDefinition>|null  $definitions  defaults to the whole registry
     * @return list<string> human-readable problems; empty when every setting has a live reader
     */
    public function readerProblems(?array $definitions = null): array
    {
        $problems = [];

        foreach ($definitions ?? $this->registry->all() as $definition) {
            $label = "{$definition->screen}:{$definition->field} ({$definition->key})";
            $screenMethods = $this->registry->screenMethods($definition->screen);

            [$validVia, $accessors] = $this->validAccessors($definition, $problems, $label);
            $live = 0;

            foreach ($definition->readers as $reader) {
                if (in_array($reader, $screenMethods, true)) {
                    $problems[] = "{$label}: reader {$reader} is the settings screen itself, not a consumer.";

                    continue;
                }

                $method = $this->method($reader);
                if ($method === null) {
                    $problems[] = "{$label}: reader {$reader} does not exist.";

                    continue;
                }

                if (! $this->reaches($definition, $method, $validVia)) {
                    $problems[] = "{$label}: reader {$reader} does not reference the key or any declared accessor.";

                    continue;
                }

                if ($definition->path !== null && ! $this->mentionsPath($definition->path, $method, $accessors)) {
                    $problems[] = "{$label}: reader {$reader} never reads '{$definition->path}' of the block.";

                    continue;
                }

                $live++;
            }

            if ($live === 0) {
                $problems[] = "{$label}: declared setting has no reader — saved values are silently ignored.";
            }
        }

        return $problems;
    }

    /**
     * Literal or constant `Setting` keys read or written in app/ and modules/:
     * `keys` maps key => ["path:line", ...]; `dynamic` lists the "path:line"
     * of calls whose key is built at runtime and cannot be resolved.
     *
     * @return array{keys: array<string, list<string>>, dynamic: list<string>}
     */
    public function literalKeyUsages(): array
    {
        $base = base_path();
        $keys = [];
        $dynamic = [];

        foreach ($this->phpFiles([$base.'/app', $base.'/modules']) as $file) {
            $relative = ltrim(substr($file, strlen($base)), '/');
            if ($relative === 'app/Models/Setting.php') {
                continue;
            }

            $source = $this->code($file);
            if (! str_contains($source, 'Setting::')) {
                continue;
            }

            if (! preg_match_all('/(?<![\w\\\\])(?:\\\\?App\\\\Models\\\\)?Setting::(?:getValue|setValue)\(\s*/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$call, $offset]) {
                $line = substr_count($source, "\n", 0, $offset) + 1;
                $argument = $this->firstArgument(substr($source, $offset + strlen($call)));
                $key = $this->resolveKeyExpression($argument, $source);

                if ($key === null) {
                    $dynamic[] = "{$relative}:{$line}";

                    continue;
                }

                $keys[$key][] = "{$relative}:{$line}";
            }
        }

        ksort($keys);

        return ['keys' => $keys, 'dynamic' => $dynamic];
    }

    /**
     * The short names of the declared accessors that reach the key, and the
     * accessors themselves.
     *
     * @return array{0: array<string, true>, 1: list<ReflectionMethod>}
     */
    private function validAccessors(SettingDefinition $definition, array &$problems, string $label): array
    {
        $methods = [];
        foreach ($definition->via as $via) {
            $method = $this->method($via);
            if ($method === null) {
                $problems[] = "{$label}: accessor {$via} does not exist.";

                continue;
            }
            $methods[$via] = $method;
        }

        // Fixpoint: an accessor is valid when it names the key or calls an
        // accessor already known to be valid.
        $valid = [];
        do {
            $grew = false;
            foreach ($methods as $via => $method) {
                $name = $method->getName();
                if (isset($valid[$name])) {
                    continue;
                }
                if ($this->namesKey($definition, $method) || $this->callsAny($this->body($method), $valid)) {
                    $valid[$name] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        foreach ($methods as $via => $method) {
            if (! isset($valid[$method->getName()])) {
                $problems[] = "{$label}: accessor {$via} does not reference the key.";
            }
        }

        return [$valid, array_values(array_filter($methods, fn (ReflectionMethod $m) => isset($valid[$m->getName()])))];
    }

    private function reaches(SettingDefinition $definition, ReflectionMethod $method, array $validVia): bool
    {
        return $this->namesKey($definition, $method) || $this->callsAny($this->body($method), $validVia);
    }

    /** @param list<ReflectionMethod> $accessors */
    private function mentionsPath(string $path, ReflectionMethod $method, array $accessors): bool
    {
        $needle = "'{$path}'";
        if (str_contains($this->body($method), $needle)) {
            return true;
        }

        foreach ($accessors as $accessor) {
            if (str_contains($this->body($accessor), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function namesKey(SettingDefinition $definition, ReflectionMethod $method): bool
    {
        if ($definition->keyParts !== []) {
            $classSource = $this->code((string) $method->getFileName());
            foreach ($definition->keyParts as $part) {
                if (! str_contains($classSource, "'{$part}'")) {
                    return false;
                }
            }

            return true;
        }

        $body = $this->body($method);
        if (str_contains($body, "'{$definition->key}'") || str_contains($body, "\"{$definition->key}\"")) {
            return true;
        }

        $fileSource = $this->code((string) $method->getFileName());
        if (preg_match_all('/((?:\\\\?[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Z][A-Z0-9_]*)\b/', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $class, $constant]) {
                if ($this->constantValue($class, $constant, $method->getDeclaringClass()->getName(), $fileSource) === $definition->key) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, true> $names */
    private function callsAny(string $body, array $names): bool
    {
        foreach (array_keys($names) as $name) {
            if (preg_match('/(?:->|::)'.preg_quote($name, '/').'\s*\(/', $body)) {
                return true;
            }
        }

        return false;
    }

    private function method(string $reference): ?ReflectionMethod
    {
        [$class, $name] = array_pad(explode('::', $reference, 2), 2, null);
        if ($name === null || ! class_exists($class) || ! method_exists($class, $name)) {
            return null;
        }

        return new ReflectionMethod($class, $name);
    }

    private function body(ReflectionMethod $method): string
    {
        $lines = explode("\n", $this->code((string) $method->getFileName()));

        return implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** @var array<string, string> */
    private array $code = [];

    /**
     * A file's source with comments blanked (line numbers preserved), so a
     * key mentioned only in a comment or docblock never counts as a read.
     */
    private function code(string $file): string
    {
        if (isset($this->code[$file])) {
            return $this->code[$file];
        }

        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $this->code[$file] = $out;
    }

    private function constantValue(string $class, string $constant, ?string $selfClass, string $fileSource): mixed
    {
        $fqcn = $this->resolveClass($class, $selfClass, $fileSource);
        if ($fqcn === null || ! class_exists($fqcn)) {
            return null;
        }

        $reflection = new ReflectionClass($fqcn);

        return $reflection->hasConstant($constant) ? $reflection->getConstant($constant) : null;
    }

    private function resolveClass(string $class, ?string $selfClass, string $fileSource): ?string
    {
        if (in_array(strtolower($class), ['self', 'static'], true)) {
            return $selfClass;
        }

        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }

        $first = explode('\\', $class)[0];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $fileSource, $uses, PREG_SET_ORDER)) {
            foreach ($uses as $use) {
                $alias = $use[2] ?? '';
                $alias = $alias !== '' ? $alias : substr((string) strrchr('\\'.$use[1], '\\'), 1);
                if ($alias === $first) {
                    return $use[1].substr($class, strlen($first));
                }
            }
        }

        $namespace = preg_match('/^namespace\s+([^;]+);/m', $fileSource, $ns) ? trim($ns[1]) : '';

        return ltrim($namespace.'\\'.$class, '\\');
    }

    private function resolveKeyExpression(string $expression, string $fileSource): ?string
    {
        $expression = trim($expression);

        if (preg_match('/^\'([^\'\\\\]*)\'$/', $expression, $m) || preg_match('/^"([^"\\\\$]*)"$/', $expression, $m)) {
            return $m[1];
        }

        if (preg_match('/^((?:\\\\?[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Z][A-Z0-9_]*)$/', $expression, $m)) {
            $self = $this->classDeclaredIn($fileSource);
            $value = $this->constantValue($m[1], $m[2], $self, $fileSource);

            return is_string($value) ? $value : null;
        }

        return null;
    }

    private function classDeclaredIn(string $fileSource): ?string
    {
        if (! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|enum)\s+(\w+)/m', $fileSource, $m)) {
            return null;
        }
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $fileSource, $ns) ? trim($ns[1]) : '';

        return ltrim($namespace.'\\'.$m[1], '\\');
    }

    /** The first call argument (up to the top-level comma or closing paren). */
    private function firstArgument(string $rest): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($rest);

        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                if ($depth === 0) {
                    return substr($rest, 0, $i);
                }
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return substr($rest, 0, $i);
            }
        }

        return $rest;
    }

    /**
     * @param  list<string>  $roots
     * @return \Generator<string>
     */
    private function phpFiles(array $roots): \Generator
    {
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() !== 'php'
                    || str_contains($path, '/tests/')
                    || str_contains($path, '/database/')
                    || str_contains($path, '/vendor/')) {
                    continue;
                }

                yield $path;
            }
        }
    }
}
