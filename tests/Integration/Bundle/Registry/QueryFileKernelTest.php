<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Support\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

/** `source: { query_file: ... }` in the bundle's YAML starts at kernel.project_dir. */
final class QueryFileKernelTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testTheFileIsReadRelativeToTheProjectDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/fz_qf_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $kernel = new RegistryCacheKernel($dir, false, false, ['indexes' => ['catalog' => [
            'source' => ['query_file' => 'sql/catalog.sql'],
            'fields' => ['name' => 'A'],
            'sync' => 'manual',
        ]]]);
        $kernel->boot();
        $registry = $kernel->getContainer()->get('test.registry');
        self::assertInstanceOf(IndexRegistry::class, $registry);
        self::assertSame('SELECT id, name FROM product', $registry->get('catalog')->source->query);
        $kernel->shutdown();
    }
}
