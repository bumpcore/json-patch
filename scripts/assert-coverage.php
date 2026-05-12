<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (
    ! is_array($arguments)
    || count($arguments) !== 3
    || ! is_string($arguments[1])
    || ! is_string($arguments[2])
) {
    fwrite(STDERR, "Usage: php scripts/assert-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$coverageFile = $arguments[1];
$minimumCoverage = $arguments[2];

if (! is_file($coverageFile)) {
    fwrite(STDERR, sprintf("Coverage file does not exist: %s\n", $coverageFile));
    exit(1);
}

$coverage = simplexml_load_file($coverageFile);

if ($coverage === false) {
    fwrite(STDERR, sprintf("Coverage file is not readable XML: %s\n", $coverageFile));
    exit(1);
}

$metrics = $coverage->project->metrics;
$coveredStatements = (int) $metrics['coveredstatements'];
$statements = (int) $metrics['statements'];
$coveredMethods = (int) $metrics['coveredmethods'];
$methods = (int) $metrics['methods'];

if ($statements === 0 || $methods === 0) {
    fwrite(STDERR, "Coverage report did not include executable statements and methods.\n");
    exit(1);
}

$lineCoverage = ($coveredStatements / $statements) * 100;
$methodCoverage = ($coveredMethods / $methods) * 100;
$requiredCoverage = (float) $minimumCoverage;

printf("Line coverage: %.2f%%\n", $lineCoverage);
printf("Method coverage: %.2f%%\n", $methodCoverage);

if ($lineCoverage < $requiredCoverage || $methodCoverage < $requiredCoverage) {
    fwrite(STDERR, sprintf("Coverage is below %.2f%%.\n", $requiredCoverage));
    exit(1);
}
