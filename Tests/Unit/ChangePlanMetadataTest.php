<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangeAction;
use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Changes\ChangeRenderer;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Reviews the optional 21E schema without changing existing plan identity. */
final class ChangePlanMetadataTest extends TestCase
{
    public function testLegacySerializationAndFingerprintAreUnchangedWhenMetadataIsAbsent(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        $hash = hash('sha256', 'new source');
        $plan = new ChangePlan('make:controller', 'Welcome', $owner, [
            new ChangeAction('create', 'Project/Controllers/Welcome.php', $owner,
                null, $hash, 'low', 'Create Controller.'),
        ], [], [], ['Project/Controllers/Welcome.php' => null]);
        $expected = [
            'version' => 1,
            'operation' => 'make:controller',
            'target' => 'Welcome',
            'owner' => ['type' => 'application', 'name' => 'Project'],
            'actions' => [[
                'kind' => 'create', 'subject' => 'Project/Controllers/Welcome.php',
                'owner' => ['type' => 'application', 'name' => 'Project'],
                'before' => null, 'after' => $hash, 'risk' => 'low',
                'reason' => 'Create Controller.',
            ]],
            'warnings' => [], 'conflicts' => [],
            'preconditions' => ['Project/Controllers/Welcome.php' => null],
        ];

        self::assertSame($expected, $plan->toArray());
        self::assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES)), $plan->fingerprint());
    }

    public function testMetadataIsBoundedDeterministicAndParticipatesInIdentity(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        $sourceHash = hash('sha256', 'archive');
        $first = new ChangePlan('bundle:import', 'Project', $owner, [
            new ChangeAction('modify', 'Assets/logo.svg', $owner, hash('sha256', 'old'),
                hash('sha256', 'new'), 'review', 'Replace reviewed asset.', 'asset'),
        ], [], [], ['Assets/logo.svg' => hash('sha256', 'old')],
            new ChangePlanMetadata('bundle', $sourceHash,
                ['secrets_excluded', 'untrusted_source'],
                ['filesystem_case', 'framework_version'],
                ['file_checksum', 'bundle_checksum']));
        $same = new ChangePlan('bundle:import', 'Project', $owner, $first->actions,
            [], [], $first->preconditions,
            new ChangePlanMetadata('bundle', $sourceHash,
                ['untrusted_source', 'secrets_excluded'],
                ['framework_version', 'filesystem_case'],
                ['bundle_checksum', 'file_checksum']));

        self::assertSame($first->fingerprint(), $same->fingerprint());
        self::assertSame('asset', $first->toArray()['actions'][0]['category']);
        self::assertSame('bundle', $first->toArray()['metadata']['source']);
        self::assertSame($sourceHash, $first->toArray()['metadata']['source_sha256']);
        self::assertSame(['bundle_checksum', 'file_checksum'],
            $first->toArray()['metadata']['verification']);
        self::assertSame('review', $first->reviewStatus());
        $render = ChangeRenderer::render($first);
        self::assertStringContainsString('Source: bundle sha256:', $render);
        self::assertStringContainsString('Expected verification: bundle_checksum, file_checksum', $render);
        self::assertStringContainsString('[risk review, category asset]', $render);
        self::assertStringNotContainsString('archive', $render);
    }

    public function testBlockedStatusAndVerificationExpectationDoNotClaimSuccessfulApply(): void
    {
        $owner = new ContributionOwner('package', 'Payments');
        $action = new ChangeAction('delete', 'Project/Packages/Payments/Old.php', $owner,
            hash('sha256', 'old'), null, 'destructive', 'Remove owned file.', 'file');
        $plan = new ChangePlan('package:remove', 'Payments', $owner, [$action], [],
            ['Modified owned file blocks removal.'],
            ['Project/Packages/Payments/Old.php' => hash('sha256', 'old')],
            new ChangePlanMetadata('package', null, ['external_hooks'], [], ['ownership']));

        self::assertSame('destructive', $plan->risk());
        self::assertSame('blocked', $plan->reviewStatus());
        self::assertStringContainsString('Status: BLOCKED', ChangeRenderer::render($plan));
        $unverified = new ChangeResult($plan, [], null, [], false);
        self::assertFalse($unverified->complete());
        self::assertSame($plan->fingerprint(), $unverified->toArray()['plan_sha256']);
        self::assertFalse($unverified->toArray()['verified']);
        self::assertFalse($unverified->toArray()['complete']);
        self::assertSame([], $unverified->toArray()['applied']);
        self::assertNotSame($plan->fingerprint(), (new ChangePlan('package:remove',
            'Payments', $owner, [$action], [], ['Modified owned file blocks removal.'],
            ['Project/Packages/Payments/Old.php' => hash('sha256', 'other')],
            $plan->metadata))->fingerprint());
    }

    public function testOnlyKnownReviewCodesAndActionCategoriesCanEnterMachineOutput(): void
    {
        foreach ([
            static fn (): ChangePlanMetadata => new ChangePlanMetadata('SQUEHUB_SECRET_DO_NOT_LEAK'),
            static fn (): ChangePlanMetadata => new ChangePlanMetadata('bundle', 'SQUEHUB_SECRET_DO_NOT_LEAK'),
            static fn (): ChangePlanMetadata => new ChangePlanMetadata('bundle', null,
                ['SQUEHUB_SECRET_DO_NOT_LEAK']),
            static fn (): ChangePlanMetadata => new ChangePlanMetadata('bundle', null,
                ['untrusted_source', 'untrusted_source']),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid review metadata was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('SQUEHUB_SECRET_DO_NOT_LEAK',
                    $exception->getMessage());
            }
        }
        $owner = new ContributionOwner('application', 'Project');
        $this->expectException(InvalidArgumentException::class);
        new ChangeAction('create', 'Project/New.php', $owner, null,
            hash('sha256', 'content'), 'low', '', 'SQUEHUB_SECRET_DO_NOT_LEAK');
    }

    public function testObviousCredentialAssignmentsAreRejectedFromFreeformReviewText(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        foreach (['APP_KEY=base64:SQUEHUB_SECRET_DO_NOT_LEAK',
            'DB_PASSWORD: SQUEHUB_SECRET_DO_NOT_LEAK'] as $text) {
            try {
                new ChangeAction('create', 'Project/New.php', $owner, null,
                    hash('sha256', 'content'), 'low', $text);
                self::fail('A credential assignment entered an action reason.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('SQUEHUB_SECRET_DO_NOT_LEAK',
                    $exception->getMessage());
            }
            try {
                new ChangePlan('make:controller', 'Welcome', $owner, [], [$text]);
                self::fail('A credential assignment entered a plan warning.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('SQUEHUB_SECRET_DO_NOT_LEAK',
                    $exception->getMessage());
            }
        }
    }

    public function testRepresentativeSubsystemPlansShareOneShapeWithoutSharingExecution(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        foreach (['generator', 'feature', 'package', 'kit', 'bundle', 'upgrade',
            'configuration', 'migration', 'recovery'] as $source) {
            $plan = new ChangePlan('review:' . $source, 'Project', $owner,
                [new ChangeAction('state', 'Project/Activation.json', $owner,
                    null, hash('sha256', $source), 'review', 'Review state change.', 'activation')],
                [], [], [], new ChangePlanMetadata($source, null, [], [], ['activation_state']));
            self::assertSame($source, $plan->toArray()['metadata']['source']);
            self::assertSame('review', $plan->reviewStatus());
        }
    }
}
