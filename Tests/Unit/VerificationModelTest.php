<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Api\Contract\VerificationCase;
use App\Api\Contract\VerificationFinding;
use App\Api\Contract\VerificationReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Verifies stable, body-free evidence and bounded executable case declarations. */
final class VerificationModelTest extends TestCase
{
    public function testFindingAndReportAreDeterministicAndContainNoRuntimePayload(): void
    {
        $findings = [
            new VerificationFinding('warning', 'operation_uncovered', 'z.index'),
            new VerificationFinding('error', 'response_schema_mismatch', 'a.show', 'a.show.case',
                '$.data.id', 'integer', 'string'),
        ];
        $summary = ['public_operations' => 2, 'operations_with_cases' => 1,
            'operations_without_cases' => 1, 'cases_executed' => 1];
        $report = new VerificationReport($summary, $findings);
        $other = new VerificationReport($summary, array_reverse($findings));
        self::assertSame($report->toJson(), $other->toJson());
        self::assertFalse($report->passed());
        self::assertSame(1, $report->exitCode());
        self::assertSame('a.show', $report->toArray()['findings'][0]['operation_id']);
        self::assertStringContainsString('at $.data.id',
            $report->toText());
        self::assertStringNotContainsString('Authorization', $report->toJson());
    }

    public function testWarningOnlyPassesUnlessStrictPolicyIsSelected(): void
    {
        $finding = new VerificationFinding('warning', 'security_unverifiable', 'route.show');
        self::assertTrue((new VerificationReport([], [$finding]))->passed());
        self::assertFalse((new VerificationReport([], [$finding], true))->passed());
    }

    public function testCaseInputRemainsPrivateToTheBuilderAndNamesAreStable(): void
    {
        $case = (new VerificationCase('users.show.success'))
            ->operation('users.show')->route(['id' => 7])
            ->headers(['Authorization' => 'Bearer TEST_ONLY_SECRET'])
            ->json(['private' => 'TEST_ONLY_SECRET'])->expectStatus(200);
        self::assertSame('users.show.success', $case->name());
        self::assertSame('users.show', $case->operationId());
        self::assertSame(['id' => 7], $case->routeParameters());
        self::assertTrue($case->hasJsonBody());
        self::assertSame(200, $case->expectedStatus());
        self::assertFalse($case->isMutation());
    }

    public function testUnsafeUriIsRejectedBeforeExecution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VerificationCase('bad.uri'))->uri('https://elsewhere.test/private');
    }
}
