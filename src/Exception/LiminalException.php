<?php

declare(strict_types=1);

namespace Liminal\Exception;

use Throwable;

/**
 * Marker implemented by every exception the core throws deliberately, so a
 * consumer can catch "any Liminal error" without enumerating classes.
 *
 * The SPL parent still carries the semantics: LogicException subclasses are
 * developer contract violations (frozen registry writes, duplicate
 * contributions, wiring mistakes), RuntimeException subclasses are
 * environment or data conditions (missing configuration, cross-company
 * access). Wrap-and-rethrow sites must pass the original as $previous.
 */
interface LiminalException extends Throwable {}
