<?php

declare(strict_types=1);

/**
 * Checks the relative links, the anchors and the absolute links to this repository in every Markdown file.
 *
 *     php tools/check-docs-links.php [root]
 *
 * Prints each problem as `file:line: message` and exits with 1 when there is one. No dependencies, no network.
 */

use Fuzzphony\Tools\DocsLinkChecker;

require_once __DIR__ . '/DocsLinkChecker.php';

$root = $argv[1] ?? dirname(__DIR__);
$checker = new DocsLinkChecker($root);
$problems = $checker->check();

foreach ($problems as $problem) {
    fwrite(STDERR, $problem . "\n");
}
if ($problems !== []) {
    fwrite(STDERR, sprintf("\n%d broken link%s or anchor%s.\n", count($problems), count($problems) === 1 ? '' : 's', count($problems) === 1 ? '' : 's'));
    exit(1);
}
echo sprintf("Checked %d Markdown files: no broken links or anchors.\n", count($checker->markdownFiles()));
