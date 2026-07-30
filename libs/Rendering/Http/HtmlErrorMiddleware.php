<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Registry\RouteRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders refusals as HTML for browsers — at −950, immediately inside the
 * error handler, so it sees an HttpException FIRST on the unwind path while
 * the outer JSON handler remains the safety net.
 *
 * What it deliberately does NOT do:
 *
 * - It catches HttpException only. A raw Throwable belongs to the outer
 *   handler, which owns logging; a browser then receives JSON on a real
 *   crash, an honest wart. If Twig itself explodes rendering this page, that
 *   exception propagates too: the outer handler logs it and answers 500, and
 *   losing the original status is the correct signal that templates are broken.
 * - It never mints state. The session middleware sits INSIDE this frame, so
 *   its persist was already skipped when we get here: a CSRF token generated
 *   now would reach the HTML but never the store, and the next POST would
 *   fail. Error templates therefore carry no forms — keep it that way.
 *
 * The intended URL travels as a query parameter rather than in the session for
 * the same reason: a 401 spray with a browser Accept header must not mint one
 * row. Generation is safe by construction (a relative path from our own
 * request); VALIDATION belongs to whoever consumes it — phase 5's login
 * handler must require a leading "/" and refuse "//" and "\".
 */
final readonly class HtmlErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RouteRegistry $routes,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private string $loginRoute,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $exception) {
            if (!str_contains($request->getHeaderLine('Accept'), 'text/html')) {
                // JSON clients keep the JSON contract: rethrow to the handler.
                throw $exception;
            }

            if ($exception->statusCode() === 401 && $this->routes->named($this->loginRoute) !== null) {
                return $this->redirectToLogin($request);
            }

            return $this->html->respond(
                '@liminal/error.html.twig',
                ['status' => $exception->statusCode(), 'message' => $exception->getMessage()],
                $exception->statusCode(),
                $exception->headers(),
            );
        }
    }

    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $location = $this->urls->generate($this->loginRoute);

        // Only a GET is resumable; a POST cannot be replayed by a redirect.
        if ($request->getMethod() === 'GET') {
            $target = $request->getUri()->getPath();
            $query = $request->getUri()->getQuery();

            $location .= '?redirect=' . rawurlencode($query === '' ? $target : $target . '?' . $query);
        }

        return $this->responses->createResponse(302)->withHeader('Location', $location);
    }
}
