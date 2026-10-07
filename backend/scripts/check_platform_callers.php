<?php

/**
 * #904 AC4 — flag platform classes (backend/app/Platform/**) that nothing calls.
 *
 * Pure static scan: no Laravel boot, no composer autoload, no database.
 *
 * How "called" is decided (deterministic, comment-stripped via token_get_all):
 *   1. Every class/interface/trait/enum declared under app/Platform is a node.
 *   2. A node is a ROOT when its FQCN (or a `use App\Platform\X\{A, B}` group
 *      import) appears in non-test, non-Platform PHP code under app/, modules/,
 *      routes/, config/, bootstrap/ or database/. Files under any `tests/`
 *      directory are ignored. PlatformServiceProvider is itself a root (it is
 *      registered by the framework), so a binding/subscription there counts.
 *   3. Platform classes reachable from a root through references inside
 *      app/Platform (FQCN, same-namespace short name, or relative name such as
 *      `Contracts\Foo`) are also considered called.
 *   4. Everything else is UNCALLED.
 *
 * The result is compared with scripts/platform-uncalled-allowlist.json:
 *   - an uncalled class NOT in the allowlist           -> FAIL (exit 1)
 *   - an allowlisted class that now has a caller        -> warning (remove it)
 *   - an allowlisted class that no longer exists        -> warning (remove it)
 *
 * Usage: php scripts/check_platform_callers.php [--list]
 *   --list  print every uncalled class as JSON (for seeding the allowlist)
 */

$backend = realpath(__DIR__.'/..');
$platformDir = $backend.'/app/Platform';
$allowlistPath = __DIR__.'/platform-uncalled-allowlist.json';
$listMode = in_array('--list', $argv, true);

/** Return PHP source with comments and whitespace tokens removed. */
function strip_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= ' ';
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }

    // Normalise escaped backslashes in string literals ('App\\Platform\\X').
    return str_replace('\\\\', '\\', $out);
}

function php_files(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $files[] = $f->getPathname();
        }
    }
    sort($files);

    return $files;
}

function is_test_path(string $rel): bool
{
    return (bool) preg_match('#(^|/)(tests|Tests)/#', $rel);
}

function ref_pattern(string $needle): string
{
    return '/(?<![A-Za-z0-9_\\\\])\\\\?'.preg_quote($needle, '/').'(?![A-Za-z0-9_])/';
}

// ---- 1. Collect platform declarations -------------------------------------
$classes = [];   // fqcn => ['file' => rel, 'ns' => ns, 'short' => name, 'src' => stripped]
foreach (php_files($platformDir) as $path) {
    $src = strip_comments(file_get_contents($path));
    $rel = substr($path, strlen($backend) + 1);
    if (! preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $src, $m)) {
        continue;
    }
    $ns = $m[1];
    preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $src, $mm);
    foreach ($mm[1] as $short) {
        $classes[$ns.'\\'.$short] = ['file' => $rel, 'ns' => $ns, 'short' => $short, 'src' => $src];
    }
}
ksort($classes);

// ---- 2. Roots: references from non-Platform, non-test code -----------------
$roots = [];
$rootSites = [];
foreach (['app', 'modules', 'routes', 'config', 'bootstrap', 'database'] as $top) {
    foreach (php_files($backend.'/'.$top) as $path) {
        $rel = substr($path, strlen($backend) + 1);
        if (str_starts_with($rel, 'app/Platform/') || is_test_path($rel)) {
            continue;
        }
        $raw = file_get_contents($path);
        if (strpos($raw, 'Platform') === false) {
            continue;
        }
        $src = strip_comments($raw);
        // Expand group imports: use App\Platform\X\{A, B as C};
        $groupNames = [];
        if (preg_match_all('/use\s+\\\\?(App\\\\Platform\\\\[A-Za-z0-9_\\\\]*?)\\\\?\{([^}]*)\}/', $src, $gm, PREG_SET_ORDER)) {
            foreach ($gm as $g) {
                foreach (explode(',', $g[2]) as $part) {
                    $name = trim(preg_split('/\s+as\s+/i', trim($part))[0]);
                    if ($name !== '') {
                        $groupNames[rtrim($g[1], '\\').'\\'.$name] = true;
                    }
                }
            }
        }
        foreach ($classes as $fqcn => $_) {
            if (isset($groupNames[$fqcn]) || preg_match(ref_pattern($fqcn), $src)) {
                $roots[$fqcn] = true;
                $rootSites[$fqcn] ??= $rel;
            }
        }
    }
}
$roots['App\\Platform\\PlatformServiceProvider'] = true;

// ---- 3. Reachability through references inside app/Platform ---------------
$edges = [];
foreach ($classes as $from => $info) {
    $edges[$from] = [];
    foreach ($classes as $to => $target) {
        if ($to === $from) {
            continue;
        }
        $hit = preg_match(ref_pattern($to), $info['src']);
        if (! $hit && $target['ns'] === $info['ns']) {
            $hit = preg_match('/(?<![A-Za-z0-9_\\\\$>:])'.preg_quote($target['short'], '/').'(?![A-Za-z0-9_])/', $info['src']);
        }
        if (! $hit && str_starts_with($to, $info['ns'].'\\')) {
            $relName = substr($to, strlen($info['ns']) + 1);
            if (str_contains($relName, '\\')) {
                $hit = preg_match('/(?<![A-Za-z0-9_\\\\])'.preg_quote($relName, '/').'(?![A-Za-z0-9_])/', $info['src']);
            }
        }
        if ($hit) {
            $edges[$from][] = $to;
        }
    }
}
$reached = [];
$queue = array_keys(array_intersect_key($roots, $classes));
while ($queue) {
    $c = array_shift($queue);
    if (isset($reached[$c])) {
        continue;
    }
    $reached[$c] = true;
    foreach ($edges[$c] ?? [] as $n) {
        if (! isset($reached[$n])) {
            $queue[] = $n;
        }
    }
}
$uncalled = array_values(array_diff(array_keys($classes), array_keys($reached)));
sort($uncalled);

if ($listMode) {
    echo json_encode(array_fill_keys($uncalled, 'TODO reason / issue'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

// ---- 4. Compare with the allowlist ----------------------------------------
$allow = is_file($allowlistPath) ? json_decode(file_get_contents($allowlistPath), true) : null;
if (! is_array($allow) || ! isset($allow['classes']) || ! is_array($allow['classes'])) {
    fwrite(STDERR, "FAIL: cannot read {$allowlistPath} (expected {\"classes\": {FQCN: reason}})\n");
    exit(1);
}
$allowed = $allow['classes'];

$newUncalled = array_values(array_filter($uncalled, fn ($c) => ! array_key_exists($c, $allowed)));
$nowCalled = array_values(array_filter(array_keys($allowed), fn ($c) => isset($classes[$c]) && isset($reached[$c])));
$gone = array_values(array_filter(array_keys($allowed), fn ($c) => ! isset($classes[$c])));

printf("Platform caller check: %d classes, %d called, %d uncalled (%d allowlisted).\n",
    count($classes), count($reached), count($uncalled), count($uncalled) - count($newUncalled));

foreach ($nowCalled as $c) {
    echo "::warning::{$c} is allowlisted as uncalled but now has a caller — remove it from scripts/platform-uncalled-allowlist.json\n";
}
foreach ($gone as $c) {
    echo "::warning::{$c} is allowlisted but no longer exists — remove it from scripts/platform-uncalled-allowlist.json\n";
}

if ($newUncalled) {
    echo "FAIL: new platform classes with no callers outside app/Platform (tests do not count):\n";
    foreach ($newUncalled as $c) {
        echo "  - {$c}  ({$classes[$c]['file']})\n";
    }
    echo "Wire each one into a real consumer, or (with an owner + issue) add it to scripts/platform-uncalled-allowlist.json.\n";
    echo "See docs/architecture-decisions/2026-10-07-platform-services-adoption-register.md (#904).\n";
    exit(1);
}

echo "OK: no new uncalled platform classes.\n";
exit(0);
