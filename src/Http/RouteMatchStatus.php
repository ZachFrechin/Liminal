<?php

declare(strict_types=1);

namespace Liminal\Http;

enum RouteMatchStatus
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
