<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\IpLimit;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A real, compiled kernel (FrameworkBundle + FuzzphonyBundle) for `rate_limit: { per: ip }` with an anonymous public
 * search endpoint: no Security bundle, no user. $options: `trusted_proxies` (framework.trusted_proxies, a string),
 * `limiter` (an application limiter of 3 searches a minute, named in rate_limit.limiter; without it the bundle's default
 * entry is used), `log_ip` (search_log.ip; null leaves the search log off).
 *
 * @internal
 */
final class IpLimitKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array{trusted_proxies?: string, limiter?: bool, log_ip?: string|null} $options */
    public function __construct(private readonly string $scratch, private readonly array $options = [])
    {
        parent::__construct('prod', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new FuzzphonyBundle();
    }

    public function getProjectDir(): string
    {
        return $this->scratch;
    }

    public function getCacheDir(): string
    {
        return $this->scratch . '/cache';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->scratch . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        // the bundle's default limiter entry is private: the test reads the limit it was compiled with
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('limiter.fuzzphony_ip')) {
                    $container->getDefinition('limiter.fuzzphony_ip')->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'fuzzphony-ip-limit',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
        ];
        if (isset($this->options['trusted_proxies'])) {
            $framework['trusted_proxies'] = $this->options['trusted_proxies'];
            $framework['trusted_headers'] = ['x-forwarded-for'];
        }
        $rateLimit = ['per' => 'ip'];
        if ($this->options['limiter'] ?? false) {
            $framework['rate_limiter'] = ['public_search' => ['policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute']];
            $rateLimit['limiter'] = 'public_search';
        }
        $container->extension('framework', $framework);

        $logIp = $this->options['log_ip'] ?? null;
        $container->extension('fuzzphony', [
            'rate_limit' => $rateLimit,
            'search_log' => $logIp === null ? ['enabled' => false] : ['enabled' => true, 'ip' => $logIp],
            'indexes' => [
                'items' => ['source' => ['table' => 'iplimit_item'], 'language' => 'english', 'fields' => ['title' => 'A']],
            ],
        ]);

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)->factory([DoctrineTestCase::class, 'dbalConnection']);
        $services->set('logger', RecordingLogger::class)->public();
        $services->set(IpLimitController::class)->autowire()->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/IpLimitController.php', 'attribute');
    }
}
