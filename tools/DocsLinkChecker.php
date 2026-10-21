<?php

declare(strict_types=1);

namespace Fuzzphony\Tools;

/**
 * Checks the links of the repository's Markdown files without any dependency or network access.
 *
 * What it checks: every relative link and image resolves to an existing file or directory (the case of every path
 * segment must match, as on GitHub), every `#anchor` matches a heading of the target file (GitHub's slug rules, with
 * `-1`, `-2` for duplicates) or an explicit `id` / `name` attribute, and absolute links to this repository
 * (`https://github.com/<repository>/blob|tree/<branch>/<path>`) resolve in the checkout like a relative link would.
 * Links to other sites, `mailto:` and a bare `#` are not checked. Links inside fenced code blocks, inline code and HTML
 * comments are ignored.
 *
 * Usage: `php tools/check-docs-links.php` (see CONTRIBUTING.md).
 *
 * @internal
 */
final class DocsLinkChecker
{
    /** Directories that are never searched for Markdown files (matched on the first path segment, or on any `vendor` / `node_modules` / `.git`). */
    private const array SKIPPED_ANYWHERE = ['vendor', 'node_modules', '.git'];

    /** Directories skipped at the repository root only: build output and the history of plans and specs. */
    private const array SKIPPED_PREFIXES = ['build', 'var', 'demo/var', 'docs/superpowers'];

    /** @var array<string, array<string, true>> anchors of a file, by its path relative to the root */
    private array $anchorCache = [];

    /** @var array<string, list<string>> directory listings, by the path relative to the root */
    private array $listingCache = [];

    private readonly string $root;

    /**
     * @param string $repository `owner/name` of this repository on GitHub, for its absolute links
     * @param string $branch     the branch those links point at
     */
    public function __construct(
        string $root,
        private readonly string $repository = 'er2es/fuzzphony',
        private readonly string $branch = 'main',
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Every problem of every Markdown file below the root, as `file:line: message`, sorted.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $problems = [];
        foreach ($this->markdownFiles() as $file) {
            $markdown = file_get_contents($this->root . '/' . $file);
            if ($markdown === false) {
                $problems[] = $file . ':1: cannot be read';
                continue;
            }
            array_push($problems, ...$this->checkMarkdown($file, $markdown));
        }

        return $problems;
    }

    /**
     * The Markdown files to check, relative to the root, sorted.
     *
     * @return list<string>
     */
    public function markdownFiles(): array
    {
        $files = [];
        $this->collect('', $files);
        sort($files);

        return $files;
    }

    /**
     * The problems of one document. `$file` is its path relative to the root (links are resolved from its directory).
     *
     * @return list<string>
     */
    public function checkMarkdown(string $file, string $markdown): array
    {
        $parsed = self::parse($markdown);
        $problems = [];
        $reported = static function (int $line, string $message) use ($file, &$problems): void {
            $problems[] = sprintf('%s:%d: %s', $file, $line, $message);
        };
        $this->anchorCache[$file] = $parsed['anchors'];

        foreach ($parsed['links'] as $link) {
            $problem = $this->problemWith($file, $link['dest']);
            if ($problem !== null) {
                $reported($link['line'], $problem);
            }
        }
        foreach ($parsed['undefined'] as $undefined) {
            $reported($undefined['line'], sprintf('the reference link [%s] has no definition', $undefined['label']));
        }
        usort($problems, static fn(string $a, string $b): int => self::lineOf($a) <=> self::lineOf($b));

        return $problems;
    }

    /**
     * What GitHub makes of a heading's text: the id of its anchor, before the `-1`, `-2` suffix of a duplicate. The text
     * is the heading's source without the leading `#`s: inline code, emphasis, links and HTML are reduced to what is
     * shown, then the text is lowercased, everything but letters, numbers, marks, `_`, `-` and spaces is removed and each
     * space becomes a hyphen (so "A & B" is `a--b`).
     */
    public static function slug(string $heading): string
    {
        $codeSpans = [];
        $text = preg_replace_callback('/(`+)(.+?)(?<!`)\1(?!`)/s', static function (array $m) use (&$codeSpans): string {
            $codeSpans[] = trim($m[2]);

            return "\u{E000}" . (count($codeSpans) - 1) . "\u{E001}";
        }, $heading) ?? $heading;
        $text = preg_replace('/<[^>]*>/', '', $text) ?? $text;                   // inline HTML
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;  // an image shows its alt text
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;   // a link shows its text
        $text = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/', '$1', $text) ?? $text;  // a reference link too
        $text = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $text) ?? $text;  // backslash escapes
        // emphasis with underscores (`_x_`, `__x__`), only at word boundaries: `candidate_limit` keeps its underscore
        $text = preg_replace('/(?<![\p{L}\p{N}_])(_{1,3})(?=\S)(.+?)(?<=\S)\1(?![\p{L}\p{N}_])/u', '$2', $text) ?? $text;
        $text = preg_replace_callback("/\u{E000}(\\d+)\u{E001}/", static fn(array $m): string => $codeSpans[(int) $m[1]] ?? '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{M}\p{N}_ -]/u', '', $text) ?? $text;

        return str_replace(' ', '-', $text);
    }

    /**
     * The anchors of a document: the slug of each heading (`-1`, `-2` for repeats, as GitHub numbers them) and every
     * `id` or `name` attribute of its HTML.
     *
     * @return array<string, true>
     */
    public static function anchors(string $markdown): array
    {
        return self::parse($markdown)['anchors'];
    }

    /**
     * The link destinations of a document with their line numbers: inline links and images, the destinations of
     * reference definitions, and `href` / `src` attributes. Nothing inside code is a link.
     *
     * @return list<array{line: int, dest: string}>
     */
    public static function links(string $markdown): array
    {
        return self::parse($markdown)['links'];
    }

    /**
     * @return array{anchors: array<string, true>, links: list<array{line: int, dest: string}>, undefined: list<array{line: int, label: string}>}
     */
    private static function parse(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $lines = self::blankFences($lines);

        // headings are read before inline code is hidden: their code spans are part of the text
        $anchors = [];
        $seen = [];
        foreach ($lines as $line) {
            if (preg_match('/^ {0,3}#{1,6}(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m) !== 1) {
                continue;
            }
            $base = self::slug($m[1] ?? '');
            $slug = $base;
            while (isset($seen[$slug])) {
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $slug = $base . '-' . $seen[$base];
            }
            $seen[$slug] = 0;
            $anchors[$slug] = true;
        }

        $prose = self::maskHtmlComments(self::maskInlineCode(implode("\n", $lines)));
        $proseLines = explode("\n", $prose);
        $links = [];
        $definitions = [];
        $references = [];
        foreach ($proseLines as $index => $line) {
            $number = $index + 1;
            if (preg_match('/^ {0,3}\[([^\]]+)\]:[ \t]*(<[^>]*>|\S+)/', $line, $m) === 1 && !str_starts_with($m[1], '^')) {
                $definitions[self::normalizeLabel($m[1])] = true;
                $links[] = ['line' => $number, 'dest' => self::unwrap($m[2])];
                continue;
            }
            foreach (self::inlineDestinations($line) as $dest) {
                $links[] = ['line' => $number, 'dest' => $dest];
            }
            if (preg_match_all('/<[^>]*\b(?:id|name)=["\']([^"\']+)["\'][^>]*>/i', $line, $ids) > 0) {
                foreach ($ids[1] as $id) {
                    $anchors[$id] = true;
                }
            }
            if (preg_match_all('/<[^>]*\b(?:href|src)=["\']([^"\']+)["\'][^>]*>/i', $line, $attributes) > 0) {
                foreach ($attributes[1] as $dest) {
                    $links[] = ['line' => $number, 'dest' => html_entity_decode($dest, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
                }
            }
            // [text][label] and [label][]: the label must be defined somewhere in the document
            if (preg_match_all('/(?<!\\\\)\[((?:[^\[\]]|\\\\.)+)\]\[([^\[\]]*)\]/', $line, $refs, PREG_SET_ORDER) > 0) {
                foreach ($refs as $ref) {
                    $label = $ref[2] === '' ? $ref[1] : $ref[2];
                    if (preg_match('/\p{L}/u', $label) === 1) {
                        $references[] = ['line' => $number, 'label' => $label];
                    }
                }
            }
        }
        $undefined = [];
        foreach ($references as $reference) {
            if (!isset($definitions[self::normalizeLabel($reference['label'])])) {
                $undefined[] = $reference;
            }
        }

        return ['anchors' => $anchors, 'links' => $links, 'undefined' => $undefined];
    }

    /**
     * The lines with every fenced code block (fences included) emptied, so line numbers stay.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function blankFences(array $lines): array
    {
        $fence = null; // [character, length] while inside a fenced block
        foreach ($lines as $i => $line) {
            if ($fence === null) {
                if (preg_match('/^[ \t]*(`{3,}|~{3,})(.*)$/', $line, $m) === 1 && !($m[1][0] === '`' && str_contains($m[2], '`'))) {
                    $fence = [$m[1][0], strlen($m[1])];
                    $lines[$i] = '';
                }
                continue;
            }
            if (preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/', $line, $m) === 1 && $m[1][0] === $fence[0] && strlen($m[1]) >= $fence[1]) {
                $fence = null;
            }
            $lines[$i] = '';
        }

        return $lines;
    }

    /** Replaces inline code spans by spaces (newlines stay, so line numbers do). A span never crosses a blank line. */
    private static function maskInlineCode(string $text): string
    {
        if (!str_contains($text, '`')) {
            return $text;
        }
        preg_match_all('/`+/', $text, $found, PREG_OFFSET_CAPTURE);
        $runs = $found[0];
        $count = count($runs);
        $out = $text;
        for ($i = 0; $i < $count; ++$i) {
            $length = strlen($runs[$i][0]);
            $closing = null;
            for ($j = $i + 1; $j < $count; ++$j) {
                if (strlen($runs[$j][0]) === $length) {
                    $closing = $j;
                    break;
                }
            }
            if ($closing === null) {
                continue;
            }
            $start = $runs[$i][1];
            $span = substr($text, $start, $runs[$closing][1] + $length - $start);
            if (preg_match('/\n[ \t]*\n/', $span) === 1) {
                continue; // the opening run is literal text
            }
            $out = substr_replace($out, preg_replace('/[^\n]/', ' ', $span) ?? $span, $start, strlen($span));
            $i = $closing;
        }

        return $out;
    }

    private static function maskHtmlComments(string $text): string
    {
        return preg_replace_callback('/<!--.*?-->/s', static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '', $text) ?? $text;
    }

    /**
     * The destinations of the inline links and images (`](dest "title")`) of one line.
     *
     * @return list<string>
     */
    private static function inlineDestinations(string $line): array
    {
        $destinations = [];
        $offset = 0;
        while (($at = strpos($line, '](', $offset)) !== false) {
            $offset = $at + 2;
            $length = strlen($line);
            $i = $offset;
            while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
                ++$i;
            }
            if ($i < $length && $line[$i] === '<') {
                $end = strpos($line, '>', $i);
                if ($end === false) {
                    continue;
                }
                $dest = substr($line, $i + 1, $end - $i - 1);
                $i = $end + 1;
            } else {
                $start = $i;
                $depth = 0;
                while ($i < $length) {
                    $c = $line[$i];
                    if ($c === '\\' && $i + 1 < $length) {
                        $i += 2;
                        continue;
                    }
                    if ($c === ' ' || $c === "\t") {
                        break;
                    }
                    if ($c === '(') {
                        ++$depth;
                    } elseif ($c === ')') {
                        if ($depth === 0) {
                            break;
                        }
                        --$depth;
                    }
                    ++$i;
                }
                $dest = substr($line, $start, $i - $start);
            }
            while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
                ++$i;
            }
            // after the destination: the closing parenthesis, a title, or the end of the line (a title may follow on the next)
            if ($i < $length && $line[$i] !== ')' && $line[$i] !== '"' && $line[$i] !== "'" && $line[$i] !== '(') {
                continue; // "[x](two words)" is not a link
            }
            $destinations[] = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $dest) ?? $dest;
        }

        return $destinations;
    }

    private static function unwrap(string $destination): string
    {
        return str_starts_with($destination, '<') && str_ends_with($destination, '>') ? substr($destination, 1, -1) : $destination;
    }

    private static function normalizeLabel(string $label): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($label)), 'UTF-8');
    }

    private static function lineOf(string $problem): int
    {
        return (int) substr($problem, (int) strpos($problem, ':') + 1);
    }

    /** The problem with one destination, or null when it is fine (or not ours to check). */
    private function problemWith(string $file, string $destination): ?string
    {
        $destination = trim($destination);
        if ($destination === '' || $destination === '#') {
            return null;
        }
        if (preg_match('~^https?://github\.com/' . preg_quote($this->repository, '~') . '/(?:blob|tree)/' . preg_quote($this->branch, '~') . '(?:/([^?#]*))?(?:\?[^#]*)?(?:#(.*))?$~i', $destination, $m) === 1
            || preg_match('~^https?://raw\.githubusercontent\.com/' . preg_quote($this->repository, '~') . '/' . preg_quote($this->branch, '~') . '/([^?#]*)(?:\?[^#]*)?(?:#(.*))?$~i', $destination, $m) === 1) {
            return $this->problemWithTarget(rawurldecode($m[1] ?? ''), $m[2] ?? null, $destination);
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $destination) === 1) {
            return null; // another site, mailto:, tel:, ...
        }

        $path = $destination;
        $fragment = null;
        if (($hash = strpos($path, '#')) !== false) {
            $fragment = substr($path, $hash + 1);
            $path = substr($path, 0, $hash);
        }
        if (($query = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $query);
        }
        if ($path === '') {
            return $this->problemWithAnchor($file, $fragment, $destination);
        }
        $path = rawurldecode($path);
        $resolved = str_starts_with($path, '/') ? ltrim($path, '/') : (dirname($file) === '.' ? '' : dirname($file) . '/') . $path;

        return $this->problemWithTarget($resolved, $fragment, $destination);
    }

    private function problemWithTarget(string $path, ?string $fragment, string $destination): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return sprintf('%s points outside the repository', $destination);
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $relative = implode('/', $segments);
        $kind = $this->kindOf($relative);
        if ($kind === null) {
            return sprintf('%s: no such file or directory', $destination);
        }
        if ($kind === 'file' && $fragment !== null && $fragment !== '' && str_ends_with($relative, '.md')) {
            return $this->problemWithAnchor($relative, $fragment, $destination);
        }

        return null; // a directory, or a file that is not Markdown (`file.php#L10`): the anchor is not ours to check
    }

    private function problemWithAnchor(string $file, ?string $fragment, string $destination): ?string
    {
        if ($fragment === null || $fragment === '' || str_starts_with($fragment, ':~:')) {
            return null;
        }
        $anchors = $this->anchorsOf($file);
        if ($anchors === null) {
            return sprintf('%s: cannot read %s', $destination, $file);
        }
        $wanted = rawurldecode($fragment);
        if (isset($anchors[$wanted])) {
            return null;
        }
        if (preg_match('/^L\d+(?:C\d+)?(?:-L\d+(?:C\d+)?)?$/', $wanted) === 1) {
            return null; // a line range
        }

        return sprintf('%s: %s has no heading or anchor "%s"', $destination, $file, $wanted);
    }

    /** @return array<string, true>|null */
    private function anchorsOf(string $file): ?array
    {
        if (!isset($this->anchorCache[$file])) {
            $markdown = @file_get_contents($this->root . '/' . $file);
            if ($markdown === false) {
                return null;
            }
            $this->anchorCache[$file] = self::anchors($markdown);
        }

        return $this->anchorCache[$file];
    }

    /** `file`, `dir` or null; every segment must match in case, as on GitHub (a Windows checkout would hide a mismatch). */
    private function kindOf(string $relative): ?string
    {
        $directory = '';
        $segments = $relative === '' ? [] : explode('/', $relative);
        foreach ($segments as $segment) {
            if (!in_array($segment, $this->listing($directory), true)) {
                return null;
            }
            $directory = $directory === '' ? $segment : $directory . '/' . $segment;
        }

        return is_dir($this->root . ($directory === '' ? '' : '/' . $directory)) ? 'dir' : 'file';
    }

    /** @return list<string> */
    private function listing(string $directory): array
    {
        if (!isset($this->listingCache[$directory])) {
            $names = @scandir($this->root . ($directory === '' ? '' : '/' . $directory));
            $this->listingCache[$directory] = $names === false ? [] : array_values(array_diff($names, ['.', '..']));
        }

        return $this->listingCache[$directory];
    }

    /** @param list<string> $files */
    private function collect(string $directory, array &$files): void
    {
        foreach ($this->listing($directory) as $name) {
            $relative = $directory === '' ? $name : $directory . '/' . $name;
            if (is_dir($this->root . '/' . $relative)) {
                if (!in_array($name, self::SKIPPED_ANYWHERE, true) && !in_array($relative, self::SKIPPED_PREFIXES, true)) {
                    $this->collect($relative, $files);
                }
            } elseif (str_ends_with($name, '.md')) {
                $files[] = $relative;
            }
        }
    }
}
