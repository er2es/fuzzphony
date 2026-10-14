<?php

declare(strict_types=1);

/**
 * Builds the Fuzzphony container in a process where the classes named in argv[2] (comma separated) cannot be
 * autoloaded, as if their package were not installed, and prints "<exception class>|<message>" ("none" when
 * the build succeeded). argv[1] picks the configuration: actor, authorization or rate_limit.
 *
 * Used by FuzzphonyBundleMutationsTest: the dev environment has every package, so the "package missing" branches
 * of the bundle can only be reached in a child process.
 */

use Composer\Autoload\ClassLoader;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$hidden = array_flip(explode(',', $argv[2] ?? ''));
$loaders = ClassLoader::getRegisteredLoaders();
$composer = reset($loaders);
if ($composer === false) {
    fwrite(STDERR, "no composer loader\n");
    exit(2);
}
$composer->unregister();
spl_autoload_register(static function (string $class) use ($composer, $hidden): void {
    if (!isset($hidden[$class])) {
        $composer->loadClass($class);
    }
}, true, true);

$config = match ($argv[1] ?? '') {
    'actor' => ['actor' => ['tenant' => 'account.id']],
    'authorization' => ['authorization' => ['enabled' => true]],
    'rate_limit' => ['rate_limit' => ['limiter' => 'search']],
    default => [],
};

$container = new ContainerBuilder();
$container->setParameter('kernel.environment', 'test');
$container->setParameter('kernel.debug', false);
$container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
$container->setParameter('kernel.cache_dir', sys_get_temp_dir());
$container->setParameter('kernel.build_dir', sys_get_temp_dir());

try {
    $extension = (new FuzzphonyBundle())->getContainerExtension();
    if ($extension === null) {
        throw new LogicException('no extension');
    }
    $container->registerExtension($extension);
    $extension->load([$config], $container);
    echo 'none|';
} catch (Throwable $e) {
    echo $e::class . '|' . $e->getMessage();
}
