<?php

declare(strict_types=1);

namespace Tests\Unit\TestCenter;

use App\Services\TestCenter\JunitParser;
use PHPUnit\Framework\TestCase;

class JunitParserTest extends TestCase
{
    private function artifact(string $cases, string $suiteAttrs): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
            <testsuite name="Feature" {$suiteAttrs}>
                {$cases}
            </testsuite>
        </testsuites>
        XML;
    }

    public function test_parses_totals_and_passing_case(): void
    {
        $xml = $this->artifact(
            '<testcase name="test_ok" class="Tests\Feature\A" classname="Tests.Feature.A" time="0.250"/>',
            'tests="1" assertions="5" errors="0" failures="0" skipped="0" time="0.25"'
        );

        $parsed = (new JunitParser)->parseString($xml);

        $this->assertSame(1, $parsed['totals']['tests']);
        $this->assertSame(5, $parsed['totals']['assertions']);
        $this->assertSame('Tests\Feature\A::test_ok', $parsed['cases'][0]['identifier']);
        $this->assertSame('passed', $parsed['cases'][0]['status']);
        $this->assertSame(250, $parsed['cases'][0]['time_ms']);
    }

    public function test_extracts_failure_message_and_type(): void
    {
        $xml = $this->artifact(
            '<testcase name="test_boom" class="Tests\Feature\B" classname="Tests.Feature.B" time="1.0">'
            .'<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 100 is identical to 101.</failure>'
            .'</testcase>',
            'tests="1" assertions="1" errors="0" failures="1" skipped="0" time="1.0"'
        );

        $case = (new JunitParser)->parseString($xml)['cases'][0];

        $this->assertSame('failed', $case['status']);
        $this->assertStringContainsString('100 is identical to 101', (string) $case['message']);
        $this->assertSame('PHPUnit\Framework\ExpectationFailedException', $case['exception_class']);
    }

    public function test_classifies_error_skipped_and_data_sets(): void
    {
        $xml = $this->artifact(
            '<testcase name="test_conn with data set &quot;mysql&quot; (#0)" class="Tests\C" classname="Tests.C" time="2">'.
              '<error type="PDOException">SQLSTATE[HY000] connection refused</error></testcase>'.
            '<testcase name="test_later" class="Tests\C" classname="Tests.C" time="3"><skipped>TODO</skipped></testcase>',
            'tests="2" assertions="0" errors="1" failures="0" skipped="1" time="5"'
        );

        [$conn, $skipped] = (new JunitParser)->parseString($xml)['cases'];

        $this->assertSame('error', $conn['status']);
        $this->assertSame('mysql', $conn['data_set']);                    // data-set extracted
        $this->assertSame('Tests\C::test_conn', $conn['identifier']);     // suffix stripped → stable key
        $this->assertSame('skipped', $skipped['status']);

        // Failure-intelligence support: infra signature detectable from message.
        $model = new \App\Models\QaTestCaseResult();
        $model->forceFill(['status' => 'error', 'message' => $conn['message'], 'detail' => null]);
        $this->assertSame('infrastructure', $model->failureClass());
    }

    public function test_rejects_malformed_xml_instead_of_zero_result(): void
    {
        $this->expectException(\RuntimeException::class);
        (new JunitParser)->parseString('<?xml version="1.0"?><testsuites><oops>');
    }
}
