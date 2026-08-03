<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Liminal\Lib\Database\Query\ListRequest;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The thirdparties of the working company, searchable, one page at a time.
 *
 * The section's two permissions anchor here. The first read/write split in
 * the tree: `read` opens the pages, `manage` opens the writes — and manage
 * PRESUMES read (every handler authorizes read first), because the role
 * editor lets an administrator check one box without the other, and a
 * manage-only actor would otherwise create records on pages they may not
 * see again.
 */
final readonly class ThirdpartyListHandler implements RequestHandlerInterface
{
    public const string READ = 'thirdparty.read';

    public const string MANAGE = 'thirdparty.manage';

    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private ThirdpartyRepository $thirdparties,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(self::READ);

        // Everything the URL is allowed to ask for is checked against the
        // schema here; the template renders the request back rather than
        // re-reading the query string, so links and state cannot disagree.
        $schema = ThirdpartyRepository::schema();
        $list = ListRequest::fromQueryParams($request->getQueryParams(), $schema);

        return $this->html->respond(
            '@thirdparty/thirdparties.html.twig',
            [
                'page' => $this->thirdparties->pageOf($list),
                'list' => $list,
                'schema' => $schema,
                // The template shows the write affordances only to those the
                // POST handlers would let through anyway.
                'canManage' => $this->allows->allows(self::MANAGE),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
