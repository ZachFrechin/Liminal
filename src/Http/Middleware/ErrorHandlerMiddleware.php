<?php

declare(strict_types=1);

namespace Liminal\Http\Middleware;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\JsonResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outermost middleware: turns any escaping throwable into a response.
 *
 * Unexpected exceptions are logged in full but never leak their message to the
 * client unless the app runs in debug mode.
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponseFactory $json,
        private LoggerInterface $logger,
        private bool $debug = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $exception) {
            return $this->render($exception->statusCode(), $exception->getMessage(), $exception->headers());
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled exception while processing request.', [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);

            return $this->render(500, $this->debug ? $exception->getMessage() : 'Internal Server Error');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function render(int $status, string $message, array $headers = []): ResponseInterface
    {
        return $this->json->response($status, ['error' => ['status' => $status, 'message' => $message]], $headers);
    }
}
