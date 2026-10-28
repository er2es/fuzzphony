<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A line ending written as a raw newline inside a string literal (`implode("<LF>    ", $parts)`) is invisible in a review and
 * is silently changed by an editor or a git line-ending setting; the library source writes them as escapes.
 */
final class SourceHygieneTest extends TestCase
{
    public function testNoSourceFileHoldsARawNewlineInsideAShortStringLiteral(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // a double-quoted string that is only a line break and indentation (the glue of an implode, an argument of str_replace)
            if (preg_match('/"\R[ \t]*"/', $code) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            }
            // a carriage return or a NUL byte anywhere in the file
            if (preg_match('/[\r\x00]/', $code) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1) . ' (CR or NUL)';
            }
        }

        self::assertSame([], $offenders, 'write the line break as "\n" (or "\r\n")');
    }
}
