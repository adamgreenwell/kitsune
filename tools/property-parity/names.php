<?php

declare(strict_types=1);

/*
 * Every property name the grammar may publish, as one JSON list for both readers.
 *
 * ⚠️ THE ALLOWLIST IS READ FROM THE CODE, not transcribed. `Pattern::PORTABLE_PROPERTIES` is the
 * thing under test, and a harness that keeps its own copy of the list tests the copy — the same
 * reason `pattern-parity` puts its cases in one shared file that both engines parse.
 *
 * ⚠️ SCRIPT NAMES COME FROM ICU, because the grammar allowlists `Script=` by PREFIX: any script name
 * is publishable, so every script name has to be swept. ICU knows 213 values, of which 175 compile in
 * both engines and 38 in neither — those 38 are aliases and legacy codes that `compiles()` refuses
 * anyway, and they are reported rather than hidden.
 */

require __DIR__.'/../../vendor/autoload.php';

use Kitsune\Core\Fields\Pattern;

if (! class_exists(IntlChar::class)) {
    fwrite(STDERR, "This harness needs the intl extension to enumerate script names.\n");
    exit(2);
}

/** @var list<string> $properties */
$properties = (new ReflectionClass(Pattern::class))->getConstant('PORTABLE_PROPERTIES');

$names = $properties;

for ($value = 0; $value < 250; $value++) {
    $script = IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, $value, IntlChar::LONG_PROPERTY_NAME);

    if ($script !== false) {
        $names[] = 'Script='.$script;
    }
}

echo json_encode($names, JSON_THROW_ON_ERROR), "\n";
