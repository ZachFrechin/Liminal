<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authorization\DenyAllResolver;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\PermissionResolver;
use Liminal\Lib\Security\Exception\UndeclaredPermissionException;
use Liminal\Registry\Permission;
use Liminal\Registry\PermissionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gate::class)]
#[CoversClass(DenyAllResolver::class)]
final class GateTest extends TestCase
{
    public function testAnUndeclaredPermissionCodeIsRefusedLoudly(): void
    {
        $this->expectException(UndeclaredPermissionException::class);

        $this->gate(new DenyAllResolver())->allows('never.declared');
    }

    public function testWithoutAUserEveryDeclaredPermissionIsDenied(): void
    {
        // Even an allow-everything resolver cannot grant to nobody.
        self::assertFalse($this->gate($this->allowAll())->allows('thing.read'));
    }

    public function testTheResolverDecidesForAnAuthenticatedUser(): void
    {
        $currentUser = new CurrentUser();
        $currentUser->set($this->user());

        self::assertTrue($this->gate($this->allowAll(), $currentUser)->allows('thing.read'));
        self::assertFalse($this->gate(new DenyAllResolver(), $currentUser)->allows('thing.read'));
    }

    public function testTheResolverIsAskedAboutTheCurrentCompany(): void
    {
        $currentUser = new CurrentUser();
        $currentUser->set($this->user());

        $resolver = new class implements PermissionResolver {
            public int $seenCompany = 0;

            public function allows(AuthenticatedUser $user, string $permission, int $companyId): bool
            {
                $this->seenCompany = $companyId;

                return true;
            }
        };

        $this->gate($resolver, $currentUser, new CompanyContext(4, 4))->allows('thing.read');

        self::assertSame(4, $resolver->seenCompany);
    }

    private function gate(
        PermissionResolver $resolver,
        ?CurrentUser $currentUser = null,
        ?CompanyContext $context = null,
    ): Gate {
        $permissions = new PermissionRegistry();
        $permissions->add(new Permission('thing.read', 'Read things'));

        return new Gate(
            $currentUser ?? new CurrentUser(),
            $context ?? new CompanyContext(1),
            $resolver,
            $permissions,
        );
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

            public function accessibleCompanyIds(): array
            {
                return [1];
            }
        };
    }
}
