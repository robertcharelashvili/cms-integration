<?php
/**
 * Every `$this->foo(...)` in the library must resolve to a method that exists.
 *
 * `Rilven_clearing` called `instant()`, `comment()` and `paymentComment()` and declared none of
 * them. `php -l` is happy -- an undefined method is a RUNTIME fatal, not a parse error -- and the
 * register was switched off, so nothing executed it. A dry run measured the SQL by hand and
 * passed, which is exactly the gap: the numbers were right about a code path that could not run.
 *
 *     php tools/check-methods.php
 *
 * Exits non-zero and names every unresolved call. Parent classes are followed, so a subclass
 * calling something it inherits is fine.
 */

$root = dirname(__DIR__) . '/application/libraries';
$files = glob($root . '/*.php');

$declared = array();   // class => [method => true]
$parent   = array();   // class => parent
$source   = array();   // class => file

foreach ($files as $file) {
    $code = file_get_contents($file);
    if (!preg_match('/\bclass\s+(\w+)(?:\s+extends\s+(\w+))?/', $code, $m)) {
        continue;
    }
    $class = $m[1];
    $source[$class] = basename($file);
    $parent[$class] = isset($m[2]) ? $m[2] : NULL;

    preg_match_all('/function\s+(\w+)\s*\(/', $code, $found);
    $declared[$class] = array_fill_keys($found[1], TRUE);
}

/** Does $class, or anything it extends, declare $method? */
function resolves($class, $method, $declared, $parent) {
    $seen = array();
    while ($class !== NULL && !isset($seen[$class])) {
        $seen[$class] = TRUE;
        if (isset($declared[$class][$method])) {
            return TRUE;
        }
        // A parent outside this directory (CI_Model and friends) cannot be checked, so stop
        // claiming to know and let it pass rather than report a false one.
        if (!isset($declared[$class])) {
            return TRUE;
        }
        $class = isset($parent[$class]) ? $parent[$class] : NULL;
    }
    return FALSE;
}

$problems = 0;
foreach ($files as $file) {
    $code = file_get_contents($file);
    if (!preg_match('/\bclass\s+(\w+)/', $code, $m)) {
        continue;
    }
    $class = $m[1];

    $lines = explode("\n", $code);
    foreach ($lines as $n => $line) {
        if (!preg_match_all('/\$this->(\w+)\s*\(/', $line, $calls)) {
            continue;
        }
        foreach ($calls[1] as $method) {
            if (resolves($class, $method, $declared, $parent)) {
                continue;
            }
            printf("%s:%d  %s->%s() is never declared\n",
                   basename($file), $n + 1, $class, $method);
            $problems++;
        }
    }
}

if ($problems > 0) {
    printf("\n%d unresolved call(s).\n", $problems);
    exit(1);
}
echo "every \$this-> call resolves\n";
