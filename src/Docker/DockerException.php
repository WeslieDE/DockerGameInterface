<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Docker;

use RuntimeException;

/** Raised when the Docker Engine API returns an error or is unreachable. */
final class DockerException extends RuntimeException
{
}
