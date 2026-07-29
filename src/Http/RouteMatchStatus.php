<?php

declare(strict_types=1);

namespace Liminal\Http;

/**
 * The three ways routing a request can end.
 */
enum RouteMatchStatus
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
