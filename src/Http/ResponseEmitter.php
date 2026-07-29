<?php

declare(strict_types=1);

namespace Liminal\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Streams a PSR-7 response to the SAPI: status line, headers — preserving
 * multiple values per name — then the body in fixed-size chunks so large
 * payloads never materialise in memory.
 */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            ), true, $response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $index => $value) {
                    header(sprintf('%s: %s', $name, $value), $index === 0);
                }
            }
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }
}
