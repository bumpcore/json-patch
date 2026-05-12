<?php

declare(strict_types=1);

$rootDirectory = dirname(__DIR__);
$coverageDirectory = $rootDirectory . '/coverage';
$coverageFile = $coverageDirectory . '/clover.xml';
$phpunit = $rootDirectory . '/vendor/bin/phpunit';

if (! is_dir($coverageDirectory) && ! mkdir($coverageDirectory, 0777, true) && ! is_dir($coverageDirectory)) {
    fwrite(STDERR, sprintf("Coverage directory could not be created: %s\n", $coverageDirectory));
    exit(1);
}

$phpunitArguments = [
    '--coverage-clover',
    $coverageFile,
    '--coverage-text',
    '--show-uncovered-for-coverage-text',
];

if (PHP_SAPI === 'phpdbg' || extension_loaded('pcov') || extension_loaded('xdebug')) {
    $exitCode = runCommand(array_merge([PHP_BINARY, $phpunit], $phpunitArguments), $rootDirectory);
} else {
    $phpdbg = findExecutable('phpdbg');

    if ($phpdbg === null) {
        fwrite(STDERR, "No PHPUnit coverage driver is available. Install PCOV, Xdebug, or phpdbg.\n");
        exit(1);
    }

    $exitCode = runCommand(array_merge([$phpdbg, '-qrr', $phpunit], $phpunitArguments), $rootDirectory);
}

if ($exitCode !== 0) {
    exit($exitCode);
}

exit(runCommand([PHP_BINARY, $rootDirectory . '/scripts/assert-coverage.php', $coverageFile, '100'], $rootDirectory));

/**
 * Runs a command and streams its output to the current process.
 *
 * @param list<string> $command
 */
function runCommand(array $command, string $workingDirectory): int
{
    $process = proc_open(
        $command,
        [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
        $workingDirectory,
    );

    if (! is_resource($process)) {
        fwrite(STDERR, sprintf("Could not start command: %s\n", implode(' ', $command)));

        return 1;
    }

    return proc_close($process);
}

/**
 * Finds an executable on the current PATH.
 */
function findExecutable(string $name): ?string
{
    $command = stripos(PHP_OS_FAMILY, 'Windows') === 0
        ? ['where', $name]
        : ['which', $name];

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $output = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0 || $output === '') {
        return null;
    }

    return explode(PHP_EOL, $output)[0];
}
