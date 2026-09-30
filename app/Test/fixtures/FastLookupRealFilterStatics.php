<?php
// For suites that fake FastLookupFilter: the real class, renamed, so the fake
// delegates its static sizing formulas instead of copying them. Load it
// before the lifecycle stubs, which then keep its exception classes.
$source = file_get_contents(__DIR__ . '/../../Lib/Tools/FastLookupFilter.php');
$renamed = preg_replace('/^class FastLookupFilter$/m', 'class FastLookupRealFilter', $source, -1, $count);
if ($count !== 1) {
    throw new LogicException('FastLookupFilter.php no longer declares its class on a line of its own.');
}
eval('?>' . $renamed);
