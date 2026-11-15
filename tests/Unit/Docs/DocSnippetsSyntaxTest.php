<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Docs;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\DefinitionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every ```php block of the README and the documentation parses (a block without an opening tag is read as the body of a
 * script; one that is a piece on purpose, an attribute or a method chain, has `<!-- fragment -->` on the line before it), so a snippet cannot rot into a syntax error. That a snippet also RUNS is checked where the guides and recipes mark
 * it with `<!-- tested: ... -->` (LikeGuideTest, RecipeDocsTest). Also: every `use Fuzzphony\...;` of a snippet names a real type (a renamed or
 * removed class is a documentation bug), and every ```yaml block parses; a complete index definition under `fuzzphony: indexes:` (with `source`
 * and `fields`) also loads and validates like the bundle does. `<!-- fragment -->` before a block skips it in all three checks.
 */
final class DocSnippetsSyntaxTest extends TestCase
{
    /** @return list<string> */
    private static function files(string $pattern): array
    {
        $found = glob($pattern);

        return $found === false ? [] : $found;
    }

    /** @return iterable<string, array{string}> */
    public static function snippets(): iterable
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge([$root . '/README.md', $root . '/UPGRADE.md', $root . '/SECURITY.md'], self::files($root . '/docs/*.md'), self::files($root . '/docs/recipes/*.md'));
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match_all('/^(?:<!-- fragment -->\R)?```php\R(.*?)^```/ms', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[1] as $n => [$code, $offset]) {
                if (str_starts_with($matches[0][$n][0], '<!-- fragment -->')) {
                    continue; // a piece of code on purpose (an attribute, a method chain): not a script
                }
                $line = substr_count(substr($text, 0, (int) $offset), "\n") + 1;
                yield substr($file, strlen($root) + 1) . ':' . $line => [$code];
            }
        }
    }

    #[DataProvider('snippets')]
    public function testTheSnippetParses(string $code): void
    {
        $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code;
        try {
            self::assertNotSame([], token_get_all($source, TOKEN_PARSE));
        } catch (\ParseError $e) {
            self::fail('Syntax error in a documentation snippet: ' . $e->getMessage() . ' (line ' . $e->getLine() . ' of the snippet)');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function yamlBlocks(): iterable
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge([$root . '/README.md', $root . '/UPGRADE.md'], self::files($root . '/docs/*.md'), self::files($root . '/docs/recipes/*.md'));
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match_all('/^(?:<!-- fragment -->\R)?```ya?ml\R(.*?)^```/ms', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[1] as $n => [$code, $offset]) {
                if (str_starts_with($matches[0][$n][0], '<!-- fragment -->')) {
                    continue;
                }
                yield substr($file, strlen($root) + 1) . ':' . (substr_count(substr($text, 0, (int) $offset), "
") + 1) => [$code];
            }
        }
    }

    #[DataProvider('snippets')]
    public function testTheUsedFuzzphonyTypesExist(string $code): void
    {
        $this->addToAssertionCount(1);
        preg_match_all('/^use (Fuzzphony\[A-Za-z0-9_\]+?)(?: as \w+)?;/m', $code, $uses);
        foreach ($uses[1] as $type) {
            self::assertTrue(class_exists($type) || interface_exists($type) || enum_exists($type), 'A documentation snippet uses ' . $type . ', which does not exist.');
        }
    }

    #[DataProvider('yamlBlocks')]
    public function testTheYamlBlockParsesAndACompleteIndexValidates(string $code): void
    {
        $this->addToAssertionCount(1);
        $data = Yaml::parse($code);
        $indexes = is_array($data) && is_array($data['fuzzphony'] ?? null) ? ($data['fuzzphony']['indexes'] ?? null) : null;
        foreach (is_array($indexes) ? $indexes : [] as $name => $config) {
            if (!is_array($config) || !isset($config['fields'], $config['source'])) {
                continue; // an override or a fragment: parsing was the check
            }
            $typed = [];
            foreach ($config as $key => $value) {
                $typed[(string) $key] = $value;
            }
            DefinitionValidator::assertValid((new ArrayDefinitionLoader(null))->load((string) $name, $typed));
        }
    }
}
