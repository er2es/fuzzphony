<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use PHPUnit\Framework\TestCase;

final class CodeFieldFlagTest extends TestCase
{
    private function index(): IndexDefinition
    {
        return IndexDefinition::builder('orders')->fromTable('orders')->field('number', 'A', code: true)->field('title', 'B')->build();
    }

    public function testBuilderYamlAndAttributeAgree(): void
    {
        self::assertTrue($this->index()->field('number')?->code);
        self::assertFalse($this->index()->field('title')?->code);
        $yaml = (new ArrayDefinitionLoader())->load('orders', ['fields' => ['number' => ['weight' => 'A', 'code' => true], 'title' => 'B']]);
        self::assertTrue($yaml->field('number')?->code);
        $entity = new #[Searchable(name: 'orders', table: 'orders')] class {
            #[SearchField('A', column: 'number', code: true)]
            public string $number = '';
        };
        self::assertTrue((new AttributeDefinitionLoader())->load($entity::class)->field('number')?->code);
    }

    public function testTheYamlFlagMustBeABoolean(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('"code" must be true or false');
        (new ArrayDefinitionLoader())->load('orders', ['fields' => ['number' => ['weight' => 'A', 'code' => 'yes']]]);
    }

    public function testTheExportersWriteTheFlagAndOmitItWhenOff(): void
    {
        $index = $this->index();
        /** @var array{fields: array<string, mixed>} $array */
        $array = (new ArrayExporter())->export($index);
        self::assertSame(['weight' => 'A', 'code' => true], $array['fields']['number']);
        self::assertSame('B', $array['fields']['title']);
        self::assertStringContainsString("->field('number', 'A', code: true)", (new BuilderExporter())->export($index));
        self::assertStringContainsString("#[SearchField('A', column: 'number', code: true", (new AttributeExporter())->export($index));
        self::assertStringNotContainsString('code: true', (new BuilderExporter())->export(IndexDefinition::builder('o')->fromTable('o')->field('t', 'B')->build()));
    }
}
