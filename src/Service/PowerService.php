<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Auth\ServerContext;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Http\HttpException;

/** Power actions on the resolved container. */
final class PowerService
{
    private const ACTIONS = ['start', 'stop', 'restart', 'kill'];

    public function __construct(
        private readonly DockerClient $docker,
    ) {}

    public function run(ServerContext $ctx, string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new HttpException(404, 'unknown action');
        }
        $this->docker->{$action}($ctx->id);
    }
}
