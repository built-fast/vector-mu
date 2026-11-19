<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use RuntimeException;

/**
 * A gate check that failed. The message is a fixed reason for logs: it
 * never holds a token, cookie, secret, or anything else from the request.
 * Never show it to the requester.
 */
final class GateException extends RuntimeException {
}
