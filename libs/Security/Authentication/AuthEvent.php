<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

/**
 * What happened on the authentication path, for the audit trail. Every login
 * attempt produces exactly one of these, whatever its outcome.
 */
enum AuthEvent: string
{
    case LoginGranted = 'login.granted';
    case LoginRefused = 'login.refused';
    case LoginThrottled = 'login.throttled';
    case LoggedOut = 'logout';
}
