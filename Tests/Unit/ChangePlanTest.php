<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The shared review artifact stays deterministic, bounded, and content-free. */
final class ChangePlanTest extends TestCase
{
    public function testOrderingDoesNotChangePlanIdentityAndRiskIsTheHighestActionRisk(): void
    {
        $owner = new ContributionOwner('package', 'Weather');
        $create = new ChangeAction('create', 'Project/Packages/Weather/New.php', $owner,
            null, hash('sha256', 'new'), 'low');
        $delete = new ChangeAction('delete', 'Project/Packages/Weather/Old.php', $owner,
            hash('sha256', 'old'), null, 'destructive');
        $first = new ChangePlan('package:upgrade', 'Weather', $owner,
            [$create, $delete], ['Review migrations.', 'Refresh autoload.'],
            [], ['Project/Activation.json' => hash('sha256', 'state')]);
        $second = new ChangePlan('package:upgrade', 'Weather', $owner,
            [$delete, $create], ['Refresh autoload.', 'Review migrations.'],
            [], ['Project/Activation.json' => hash('sha256', 'state')]);

        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame('destructive', $first->risk());
        self::assertFalse($first->hasConflicts());
        self::assertSame('Project/Packages/Weather/New.php', $first->toArray()['actions'][0]['subject']);
        self::assertStringNotContainsString('old', ChangeRenderer::render($first));
        self::assertStringContainsString('DELETE', ChangeRenderer::render($first));
        self::assertStringContainsString('owner package:Weather, before sha256:',
            ChangeRenderer::render($first));
    }

    public function testConflictsAndPartialResultAreDistinctFromWarnings(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        $action = new ChangeAction('create', 'Project/Controllers/UserController.php', $owner,
            null, hash('sha256', '<?php'), 'low');
        $plan = new ChangePlan('make:controller', 'UserController', $owner, [$action],
            ['Review generated code.'], ['Target already exists.'],
            ['Project/Controllers/UserController.php' => null]);
        $result = new ChangeResult($plan, [], $action, [], false);

        self::assertTrue($plan->hasConflicts());
        self::assertFalse($result->complete());
        self::assertStringContainsString('WARNING Review generated code.', ChangeRenderer::render($plan));
        self::assertStringContainsString('CONFLICT Target already exists.', ChangeRenderer::render($plan));
        self::assertSame(0, count($result->applied));
        self::assertSame($action, $result->failed);
    }

    public function testPrivateOrUnsafePathsCannotEnterPortableActions(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        foreach (['C:/private/secret.php', '/tmp/secret.php', '../escape.php',
            'Project/../escape.php', "Project/Secret\n.php"] as $subject) {
            try {
                new ChangeAction('create', $subject, $owner, null, hash('sha256', 'safe'), 'low');
                self::fail('Unsafe subject was accepted: ' . $subject);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFingerprintFieldsCannotCarryContentOrSecrets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChangeAction('modify', 'Project/Routes/Web.php',
            new ContributionOwner('application', 'Project'),
            'SQUEHUB_PLANTED_SECRET', hash('sha256', 'safe'), 'review');
    }

    public function testPartialFailureCarriesObservedActionsWithoutLeakingCause(): void
    {
        $owner = new ContributionOwner('application', 'Project');
        $action = new ChangeAction('create', 'Project/Controllers/Example.php', $owner,
            null, hash('sha256', 'source'), 'low');
        $plan = new ChangePlan('make:controller', 'Example', $owner, [$action]);
        $result = new ChangeResult($plan, [], $action, [], false,
            'Project/Controllers/Example.php');
        $failure = new ChangeApplyException($result,
            new \RuntimeException('SQUEHUB_PLANTED_SECRET'));

        self::assertSame($result, $failure->result);
        self::assertFalse($result->complete());
        self::assertStringNotContainsString('SQUEHUB_PLANTED_SECRET', $failure->getMessage());
        self::assertSame('Project/Controllers/Example.php', $result->recoveryPath);
    }
}
