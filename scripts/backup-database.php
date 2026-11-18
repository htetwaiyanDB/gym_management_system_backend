<?php

// Run as the deployment user. Never place database passwords in argv or logs.
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$destination = $argv[1] ?? '';
if ($destination === '' || file_exists($destination)) {
    throw new RuntimeException('A new backup destination is required.');
}

$connection = config('database.default');
$database = app('db')->connection($connection)->getConfig();
if (($database['driver'] ?? '') !== 'mysql') {
    throw new RuntimeException('Production backup supports MySQL/MariaDB only; refusing migration.');
}

umask(0077);
$options = tempnam(sys_get_temp_dir(), 'gym-db-');
if ($options === false) {
    throw new RuntimeException('Cannot create private backup credentials file.');
}

$quote = static fn ($value): string => '"'.str_replace(
    ['\\', '"', "\n", "\r"],
    ['\\\\', '\\"', '\\n', '\\r'],
    (string) $value,
).'"';

$process = null;
$output = null;
try {
    $settings = "[client]\n";
    foreach (['host' => 'host', 'port' => 'port', 'username' => 'user', 'password' => 'password', 'unix_socket' => 'socket'] as $key => $option) {
        if (isset($database[$key]) && $database[$key] !== '') {
            $settings .= $option.'='.$quote($database[$key])."\n";
        }
    }
    if (file_put_contents($options, $settings) === false) {
        throw new RuntimeException('Cannot write backup connection options.');
    }
    $command = ['mysqldump', '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--no-tablespaces', '--databases', $database['database']];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start database backup.');
    }
    $output = gzopen($destination, 'wb6');
    if ($output === false) {
        throw new RuntimeException('Cannot open compressed backup destination.');
    }
    $bytes = 0;
    while (! feof($pipes[1])) {
        $chunk = fread($pipes[1], 1048576);
        if ($chunk === false || gzwrite($output, $chunk) !== strlen($chunk)) {
            throw new RuntimeException('Database backup write failed.');
        }
        $bytes += strlen($chunk);
    }
    fclose($pipes[1]);
    if (! gzclose($output)) {
        $output = null;
        throw new RuntimeException('Compressed backup finalization failed.');
    }
    $output = null;
    $status = proc_close($process);
    $process = null;
    if ($status !== 0 || $bytes === 0) {
        throw new RuntimeException('Database backup failed; migrations were not started.');
    }
    echo "Database backup completed.\n";
} catch (Throwable $error) {
    if (is_resource($output)) {
        gzclose($output);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_file($destination)) {
        unlink($destination);
    }
    throw $error;
} finally {
    unlink($options);
}