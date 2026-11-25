<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

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

require_once __DIR__.'/../../vendor/autoload.php';

use Kitsune\Core\Fields\Pattern;

function parityPropertyCorpus(): string
{
    if (! class_exists(IntlChar::class)) {
        throw new InvalidArgumentException('This harness needs the intl extension to enumerate script names.');
    }

    /** @var list<string> $properties */
    $properties = (new ReflectionClass(Pattern::class))->getConstant('PORTABLE_PROPERTIES');

    $names = $properties;
    $max = IntlChar::getIntPropertyMaxValue(IntlChar::PROPERTY_SCRIPT);

    for ($value = 0; $value <= $max; $value++) {
        $script = IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, $value, IntlChar::LONG_PROPERTY_NAME);

        if (is_string($script) && $script !== '') {
            $names[] = 'Script='.$script;
        }
    }

    if ($names === [] || count($names) !== count(array_unique($names))) {
        throw new InvalidArgumentException('The property corpus must contain unique names.');
    }

    return json_encode(['icu_version' => INTL_ICU_VERSION, 'names' => $names], JSON_THROW_ON_ERROR)."\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo parityPropertyCorpus();
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(2);
    }
}
