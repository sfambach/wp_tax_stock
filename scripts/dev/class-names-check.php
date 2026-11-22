<?php declare(strict_types=1);

/**
 * Live-Check 31 (22.11.): a short class name the file neither imports nor finds beside it fails only when the line runs —
 * the picker's places answer died on `ListPage::number` with HTTP 500 and showed «Liegt nirgends frei». This finds each
 * `Name::` and `new Name` in src/ that resolves to nothing, without WordPress.
 *
 *     php scripts/dev/class-names-check.php
 */

$root = dirname(__DIR__, 2) . '/src';
$red  = 0;

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $code     = (string) file_get_contents($file->getPathname());
    $imported = [];
    preg_match_all('/^use\s+(?:function\s+|const\s+)?[\\\\\w]+?\\\\(\w+)(?:\s+as\s+(\w+))?;/m', $code, $uses, PREG_SET_ORDER);

    foreach ($uses as $use) {
        $imported[$use[2] ?? $use[1]] = true;
    }

    // Strings and comments name classes in prose; only code counts.
    $bare = '';

    foreach (token_get_all($code) as $token) {
        $bare .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) ? ' ' : $token[1]) : $token;
    }

    preg_match_all('/(?<![\\\\\w$>])(?:new\s+)?([A-Z]\w*)::|(?<![\\\\\w$])new\s+([A-Z]\w*)\b/', $bare, $hits, PREG_SET_ORDER);
    $beside = dirname($file->getPathname());

    foreach ($hits as $hit) {
        $name = $hit[1] !== '' ? $hit[1] : ($hit[2] ?? '');

        if ($name === '' || isset($imported[$name]) || is_file("{$beside}/{$name}.php") || class_exists($name, false) || interface_exists($name, false)) {
            continue;
        }

        if (preg_match('/\b(?:class|interface|trait|enum)\s+' . $name . '\b/', $bare)) {
            continue;
        }

        printf("  FAIL %s: %s is neither imported nor beside it\n", substr($file->getPathname(), strlen($root) + 1), $name);
        $red++;
    }
}

echo $red === 0 ? "green\n" : "{$red} red\n";
exit($red === 0 ? 0 : 1);
