<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #900 (PC6) — enforcement of "all sales status changes go through the
 * WorkflowEngine".
 *
 * The issue counted literal `'status' => '…'` writes in six sales files. This
 * token-level static check fails if any guarded file regains a raw status
 * write on a workflow-managed document: create/update/fill arrays containing
 * a `status` key, `->status = …` or `['status'] = …` assignments.
 *
 * It is deliberately receiver-aware: writes on non-workflow models are
 * allowed via ALLOWED_RECEIVERS (e.g. ApprovalRequest.status is the approval
 * inbox dimension, not the document lifecycle), and audit-log before/after
 * snapshot arrays are not write calls and are ignored.
 */
class SalesStatusLiteralWritesStaticTest extends TestCase
{
    /**
     * The files called out by issue #900 (literal writes inventory), plus
     * OrderLifecycleService ($order->update(['status' => $toStatus])).
     */
    private const GUARDED_FILES = [
        'app/Http/Controllers/Api/InvoiceController.php',
        'app/Http/Controllers/Api/QuotationController.php',
        'app/Services/InvoiceService.php',
        'app/Services/InvoiceCancellationService.php',
        'modules/Sales/src/Services/DeliveryOrderService.php',
        'modules/Sales/src/Services/RecurringSalesService.php',
        'modules/Sales/src/Services/OrderLifecycleService.php',
        'modules/Sales/src/Services/SalesOrderService.php',
    ];

    private const WRITE_METHODS = [
        'update', 'forcefill', 'fill', 'create', 'updateorcreate', 'firstorcreate', 'insert', 'insertgetid',
    ];

    /**
     * Receivers whose own `status` column is not a sales document lifecycle.
     */
    private const ALLOWED_RECEIVERS = [
        'ApprovalRequest',
        // #882 (SA9, landed on the fix branch after #900's branch point):
        // the deferred revenue schedule's `status` is its recognition state
        // (active/completed/…), not a sales document transition.
        'DeferredRevenueSchedule',
        // #461 (DO9): the return document's `status` is the return's own
        // state (recorded/restocked/…), not the delivery order lifecycle.
        'DeliveryOrderReturn',
    ];

    public function test_guarded_sales_files_contain_no_raw_status_writes(): void
    {
        $violations = [];

        foreach (self::GUARDED_FILES as $relativePath) {
            $path = base_path($relativePath);

            $this->assertFileExists($path, "Guarded sales file {$relativePath} must exist.");

            $source = file_get_contents($path);
            $violations = array_merge(
                $violations,
                $this->findArrayKeyWrites($source, $relativePath),
                $this->findDirectAssignments($source, $relativePath),
            );
        }

        $this->assertSame(
            [],
            $violations,
            "Raw status writes found outside the WorkflowEngine (#900).\n"
                .implode("\n", $violations)
        );
    }

    /**
     * Detect write calls (create/update/fill/…) whose argument array contains
     * a `'status' => …` key (token-scanned, so multi-line and nested arrays are
     * handled).
     *
     * @return string[]
     */
    private function findArrayKeyWrites(string $source, string $relativePath): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $violations = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array(strtolower($token[1]), self::WRITE_METHODS, true)) {
                continue;
            }

            $operatorIndex = $this->previousSignificantIndex($tokens, $i);

            if ($operatorIndex === null) {
                continue;
            }

            $operator = $tokens[$operatorIndex];
            $operatorKind = is_array($operator) ? $operator[0] : $operator;

            if (! in_array($operatorKind, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $receiver = $this->resolveReceiver($tokens, $operatorIndex);
            if ($receiver !== null && in_array($receiver, self::ALLOWED_RECEIVERS, true)) {
                continue;
            }

            if ($this->arrayArgumentContainsStatusKey($tokens, $i, $count)) {
                $violations[] = "{$relativePath}:{$token[2]} "
                    .($receiver ?? 'variable')."::{$token[1]}() writes a status key";
            }
        }

        return $violations;
    }

    /**
     * Detect `->status = …` and `['status'] = …` direct assignments.
     *
     * @return string[]
     */
    private function findDirectAssignments(string $source, string $relativePath): array
    {
        $violations = [];

        foreach ([
            '/->status\s*=\s*(?!=)/' => 'direct ->status assignment',
            '/\[\s*[\'"]status[\'"]\s*\]\s*=\s*(?!=)/' => "direct ['status'] assignment",
        ] as $pattern => $label) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                    $violations[] = "{$relativePath}:{$line} {$label}";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function arrayArgumentContainsStatusKey(array $tokens, int $methodIndex, int $count): bool
    {
        $depth = 0;
        $started = false;

        for ($i = $methodIndex + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
                $started = true;

                continue;
            }

            if ($token === ')') {
                $depth--;

                if ($started && $depth === 0) {
                    return false;
                }

                continue;
            }

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (trim($token[1], "'\"") !== 'status') {
                continue;
            }

            $next = $this->nextSignificant($tokens, $i);

            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the receiver (class short name or `$variable`) of a call from the
     * token index of its `->` / `::` operator.
     *
     * @param  array<int, mixed>  $tokens
     */
    private function resolveReceiver(array $tokens, int $operatorIndex): ?string
    {
        $operator = $tokens[$operatorIndex];

        if (is_array($operator) && $operator[0] === T_DOUBLE_COLON) {
            $class = $this->previousSignificant($tokens, $operatorIndex);

            return is_array($class) && in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                ? $this->shortName($class[1])
                : null;
        }

        // `->` operator: walk back over a chained call (Invoice::where(…)->update)
        // until the variable or class that owns the chain. Statement boundaries
        // stop the walk so a previous expression's class can never leak in.
        $depth = 0;

        for ($i = $operatorIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token === ')') {
                $depth++;

                continue;
            }

            if ($token === '(') {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }

                return null;
            }

            if ($depth > 0) {
                continue;
            }

            if ($token === ';' || $token === '{' || $token === '}') {
                return null;
            }

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE) {
                return $token[1];
            }

            if ($token[0] === T_DOUBLE_COLON) {
                $class = $this->previousSignificant($tokens, $i);

                return is_array($class) && in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    ? $this->shortName($class[1])
                    : null;
            }

            if (in_array($token[0], [T_RETURN, T_ECHO, T_PRINT, T_NEW, T_OPEN_TAG], true)) {
                return null;
            }
        }

        return null;
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', ltrim($className, '\\'));

        return end($parts);
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function nextSignificant(array $tokens, int $index): mixed
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function previousSignificant(array $tokens, int $index): mixed
    {
        $position = $this->previousSignificantIndex($tokens, $index);

        return $position === null ? null : $tokens[$position];
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
