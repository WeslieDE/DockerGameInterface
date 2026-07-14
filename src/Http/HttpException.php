<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Http;

use RuntimeException;

/** Carries an HTTP status code so the router can turn it into a JSON error. */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
