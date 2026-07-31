<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use DateTimeImmutable;
use Liminal\Http\Exception\HttpException;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\RouteMatch;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Security\Authentication\AuthenticationMiddleware;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Authentication\PreAuthentication;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Csrf\CsrfMiddleware;
use Liminal\Lib\Security\Csrf\CsrfTokenManager;
use Liminal\Lib\Security\Scope\CompanySwitchMiddleware;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Registry\Route;
use Liminal\Tests\Unit\Security\Double\RecordingUserProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The three seams a validated bearer credential opens, and the invariants
 * they must NOT open: the identity travels in the request attribute, the
 * session is neither consulted nor written, and without the attribute every
 * middleware behaves byte-identically to before.
 */
#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(CsrfMiddleware::class)]
#[CoversClass(CompanySwitchMiddleware::class)]
final class PreAuthenticationSeamTest extends TestCase
{
    public function testAuthenticationAdoptsThePreAuthenticatedIdentityAndLeavesTheSessionAlone(): void
    {
        // The session claims user 99; the provider only knows user 7. Without
        // the seam this request would log 99 out and 401. With it, the bearer
        // identity wins and the session is not even read.
        $session = Session::fresh('id', new DateTimeImmutable());
        $session->setUserId(99);

        $currentUser = new CurrentUser();
        $middleware = new AuthenticationMiddleware(new RecordingUserProvider($this->user(), 'hash'), $currentUser);

        $middleware->process($this->preAuthenticated($session), $this->passThrough());

        self::assertSame(7, $currentUser->get()?->id());
        self::assertSame(99, $session->userId());
    }

    public function testWithoutTheAttributeAProtectedRouteStill401s(): void
    {
        $middleware = new AuthenticationMiddleware(new RecordingUserProvider($this->user(), 'hash'), new CurrentUser());

        $this->expectException(HttpException::class);

        $middleware->process($this->request(Session::fresh('id', new DateTimeImmutable())), $this->passThrough());
    }

    public function testCsrfExemptsThePreAuthenticatedRequestAndOnlyThat(): void
    {
        $middleware = new CsrfMiddleware(new CsrfTokenManager());
        $session = Session::fresh('id', new DateTimeImmutable());

        // Bearer branch: an unsafe method passes with no token at all.
        $response = $middleware->process(
            $this->preAuthenticated($session)->withMethod('POST'),
            $this->passThrough(),
        );
        self::assertSame(200, $response->getStatusCode());

        // Cookie branch, unchanged: the same POST without the attribute
        // refuses with the CSRF 403.
        try {
            $middleware->process($this->request($session)->withMethod('POST'), $this->passThrough());
            self::fail('A cookie POST without a token must refuse.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
        }
    }

    public function testTheStatelessScopeFollowsTheHeaderWithinTheAccessibleSet(): void
    {
        $context = new CompanyContext(1, 1);
        $session = Session::fresh('id', new DateTimeImmutable());

        $middleware = $this->companySwitch($context);

        // No header: the first accessible company, the HTML default — and the
        // session stays clean: no preference write, no INSERT-per-call.
        $middleware->process($this->preAuthenticated($session), $this->passThrough());
        self::assertSame(3, $context->currentId());
        self::assertFalse($session->isDirty());

        // An accessible company is honoured.
        $middleware->process(
            $this->preAuthenticated($session)->withHeader(CompanySwitchMiddleware::COMPANY_HEADER, '5'),
            $this->passThrough(),
        );
        self::assertSame(5, $context->currentId());
    }

    public function testAForeignCompanyHeaderIsRefusedNeverReplaced(): void
    {
        $middleware = $this->companySwitch(new CompanyContext(1, 1));
        $session = Session::fresh('id', new DateTimeImmutable());

        try {
            $middleware->process(
                $this->preAuthenticated($session)->withHeader(CompanySwitchMiddleware::COMPANY_HEADER, '8'),
                $this->passThrough(),
            );
            self::fail('A company outside the accessible set must refuse.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
        }

        // Garbage is a refusal too, not a silent fallback.
        $this->expectException(HttpException::class);
        $middleware->process(
            $this->preAuthenticated($session)->withHeader(CompanySwitchMiddleware::COMPANY_HEADER, 'MAIN'),
            $this->passThrough(),
        );
    }

    private function companySwitch(CompanyContext $context): CompanySwitchMiddleware
    {
        $currentUser = new CurrentUser();
        $currentUser->set($this->user());

        return new CompanySwitchMiddleware($currentUser, $context, 1);
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
                return 'Ada';
            }

            public function accessibleCompanyIds(): array
            {
                return [3, 5];
            }
        };
    }

    private function request(Session $session): ServerRequestInterface
    {
        $route = new Route('GET', '/things', 'handler.id', 'thing.list');

        return new Psr17Factory()->createServerRequest('GET', '/things')
            ->withAttribute(SessionMiddleware::ATTRIBUTE, $session)
            ->withAttribute(RouterMiddleware::ATTRIBUTE, RouteMatch::found($route, []));
    }

    private function preAuthenticated(Session $session): ServerRequestInterface
    {
        return $this->request($session)
            ->withAttribute(PreAuthentication::ATTRIBUTE, new PreAuthentication($this->user()));
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
