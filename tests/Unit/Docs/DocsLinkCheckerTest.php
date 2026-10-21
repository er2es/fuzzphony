<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Docs;

use Fuzzphony\Tools\DocsLinkChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/DocsLinkChecker.php';

/**
 * The checker behind `php tools/check-docs-links.php` (the docs-links workflow): GitHub's heading slugs, which
 * destinations count as links, and what it reports for a small tree of files.
 */
final class DocsLinkCheckerTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->removeTree($this->root);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function slugs(): iterable
    {
        yield 'plain words' => ['Known limitations', 'known-limitations'];
        yield 'code keeps its underscore' => ['Ranking is approximate beyond `candidate_limit`', 'ranking-is-approximate-beyond-candidate_limit'];
        yield 'code with punctuation' => ["Why `LIKE '%x%'` falls over", 'why-like-x-falls-over'];
        yield 'an ampersand leaves two hyphens' => ['Search & filter', 'search--filter'];
        yield 'a colon' => ['Privacy: the log is personal data', 'privacy-the-log-is-personal-data'];
        yield 'dots' => ['Version 1.0.5 notes', 'version-105-notes'];
        yield 'an apostrophe' => ["Don't stop", 'dont-stop'];
        yield 'non-ASCII letters stay' => ['Árvíztűrő tükörfúrógép', 'árvíztűrő-tükörfúrógép'];
        yield 'a link shows its text, emphasis its words' => ['[Link](x.md) and *emph* and **bold**', 'link-and-emph-and-bold'];
        yield 'underscore emphasis goes, a word underscore stays' => ['_Emphasis_ in snake_case', 'emphasis-in-snake_case'];
        yield 'code and parentheses' => ['Config: `search_log.ip` (opt-in)', 'config-search_logip-opt-in'];
        yield 'HTML tags are dropped' => ['A <b>bold</b> idea', 'a-bold-idea'];
        yield 'an escaped character' => ['Price \\#1', 'price-1'];
        yield 'an existing hyphen stays' => ['Opt-in guards', 'opt-in-guards'];
    }

    #[DataProvider('slugs')]
    public function testSlugFollowsGitHub(string $heading, string $expected): void
    {
        self::assertSame($expected, DocsLinkChecker::slug($heading));
    }

    public function testRepeatedHeadingsGetNumberedAnchorsAsOnGitHub(): void
    {
        $anchors = DocsLinkChecker::anchors("# A\n\n## A\n\n## A\n\n## A-1\n");

        self::assertSame(['a', 'a-1', 'a-2', 'a-1-1'], array_keys($anchors));
    }

    public function testHeadingsInCodeFencesAndNonHeadingsAreNotAnchors(): void
    {
        $markdown = "# Real\n\n```bash\n# a comment, not a heading\n```\n\n#hashtag\n\n    text # not at the start\n\n~~~\n## nor this\n~~~\n\n### Closed ###\n";

        self::assertSame(['real', 'closed'], array_keys(DocsLinkChecker::anchors($markdown)));
    }

    public function testExplicitIdsAndNamesAreAnchors(): void
    {
        $markdown = "# Title\n\n<a id=\"custom\"></a>\n<a name='old-style'></a>\n<div id=\"box\">x</div>\n\n`<a id=\"in-code\">`\n";

        self::assertSame(['title', 'custom', 'old-style', 'box'], array_keys(DocsLinkChecker::anchors($markdown)));
    }

    public function testLinksAreFoundWithTheirLineNumbers(): void
    {
        $markdown = <<<'MD'
            [one](a.md) and ![img](i.png "a title") then [two](<b c.md>) and [three](d.md#frag)
            [badge](https://example.com/x_(y)) [![inner](pic.svg)](target.md)
            [ref]: refs.md "Title"
            [not a link](two words) [empty]()
            <a href="html.md">html</a> <img src='pic.png'>
            MD;

        self::assertSame([
            [1, 'a.md'], [1, 'i.png'], [1, 'b c.md'], [1, 'd.md#frag'],
            [2, 'https://example.com/x_(y)'], [2, 'pic.svg'], [2, 'target.md'],
            [3, 'refs.md'],
            [4, ''],
            [5, 'html.md'], [5, 'pic.png'],
        ], array_map(static fn(array $l): array => [$l['line'], $l['dest']], DocsLinkChecker::links($markdown)));
    }

    public function testNothingInCodeOrCommentsIsALink(): void
    {
        $markdown = "```md\n[a](fenced.md)\n```\n\nInline `[b](code.md)` and ``[c](`code`.md)`` and a span `over\ntwo lines [d](span.md)` here.\n\n<!-- [e](comment.md)\n[f](comment.md) -->\n\n[g](real.md)\n";

        self::assertSame([[11, 'real.md']], array_map(static fn(array $l): array => [$l['line'], $l['dest']], DocsLinkChecker::links($markdown)));
    }

    public function testAnUnclosedBacktickIsPlainText(): void
    {
        self::assertSame(['a.md'], array_column(DocsLinkChecker::links("a lone ` tick [x](a.md)\n"), 'dest'));
        self::assertSame(['a.md'], array_column(DocsLinkChecker::links("a lone `tick\n\nstill [x](a.md) `\n"), 'dest'));
    }

    public function testAbsoluteLinksToThisRepositoryAreCheckedAndOtherSitesAreNot(): void
    {
        $this->tree([
            'README.md' => <<<'MD'
                # Title

                ## Section

                [ok](https://github.com/er2es/fuzzphony/blob/main/docs/guide.md#details)
                [ok dir](https://github.com/er2es/fuzzphony/tree/main/docs/)
                [ok root](https://github.com/er2es/fuzzphony/tree/main)
                [gone](https://github.com/er2es/fuzzphony/blob/main/docs/missing.md)
                [bad anchor](https://github.com/er2es/fuzzphony/blob/main/docs/guide.md#nope)
                [raw](https://raw.githubusercontent.com/er2es/fuzzphony/main/docs/img/pic.png)
                [raw gone](https://raw.githubusercontent.com/er2es/fuzzphony/main/docs/img/none.png)
                [other repo](https://github.com/someone/else/blob/main/nothing.md)
                [compare](https://github.com/er2es/fuzzphony/compare/v1...v2)
                [site](https://example.com/page#anything) [mail](mailto:a@b.c) [bare](#) [top](#title)
                MD,
            'docs/guide.md' => "# Guide\n\n## Details\n",
            'docs/img/pic.png' => 'x',
        ]);

        self::assertSame([
            'README.md:8: https://github.com/er2es/fuzzphony/blob/main/docs/missing.md: no such file or directory',
            'README.md:9: https://github.com/er2es/fuzzphony/blob/main/docs/guide.md#nope: docs/guide.md has no heading or anchor "nope"',
            'README.md:11: https://raw.githubusercontent.com/er2es/fuzzphony/main/docs/img/none.png: no such file or directory',
        ], (new DocsLinkChecker($this->root))->check());
    }

    public function testRelativeLinksAndAnchorsAreResolvedFromTheFilesDirectory(): void
    {
        $this->tree([
            'README.md' => "# Project\n\n[docs](docs/guide.md) [dir](docs) [sub](docs/sub/) [root](/README.md#project)\n",
            'docs/guide.md' => <<<'MD'
                # Guide

                ## Search & filter

                ## Search & filter

                See [up](../README.md#project), [same](#search--filter), [second](#search--filter-1), [third](#search--filter-2),
                [sibling](other.md#other-heading), [space](my%20file.md), [line](../src/Code.php#L10), [lost](lost.md),
                [case](Other.md), [out](../../outside.md), [dir anchor](sub/#whatever), [image](img/missing.png),
                [mixed](#Search--Filter), [text fragment](other.md#:~:text=foo)

                [def]: other.md#nothing
                [fine]: ../README.md
                MD,
            'docs/other.md' => "# Other heading\n",
            'docs/my file.md' => "x\n",
            'docs/sub/README.md' => "# Sub\n",
            'src/Code.php' => "<?php\n",
        ]);

        self::assertSame([
            'docs/guide.md:7: #search--filter-2: docs/guide.md has no heading or anchor "search--filter-2"',
            'docs/guide.md:8: lost.md: no such file or directory',
            'docs/guide.md:9: Other.md: no such file or directory',
            'docs/guide.md:9: ../../outside.md points outside the repository',
            'docs/guide.md:9: img/missing.png: no such file or directory',
            'docs/guide.md:10: #Search--Filter: docs/guide.md has no heading or anchor "Search--Filter"',
            'docs/guide.md:12: other.md#nothing: docs/other.md has no heading or anchor "nothing"',
        ], (new DocsLinkChecker($this->root))->check());
    }

    public function testReferenceLinksNeedADefinition(): void
    {
        $this->tree([
            'README.md' => "# T\n\nSee [the guide][guide], [guide][], [lost][nowhere] and `[in][code]`, also array[0][1].\n\n[guide]: docs/g.md\n[Other]: docs/none.md\n",
            'docs/g.md' => "x\n",
        ]);

        self::assertSame([
            'README.md:3: the reference link [nowhere] has no definition',
            'README.md:6: docs/none.md: no such file or directory',
        ], (new DocsLinkChecker($this->root))->check());
    }

    public function testTheFilesCheckedSkipVendorBuildAndHistory(): void
    {
        $this->tree([
            'README.md' => "# T\n",
            'docs/a.md' => "# A\n",
            'docs/superpowers/plan.md' => "[x](missing.md)\n",
            'vendor/pkg/README.md' => "[x](missing.md)\n",
            'demo/vendor/pkg/README.md' => "[x](missing.md)\n",
            'build/out.md' => "[x](missing.md)\n",
            'node_modules/n/README.md' => "[x](missing.md)\n",
            'notes.txt' => "[x](missing.md)\n",
        ]);
        $checker = new DocsLinkChecker($this->root);

        self::assertSame(['README.md', 'docs/a.md'], $checker->markdownFiles());
        self::assertSame([], $checker->check());
    }

    public function testACheckedFileMayBeCheckedAgainstAnotherRepository(): void
    {
        $this->tree(['docs/a.md' => "# A\n"]);

        $checker = new DocsLinkChecker($this->root, 'acme/widgets', 'trunk');

        self::assertSame([], $checker->checkMarkdown('README.md', "[a](https://github.com/acme/widgets/blob/trunk/docs/a.md#a)\n"));
        self::assertSame(
            ['README.md:1: https://github.com/acme/widgets/blob/trunk/docs/b.md: no such file or directory'],
            $checker->checkMarkdown('README.md', "[a](https://github.com/acme/widgets/blob/trunk/docs/b.md)\n"),
        );
        self::assertSame([], $checker->checkMarkdown('README.md', "[a](https://github.com/er2es/fuzzphony/blob/main/docs/b.md)\n"));
    }

    /** @param array<string, string> $files */
    private function tree(array $files): void
    {
        $this->root = sys_get_temp_dir() . '/fuzzphony-docs-links-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            $file = $this->root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $content);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $name) {
                $this->removeTree($path . '/' . $name);
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
