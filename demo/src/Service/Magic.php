<?php

declare(strict_types=1);

namespace App\Service;

use Fuzzphony\Core\Search\Hit;

/**
 * What the header's search box (MagicController) needs to show a hit: the title of the index it came from, a row built from `Hit::$data`
 * alone, and the routes (name and parameters, for generateUrl()) of the row and of the index's own page with the same text.
 */
final class Magic
{
    /** The group titles of the indexes that are not a language: index => title. */
    private const array TITLES = ['people' => 'People', 'companies' => 'Companies', 'orders' => 'Orders', 'catalog' => 'Catalogue', 'intranet' => 'Intranet notes'];

    /** The examples of the empty box: label => query. */
    public const array EXAMPLES = ['a surname' => 'kovacs', 'a legal form' => 'kft', 'an order number' => 'ORD-2025', 'a product' => 'wireless mouse', 'a note' => 'salary'];

    public static function title(string $index): string
    {
        if (isset(self::TITLES[$index])) {
            return self::TITLES[$index];
        }
        foreach (Languages::LANGUAGES as $language) {
            if ($language['index'] === $index) {
                return $language['name'] . ' products';
            }
        }

        return $index;
    }

    /**
     * The index's own page with the text: [route, parameters].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function indexUrl(string $index, string $q): array
    {
        return match (true) {
            isset(Erp::TABS[$index]) => ['erp', ['tab' => $index, 'q' => $q]],
            $index === 'catalog' => ['search', ['q' => $q]],
            $index === 'intranet' => ['security', ['q' => $q]],
            default => ['languages', ['lang' => str_replace('lang_', '', $index), 'q' => $q]],
        };
    }

    /**
     * One row: the title and the line under it, the kind (the box shows it when it differs from the index) and where it links to: the ERP
     * detail page, else the index's own page with the title as the text (the catalogue, the notes and the language products have no page of one).
     *
     * @return array{title: string, sub: string, kind: string, url: array{0: string, 1: array<string, string|int>}}
     */
    public static function row(Hit $hit): array
    {
        $d = $hit->data;
        $text = static fn (string $key): string => isset($d[$key]) ? (string) $d[$key] : '';
        $join = static fn (array $parts): string => implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
        if (isset(Erp::TABS[$hit->index])) {
            $row = Erp::describe($hit);

            return ['title' => $row['title'], 'sub' => $row['sub'], 'kind' => $hit->kind, 'url' => ['erp_detail', ['kind' => $hit->kind, 'id' => $hit->id]]];
        }
        [$title, $sub] = match ($hit->index) {
            'catalog' => [$text('name'), $join([$text('brand'), $text('category')])],
            'intranet' => [$text('title'), mb_strimwidth($text('body'), 0, 90, '…')],
            default => [$text('name'), $text('category')],
        };
        [$route, $params] = self::indexUrl($hit->index, $title);

        return ['title' => $title, 'sub' => $sub, 'kind' => $hit->kind, 'url' => [$route, $params]];
    }
}
