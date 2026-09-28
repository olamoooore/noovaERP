<?php

namespace Modules\Storefront\Shortcodes;

/**
 * Shortcode token parser (Appendix B §B3.1/§B3.3 step 3).
 *
 * Grammar: [storefront.<function> name="double" name='single' name=scalar flag ids="1,2,3"]
 *  - single pass over the page HTML;
 *  - tokens inside <script>, <style>, and HTML comments are NEVER executed;
 *  - attribute count and value-length caps are applied here (§B3.1 limits);
 *  - produces raw string attributes only — typing/validation is the
 *    Definition's job (parser does not know any functions).
 */
final class Parser
{
    public const MAX_ATTRIBUTES = 20;

    public const MAX_VALUE_LENGTH = 500;

    /** @var string Single token grammar over the namespace prefix. */
    private const TOKEN_REGEX = '/\[(storefront\.[a-z0-9_]+(?:\.[a-z0-9_]+)?)((?:\s+[a-zA-Z0-9_]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))?)*)\s*\]/';

    private const ATTRIBUTE_REGEX = '/([a-zA-Z0-9_]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+)))?/';

    /**
     * Regions whose content must never be tokenized.
     */
    private const PROTECTED_REGIONS_REGEX = '/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<!--.*?-->)/is';

    /**
     * Quick guard so the pipeline only engages when the page has any token.
     */
    public function hasTokens(string $html): bool
    {
        return $html !== '' && str_contains($html, '[storefront.');
    }

    /**
     * Parse one token string into [function key, raw attributes].
     * Returns null when the string is not a well-formed token.
     *
     * @return null|array{function: string, attributes: array<string,mixed>}
     */
    public function parseToken(string $token): ?array
    {
        if (! preg_match(self::TOKEN_REGEX, trim($token), $m)) {
            return null;
        }

        return [
            'function' => $m[1],
            'attributes' => $this->parseAttributes($m[2] ?? ''),
        ];
    }

    /**
     * Replace every token in $html via $replacer. Protected regions are
     * copied through untouched; non-token text passes through unchanged.
     *
     * @param  callable(array{function: string, attributes: array<string,mixed>}, string): string  $replacer
     */
    public function replace(string $html, callable $replacer): string
    {
        if (! $this->hasTokens($html)) {
            return $html;
        }

        $out = '';

        // Split into protected/other regions, only transform the other ones.
        foreach (preg_split(self::PROTECTED_REGIONS_REGEX, $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $region) {
            if ($index % 2 === 1) {
                // captured delimiter: <script>/<style>/comment region
                $out .= $region;

                continue;
            }

            $out .= preg_replace_callback(
                self::TOKEN_REGEX,
                function (array $m) use ($replacer) {
                    $token = ['function' => $m[1], 'attributes' => $this->parseAttributes($m[2] ?? '')];

                    return $replacer($token, $m[0]);
                },
                $region
            );
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseAttributes(string $body): array
    {
        $attrs = [];
        if (trim($body) === '') {
            return $attrs;
        }

        if (preg_match_all(self::ATTRIBUTE_REGEX, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (count($attrs) >= self::MAX_ATTRIBUTES) {
                    break; // anti-abuse cap (§B3.1); remaining attributes are dropped
                }

                $name = $match[1];
                $value = true; // bare flag
                for ($i = 2; $i <= 4; $i++) {
                    if (isset($match[$i]) && $match[$i] !== '') {
                        $value = mb_substr($match[$i], 0, self::MAX_VALUE_LENGTH);
                        break;
                    }
                }

                $attrs[$name] = $value;
            }
        }

        return $attrs;
    }
}
