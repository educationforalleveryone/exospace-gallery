<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use RuntimeException;

/**
 * Minimal, dependency-free JUnit XML parser for PHPUnit artifacts.
 *
 * Supports the PHPUnit 9.3+/11 `<testsuites>` document shape:
 *   <testsuites>
 *     <testsuite name="..." tests="" assertions="" errors="" failures=""
 *                skipped="" time="">
 *       <testcase name="test_x" class="Tests\Feature\A" classname="Tests.Feature.A"
 *                 file="..." line=".." time="..">
 *         <failure type="...">message\n\nstack</failure>
 *         <error ...>…</error>
 *         <skipped>…</skipped>
 *         <warning>…</warning>
 *       </testcase>
 *
 * Data-provider cases appear as `test_x with data set "foo" (…)`.
 */
class JunitParser
{
    /**
     * @return array{
     *   totals: array{tests:int, assertions:int, failures:int, errors:int, skipped:int, warnings:int, time:float},
     *   cases:  list<array{identifier:string, classname:string, name:string, data_set:?string,
     *                      status:string, time_ms:int|null, message:?string, detail:?string, exception_class:?string}>
     * }
     *
     * @throws RuntimeException when XML is unreadable/malformed
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("JUnit artifact not found: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException("JUnit artifact empty or unreadable: {$path}");
        }

        // PHPUnit writes raw bytes; suppress libxml entity issues from system-out noise.
        $prev = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contents);
            if ($xml === false) {
                throw new RuntimeException('Malformed JUnit XML: '.implode('; ', array_slice(libxml_get_errors(), 0, 3)));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $this->parseNode($xml);
    }

    public function parseString(string $junitXml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($junitXml);
            if ($xml === false) {
                throw new RuntimeException('Malformed JUnit XML payload.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $this->parseNode($xml);
    }

    private function parseNode(\SimpleXMLElement $root): array
    {
        $totals = [
            'tests'      => 0,
            'assertions' => 0,
            'failures'   => 0,
            'errors'     => 0,
            'skipped'    => 0,
            'warnings'   => 0,
            'time'       => 0.0,
        ];

        /** @var list<array> $cases */
        $cases = [];

        // ⚠ Only TOP-LEVEL suites may feed totals: PHPUnit's <testsuite>
        // hierarchy already AGGREGATES its descendants (suite → class suites
        // → data-provider groups). Counting every level triple-counts a run.
        // Cases are collected across all depths of each top-level branch.
        $suites = $root->getName() === 'testsuites'
            ? $root->xpath('./testsuite')
            : [$root];

        foreach ($suites as $suite) {
            $totals['tests']      += (int) ($suite['tests'] ?? 0);
            $totals['assertions'] += (int) ($suite['assertions'] ?? 0);
            $totals['failures']   += (int) ($suite['failures'] ?? 0);
            $totals['errors']     += (int) ($suite['errors'] ?? 0);
            $totals['skipped']    += (int) ($suite['skipped'] ?? 0);
            $totals['warnings']   += (int) ($suite['warnings'] ?? 0);
            $totals['time']       += (float) ($suite['time'] ?? 0);

            foreach ($suite->xpath('.//testcase') ?: [] as $case) {
                $cases[] = $this->normalizeCase($case);
            }
        }

        usort($cases, fn ($a, $b) => [$a['classname'], $a['name']] <=> [$b['classname'], $b['name']]);

        return ['totals' => $totals, 'cases' => $cases];
    }

    private function normalizeCase(\SimpleXMLElement $case): array
    {
        $name       = (string) $case['name'];
        $classAttr  = (string) ($case['class'] ?? '');
        $classNice  = str_replace('.', '\\', (string) ($case['classname'] ?? $classAttr));

        // PHPUnit emits `test_x with data set "set1" (#1)` — split cleanly.
        $dataSet = null;
        if (preg_match('/\s+with\s+data\s+set\s+"([^"]*)"/u', $name, $m)) {
            $dataSet = $m[1];
        }

        [$status, $message, $detail, $exceptionClass] = $this->extractOutcome($case);

        return [
            'identifier'      => $this->identifier($classNice, $name),
            'classname'       => $classNice,
            'name'            => $name,
            'data_set'        => $dataSet,
            'status'          => $status,
            'time_ms'         => isset($case['time']) ? (int) round(((float) $case['time']) * 1000) : null,
            'message'         => $message !== null ? mb_substr($message, 0, 2000) : null,
            'detail'          => $detail !== null ? mb_substr($detail, 0, 60000) : null,
            'exception_class' => $exceptionClass,
        ];
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} status, message, detail, exception class */
    private function extractOutcome(\SimpleXMLElement $case): array
    {
        $outcome = $case->xpath('./failure');
        if ($outcome !== [] && count($outcome)) {
            /** @var \SimpleXMLElement $f */
            $f = $outcome[0];

            return ['failed', trim((string) $f), (string) $f, (string) ($f['type'] ?? '') ?: null];
        }

        $outcome = $case->xpath('./error');
        if ($outcome !== [] && count($outcome)) {
            $e = $outcome[0];
            $type = (string) ($e['type'] ?? '');

            return ['error', trim((string) $e), (string) $e, $type ?: null];
        }

        $outcome = $case->xpath('./skipped');
        if ($outcome !== [] && count($outcome)) {
            $s = $outcome[0];

            return ['skipped', trim((string) $s) ?: null, null, null];
        }

        $outcome = $case->xpath('./warning');
        if ($outcome !== [] && count($outcome)) {
            $w = $outcome[0];

            return ['warning', trim((string) $w), null, (string) ($w['type'] ?? '') ?: null];
        }

        return ['passed', null, null, null];
    }

    /**
     * Stable cross-run identifier. Trailing "(#N)" removed so a data-set row
     * reorders consistently; duplicates keyed by data_set column instead.
     */
    private function identifier(string $classname, string $name): string
    {
        $method = preg_replace('/^([^\s]+).*$/', '$1', $name); // strip data-set suffix text

        return $classname.'::'.$method;
    }
}
