<?php

declare(strict_types=1);

namespace Liminal\Lib\Api;

use Liminal\Http\JsonResponseFactory;
use Liminal\Lib\Database\Pagination\Page;
use Psr\Http\Message\ResponseInterface;

/**
 * The one shape of a successful API answer: {data} for an item, {data, meta}
 * for a collection — meta carrying exactly what Page already knows. Errors
 * never come through here: the kernel's ErrorHandlerMiddleware owns the
 * {error: {status, message}} envelope, and a second error shape would be a
 * second thing clients must parse.
 */
final readonly class ApiResponder
{
    public function __construct(private JsonResponseFactory $json) {}

    /**
     * @param array<string, mixed> $data
     */
    public function item(array $data): ResponseInterface
    {
        return $this->json->response(200, ['data' => $data]);
    }

    /**
     * @template T of object
     *
     * @param Page<T>                          $page
     * @param callable(T): array<string, mixed> $serialize
     */
    public function collection(Page $page, callable $serialize): ResponseInterface
    {
        return $this->json->response(200, [
            'data' => array_map($serialize(...), $page->items),
            'meta' => [
                'page' => $page->page,
                'pages' => $page->pages,
                'total' => $page->total,
                'perPage' => $page->perPage,
            ],
        ]);
    }
}
