<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every ```php block of the README and the documentation parses (a block without an opening tag is read as the body of a
 * script; one that is a piece on purpose, an attribute or a method chain, has `<!-- fragment -->` on the line before it), so a snippet cannot rot into a syntax error. That a snippet also RUNS is checked where the guides and recipes mark
 * it with `<!-- tested: ... -->` (LikeGuideTest, RecipeDocsTest).
 */
final class DocSnippetsSyntaxTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function snippets(): iterable
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge([$root . '/README.md', $root . '/UPGRADE.md', $root . '/SECURITY.md'], glob($root . '/docs/*.md') ?: [], glob($root . '/docs/recipes/*.md') ?: []);
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
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail('Syntax error in a documentation snippet: ' . $e->getMessage() . ' (line ' . $e->getLine() . ' of the snippet)');
        }
        $this->addToAssertionCount(1);
    }
}
