<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the WordPress Integration test suite.
 *
 * This file must NOT be used for the regular (non-WP) test suites.
 * Loading it defines wp_remote_request() and related stubs in the global
 * namespace, which would cause Psr18Client to take the WordPress code path
 * for every test — including the non-WP integration tests.
 *
 * Run the WP suite with its own config:
 *
 *   vendor/bin/phpunit -c phpunit-wp.xml.dist
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load WordPress HTTP function stubs (wp_remote_request, is_wp_error, WP_Error).
require_once __DIR__ . '/WpFunctions.php';

/**
 * Start the php-http/client-integration-tests fixture server on 127.0.0.1:10000.
 * Identical to the logic in tests/bootstrap.php.
 */
$fixtureDir = __DIR__ . '/../vendor/php-http/client-integration-tests/fixture';

if (!is_dir($fixtureDir)) {
    return;
}

$host = '127.0.0.1';
$port = 10000;

$probe = @fsockopen($host, $port, $errno, $errstr, 1);
if ($probe !== false) {
    fclose($probe);
    $_SERVER['TEST_SERVER'] = "http://{$host}:{$port}/server.php";
    return;
}

$command = sprintf(
    '%s -S %s:%d -t %s',
    PHP_BINARY,
    $host,
    $port,
    escapeshellarg(realpath($fixtureDir))
);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', sys_get_temp_dir() . '/php-http-test-server-wp.log', 'a'],
    2 => ['file', sys_get_temp_dir() . '/php-http-test-server-wp.log', 'a'],
];

$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    throw new \RuntimeException("Failed to start the PHP HTTP test server.\nCommand: {$command}");
}

fclose($pipes[0]);

$started = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000);
    $probe = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($probe !== false) {
        fclose($probe);
        $started = true;
        break;
    }
}

if (!$started) {
    proc_terminate($process);
    proc_close($process);
    throw new \RuntimeException(
        "PHP HTTP test server did not start within 5 seconds on {$host}:{$port}.\n" .
        "Check " . sys_get_temp_dir() . '/php-http-test-server-wp.log for details.'
    );
}

register_shutdown_function(static function () use ($process): void {
    proc_terminate($process);
    proc_close($process);
});

$_SERVER['TEST_SERVER'] = "http://{$host}:{$port}/server.php";
