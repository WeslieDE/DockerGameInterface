<?php
declare(strict_types=1);

/**
 * SGI API front-controller.
 *
 * Wires the (dependency-free) object graph and hands off to the Router.
 * A plain PSR-4 autoloader is registered inline so the app runs without a
 * `composer install` step — there are no external dependencies to fetch.
 */

use tk\weslie\SGI\Auth\TokenAuth;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Docker\SocketStream;
use tk\weslie\SGI\Http\Router;
use tk\weslie\SGI\Service\BackupService;
use tk\weslie\SGI\Service\ConsoleService;
use tk\weslie\SGI\Service\PowerService;
use tk\weslie\SGI\Service\StatusService;

$srcDir = dirname(__DIR__, 2) . '/src';

// Prefer Composer's autoloader if present, else register a minimal PSR-4 one.
$composer = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class) use ($srcDir): void {
        $prefix = 'tk\\weslie\\SGI\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $srcDir . '/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

$socket = getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock';

$docker  = new DockerClient($socket);
$stream  = new SocketStream($socket);
$auth    = new TokenAuth($docker);

$router = new Router(
    $auth,
    new StatusService($docker),
    new PowerService($docker),
    new ConsoleService($docker, $stream),
    new BackupService($docker),
);

$router->dispatch();
