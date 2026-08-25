<?php
/**
 * Per-request coverage collector for MISP's live test suite.
 *
 * The live suite drives MISP over HTTP, so PHPUnit cannot attribute its
 * coverage. This hooks pcov into every request and CLI invocation instead.
 *
 * Enable by setting, for BOTH the web SAPI and CLI:
 *     pcov.enabled=1
 *     pcov.directory=/var/www/MISP/app
 *     auto_prepend_file=/var/www/MISP/build/coverage/covcollect.php
 *
 * Collection starts only once $MISP_COV_DIR/ENABLED exists, so instance
 * setup (schema import, runUpdates, updateJSON) is not counted as coverage.
 *
 * Env:
 *   MISP_COV_DIR   where captures are written        (default /cov)
 *   MISP_APP_ROOT  app root prefix files must match  (default /var/www/MISP/app/)
 */

$covDir = getenv('MISP_COV_DIR') ?: '/cov';

if (extension_loaded('pcov') && @file_exists($covDir . '/ENABLED')) {
    \pcov\start();
    register_shutdown_function(function () use ($covDir) {
        \pcov\stop();

        // \pcov\inclusive matches exact file paths, not directories, so
        // collect everything and filter here.
        $data = \pcov\collect(\pcov\all);
        $appRoot = getenv('MISP_APP_ROOT') ?: '/var/www/MISP/app/';

        $hit = [];
        foreach ($data as $file => $lines) {
            if (strpos($file, $appRoot) !== 0)            { continue; }
            if (strpos($file, '/Vendor/') !== false)      { continue; }
            if (strpos($file, '/Lib/cakephp/') !== false) { continue; }
            $covered = [];
            foreach ($lines as $line => $count) {
                if ($count > 0) { $covered[] = $line; }
            }
            if ($covered) { $hit[$file] = $covered; }
        }

        if ($hit) {
            @file_put_contents(
                sprintf('%s/%d-%s.json', $covDir, getmypid(), bin2hex(random_bytes(6))),
                json_encode($hit)
            );
        }
    });
}
