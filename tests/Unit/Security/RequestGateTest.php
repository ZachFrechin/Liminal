<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authorization\DenyAllResolver;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Exception\UndeclaredPermissionException;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestGate::class)]
final class RequestGateTest extends TestCase
{
    public function testAnAllowedPermissionPasses(): void
    {
        // authorize() is void: returning at all IS the passing behaviour.
        $this->expectNotToPerformAssertions();

        $this->gate($this->allowAll(), authenticated: true)->authorize('thing.read');
    }

    /**
     * Naming the refused code would map the authorization model for whoever
     * is probing.
     */
    public function testADeniedPermissionIsA403ThatDoesNotNameTheCode(): void
    {
        try {
            $this->gate(new DenyAllResolver(), authenticated: true)->authorize('thing.read');
            self::fail('A denied permission must raise an HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode());
            self::assertStringNotContainsString('thing.read', $exception->getMessage());
        }
    }

    /**
     * The Gate's fail-loud behaviour must survive the throwing wrapper: a typo
     * is wiring, not a denial.
     */
    public function testAnUndeclaredPermissionStillFailsLoudInsteadOfReadingAsDenied(): void
    {
        $this->expectException(UndeclaredPermissionException::class);

        $this->gate($this->allowAll(), authenticated: true)->authorize('never.declared');
    }

    private function gate(PermissionResolver $resolver, bool $authenticated): RequestGate
    {
        $currentUser = new CurrentUser();

        if ($authenticated) {
            $currentUser->set($this->user());
        }

        $permissions = new PermissionRegistry();
        $permissions->add(new Permission('thing.read', 'Read things'));

        return new RequestGate(new Gate($currentUser, new CompanyContext(1), $resolver, $permissions));
    }

    private function allowAll(): PermissionResolver
    {
        return new class implements PermissionResolver {
            public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool
            {
                return true;
            }
        };
    }

    private function user(): AuthenticatedUser
    {
        return new class implements AuthenticatedUser {
            public function id(): int
            {
                return 7;
            }

            public function displayName(): string
            {
                return 'Fixture user';
            }

            public function accessibleCompanyIds(): array
            {
                return [1];
            }
        };
    }
}
