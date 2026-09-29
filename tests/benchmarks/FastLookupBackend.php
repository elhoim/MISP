<?php
/**
 * The server behind a benchmark's Redis connection: its name, version and
 * loaded modules ('bf' is the Bloom module on both Redis and Valkey).
 */
function fastLookupIsFilterKey(string $key): bool
{
    return preg_match('/^g:[^:]+:bf(:\d+)?$/', $key) === 1;
}

function fastLookupBackendVersions($redis): array
{
    $server = $redis->info('server');
    $server = is_array($server) ? $server : [];
    $valkey = isset($server['valkey_version']);
    $modules = null;
    try {
        $list = $redis->rawCommand('MODULE', 'LIST');
        if (is_array($list)) {
            $modules = [];
            foreach ($list as $module) {
                if (!is_array($module)) {
                    continue;
                }
                $fields = [];
                for ($i = 0; $i + 1 < count($module); $i += 2) {
                    $fields[$module[$i]] = $module[$i + 1];
                }
                if (isset($fields['name'])) {
                    $modules[$fields['name']] = $fields['ver'] ?? null;
                }
            }
            ksort($modules);
        }
    } catch (Throwable $e) {
        // A refused listing leaves the modules unknown (null), not none.
    }
    return [
        'backend' => $valkey ? 'valkey' : 'redis',
        'backend_version' => $valkey
            ? $server['valkey_version'] : ($server['redis_version'] ?? null),
        'redis' => $server['redis_version'] ?? null,
        'modules' => $modules,
    ];
}
