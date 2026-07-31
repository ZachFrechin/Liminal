<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Security\Authentication\BearerTokenMiddleware;
use Liminal\Lib\Security\Authentication\NullTokenProvider;
use Liminal\Lib\Security\Authentication\PreAuthentication;
use Liminal\Lib\Security\Contract\AuthenticatedUser;
use Liminal\Lib\Security\Contract\TokenProvider;
use Liminal\Lib\Security\Contract\UserProvider;
use Liminal\Tests\Unit\Security\Double\RecordingTokenProvider;
use Liminal\Tests\Unit\Security\Double\RecordingUserProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(BearerTokenMiddleware::class)]
final class BearerTokenMiddlewareTest extends TestCase
{
    public function testARequestWithoutTheHeaderPassesUntouchedAndCostsNoLookup(): void
    {
        $tokens = new RecordingTokenProvider('secret', 7);
        $captured = $this->process($this->middleware($tokens), $this->request());

        self::assertNull($captured->getAttribute(PreAuthentication::ATTRIBUTE));
        self::assertFalse($tokens->consulted);
    }

    public function testAnotherAuthorizationSchemePassesUntouched(): void
    {
        $tokens = new RecordingTokenProvider('secret', 7);
        $captured = $this->process(
            $this->middleware($tokens),
            $this->request()->withHeader('Authorization', 'Basic dXNlcjpwYXNz'),
        );

        self::assertNull($captured->getAttribute(PreAuthentication::ATTRIBUTE));
        self::assertFalse($tokens->consulted);
    }

    public function testAPresentedTokenThatDoesNotValidateIs401WithTheBearerHeader(): void
    {
        try {
            $this->process(
                $this->middleware(new NullTokenProvider()),
                $this->request()->withHeader('Authorization', 'Bearer nope'),
            );
            self::fail('An invalid bearer token must refuse.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->statusCode());
            self::assertSame('Bearer error="invalid_token"', $e->headers()['WWW-Authenticate'] ?? null);
        }
    }

    public function testAnEmptyBearerCredentialRefuses(): void
    {
        // PSR-7 trims trailing whitespace: "Bearer " arrives as a bare
        // "Bearer" — presented and empty, never someone else's scheme.
        $this->expectException(HttpException::class);

        $this->process(
            $this->middleware(new RecordingTokenProvider('secret', 7)),
            $this->request()->withHeader('Authorization', 'Bearer'),
        );
    }

    /** Deletion and offboarding revoke API access the instant they land. */
    public function testATokenWhoseUserVanishedOrLostEveryCompanyRefuses(): void
    {
        // The token is valid, the user lookup finds nobody: the provider
        // double only answers for id 7 and this middleware asks for 8.
        try {
            $this->process(
                $this->middleware(new RecordingTokenProvider('secret', 8), $this->users()),
                $this->request()->withHeader('Authorization', 'Bearer secret'),
            );
            self::fail('A token for a vanished user must refuse.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->statusCode());
        }

        try {
            $this->process(
                $this->middleware(new RecordingTokenProvider('secret', 7), $this->users(companies: [])),
                $this->request()->withHeader('Authorization', 'Bearer secret'),
            );
            self::fail('A token for an offboarded user must refuse.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->statusCode());
        }
    }

    public function testAValidTokenTravelsAsAPreAuthenticationAttribute(): void
    {
        $captured = $this->process(
            $this->middleware(new RecordingTokenProvider('secret', 7), $this->users()),
            $this->request()->withHeader('Authorization', 'Bearer secret'),
        );

        $pre = $captured->getAttribute(PreAuthentication::ATTRIBUTE);
        self::assertInstanceOf(PreAuthentication::class, $pre);
        self::assertSame(7, $pre->user->id());
    }

    private function middleware(TokenProvider $tokens, ?UserProvider $users = null): BearerTokenMiddleware
    {
        return new BearerTokenMiddleware($tokens, $users ?? $this->users());
    }

    /**
     * @param list<int> $companies
     */
    private function users(array $companies = [1]): RecordingUserProvider
    {
        $user = new class ($companies) implements AuthenticatedUser {
            /** @param list<int> $companies */
            public function __construct(private readonly array $companies) {}

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
                return $this->companies;
            }
        };

        return new RecordingUserProvider($user, 'irrelevant-hash');
    }

    private function request(): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('GET', '/api/v1/things');
    }

    /** Runs the middleware and returns the request the inner handler saw. */
    private function process(BearerTokenMiddleware $middleware, ServerRequestInterface $request): ServerRequestInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };

        $middleware->process($request, $handler);
        self::assertInstanceOf(ServerRequestInterface::class, $handler->captured);

        return $handler->captured;
    }
}
