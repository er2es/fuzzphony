<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use PHPUnit\Framework\TestCase;

final class QueryFileTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/fz_qf_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/project/sql', recursive: true);
        file_put_contents($this->base . '/project/sql/catalog.sql', "SELECT id, name FROM product;\n");
        file_put_contents($this->base . '/project/sql/empty.sql', " \n");
        file_put_contents($this->base . '/outside.sql', 'SELECT 1 AS id');
    }

    protected function tearDown(): void
    {
        foreach (['/project/sql/catalog.sql', '/project/sql/empty.sql', '/outside.sql'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/project/sql');
        @rmdir($this->base . '/project');
        @rmdir($this->base);
    }

    private function build(string $path): IndexDefinition
    {
        return IndexDefinition::builder('catalog')->fromQueryFile($path, $this->base . '/project')->watch('product')->field('name', 'A')->build();
    }

    public function testARelativePathIsReadFromTheBaseDirectory(): void
    {
        self::assertSame('SELECT id, name FROM product', $this->build('sql/catalog.sql')->source->query);
        self::assertSame('SELECT id, name FROM product', $this->build('sql/../sql/catalog.sql')->source->query, 'a ".." that stays inside is fine');
    }

    public function testAnAbsolutePathInsideTheBaseDirectoryIsFine(): void
    {
        self::assertSame('SELECT id, name FROM product', $this->build($this->base . '/project/sql/catalog.sql')->source->query);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'escape' => ['../outside.sql'];
        yield 'deep escape' => ['sql/../../outside.sql'];
        yield 'absolute outside' => ['@OUTSIDE@'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testAPathOutsideTheBaseDirectoryIsRefused(string $path): void
    {
        $path = str_replace('@OUTSIDE@', $this->base . '/outside.sql', $path);
        try {
            $this->build($path);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('outside the project directory', $e->getMessage());
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testAMissingFileIsNamed(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Query file "sql/nope.sql" does not exist or cannot be read');
        $this->build('sql/nope.sql');
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Query file "sql/empty.sql" is empty');
        $this->build('sql/empty.sql');
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->build(' ');
    }

    public function testTheFileContentIsPartOfTheFingerprint(): void
    {
        $before = Fingerprint::definition($this->build('sql/catalog.sql'));
        self::assertSame($before, Fingerprint::definition($this->build('sql/catalog.sql')));
        file_put_contents($this->base . '/project/sql/catalog.sql', 'SELECT id, name, 1 AS x FROM product');
        self::assertNotSame($before, Fingerprint::definition($this->build('sql/catalog.sql')));
    }

    public function testTheYamlSourceReadsTheFile(): void
    {
        $loader = new ArrayDefinitionLoader($this->base . '/project');
        $definition = $loader->load('catalog', ['source' => ['query_file' => 'sql/catalog.sql', 'id' => 'pid'], 'fields' => ['name' => 'A'], 'watch' => ['product' => 'SELECT :id'], 'sync' => 'manual']);
        self::assertSame('SELECT id, name FROM product', $definition->source->query);
        self::assertSame('pid', $definition->source->idColumn);
    }

    public function testQueryAndQueryFileTogetherAreAProblem(): void
    {
        try {
            (new ArrayDefinitionLoader($this->base . '/project'))->load('catalog', ['source' => ['query' => 'SELECT 1', 'query_file' => 'sql/catalog.sql'], 'fields' => ['name' => 'A']]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('"query_file" or "query", not both', $e->getMessage());
        }
        $this->expectException(InvalidDefinition::class);
        (new ArrayDefinitionLoader($this->base . '/project'))->load('catalog', ['source' => ['table' => 'product', 'query_file' => 'sql/catalog.sql']]);
    }

    public function testAMistypedKeyGetsADidYouMean(): void
    {
        try {
            (new ArrayDefinitionLoader($this->base . '/project'))->load('catalog', ['source' => ['query_fil' => 'sql/catalog.sql']]);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('query_file', $e->getMessage());
            self::assertStringContainsString('did you mean', strtolower($e->getMessage()));
        }
    }

    public function testANonStringPathIsAProblem(): void
    {
        $this->expectException(InvalidDefinition::class);
        (new ArrayDefinitionLoader($this->base . '/project'))->load('catalog', ['source' => ['query_file' => ['a']]]);
    }
}
