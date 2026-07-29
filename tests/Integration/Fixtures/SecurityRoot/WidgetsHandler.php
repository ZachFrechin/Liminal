<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Doctrine\ORM\EntityManagerInterface;
use Liminal\Http\JsonResponseFactory;
use Liminal\Tests\Integration\Fixtures\Entity\Widget;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lists the scoped widgets visible under the CURRENT company scope — the
 * endpoint the company-scope HTTP proof reads through two different users.
 */
final readonly class WidgetsHandler implements RequestHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private JsonResponseFactory $json,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $widgets = $this->entityManager
            ->createQuery('SELECT w FROM ' . Widget::class . ' w ORDER BY w.label')
            ->getResult();

        $labels = [];

        if (is_array($widgets)) {
            foreach ($widgets as $widget) {
                if ($widget instanceof Widget) {
                    $labels[] = $widget->getLabel();
                }
            }
        }

        return $this->json->response(200, ['widgets' => $labels]);
    }
}
