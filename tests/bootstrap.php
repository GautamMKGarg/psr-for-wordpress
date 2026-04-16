<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * PHPUnit bootstrap: starts the php-http test server before the Integration suite
 * and shuts it down automatically when the PHP process exits.
 *
 * The server listens on 127.0.0.1:10000 and serves the fixture directory bundled
 * with php-http/client-integration-tests.
 *
 * The TEST_SERVER environment variable is set so that PHPUnitUtility::getUri()
 * returns the correct URL (the same value is also declared in phpunit.xml.dist as
 * a fallback for when this bootstrap is not loaded).
 */

$fixtureDir = __DIR__ . '/../vendor/php-http/client-integration-tests/fixture';

if (!is_dir($fixtureDir)) {
    // Integration-tests package not installed — skip server startup silently.
    return;
}

$host = '127.0.0.1';
$port = 10000;

// If something is already listening on the port, assume it's the test server.
$probe = @fsockopen($host, $port, $errno, $errstr, 1);
if ($probe !== false) {
    fclose($probe);
    $_SERVER['TEST_SERVER'] = "http://{$host}:{$port}/server.php";
    return;
}

// Start the PHP built-in server as a detached background process.
$command = sprintf(
    '%s -S %s:%d -t %s',
    PHP_BINARY,
    $host,
    $port,
    escapeshellarg(realpath($fixtureDir))
);

$descriptors = [
    0 => ['pipe', 'r'],   // stdin
    1 => ['file', sys_get_temp_dir() . '/php-http-test-server.log', 'a'],
    2 => ['file', sys_get_temp_dir() . '/php-http-test-server.log', 'a'],
];

$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    throw new \RuntimeException("Failed to start the PHP HTTP test server.\nCommand: {$command}");
}

// Close stdin so the server doesn't block waiting for input.
fclose($pipes[0]);

// Wait up to 5 seconds for the server to accept connections.
$started = false;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000); // 100 ms
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
        "Check " . sys_get_temp_dir() . '/php-http-test-server.log for details.'
    );
}

// Kill the server when PHP exits (covers normal exit AND fatal errors).
register_shutdown_function(static function () use ($process): void {
    proc_terminate($process);
    proc_close($process);
});

$_SERVER['TEST_SERVER'] = "http://{$host}:{$port}/server.php";
