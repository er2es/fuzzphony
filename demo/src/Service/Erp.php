<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\MatchedWord;

/**
 * What the ERP page needs besides the searches themselves: how a hit becomes a row (from `Hit::$data`, no database lookup), the
 * "found in ..." lines (from `Hit::$matches`), the link of a row, and the read-only SQL of the detail page. Everything that touches
 * the ERP tables here is a SELECT; the one write of the demo is the sync demonstration (ErpSync).
 */
final readonly class Erp
{
    /** The searches of the page: tab => index, the label, the example chips (label => query) and the one-line sentence under the title. */
    public const array TABS = [
        'people' => [
            'index' => 'people', 'label' => 'People', 'field' => 'Search people',
            'about' => 'Persons by name, birth name, e-mail and their addresses (several values per person, each address one value).',
            'examples' => ['name' => 'kovacs', 'city of an address' => 'kovacs budapest', 'a typo' => 'kovacz nora', 'two addresses or one' => 'fő utca szeged'],
        ],
        'companies' => [
            'index' => 'companies', 'label' => 'Companies', 'field' => 'Search companies',
            'about' => 'Companies by name, by tax number and phone as exact codes, and by the names of their contact persons.',
            'examples' => ['legal form' => 'kft', 'tax number' => '10079190-1-12', 'contact person' => 'contact:simon'],
        ],
        'orders' => [
            'index' => 'orders', 'label' => 'Orders', 'field' => 'Search orders',
            'about' => 'Orders by their number (an exact code, a beginning works too) and by the customer.',
            'examples' => ['order number' => 'ORD-2026-044127', 'a beginning' => 'ORD-2025', 'customer' => 'kovacs'],
        ],
    ];

    /** The index of the People tab with the default ranking of the address field (src/Search/PeopleWholeIndex.php), for the comparison. */
    public const string PEOPLE_WHOLE = 'people_whole';

    /** The fields whose matched text the rows show as a muted line (Hit::$highlights), per tab. */
    public const array HIGHLIGHTED = [
        'people' => ['birth_name', 'address'],
        'companies' => ['contact'],
        'orders' => ['customer_name'],
    ];

    /** The muted lines under a row: field => label. */
    private const array LINE_LABELS = ['birth_name' => 'Birth name', 'address' => 'Address', 'contact' => 'Contact', 'customer_name' => 'Customer'];

    /** The date filter of People and Companies: key => [label, from, to] (both inclusive, ISO dates). */
    public const array CREATED = [
        '2026' => ['Created in 2026', '2026-01-01T00:00:00+00:00', '2026-12-31T23:59:59+00:00'],
        '2025' => ['Created in 2025', '2025-01-01T00:00:00+00:00', '2025-12-31T23:59:59+00:00'],
        'older' => ['Created before 2025', '2020-01-01T00:00:00+00:00', '2024-12-31T23:59:59+00:00'],
    ];

    /** The total filter of Orders: key => [label, from, to]. */
    public const array TOTALS = [
        'small' => ['Up to 100 000 Ft', 0, 100000],
        'middle' => ['100 000 to 500 000 Ft', 100000, 500000],
        'large' => ['Over 500 000 Ft', 500000, 100000000],
    ];

    /** The chips under the combined box: label => query. */
    public const array COMBINED_EXAMPLES = ['a surname' => 'kovacs', 'a legal form' => 'kft', 'order numbers' => 'ORD-2025'];

    /** Which table a kind (the index name of a single-source index, the source name of the multi-source `site`) is read from. */
    private const array KINDS = [
        'people' => 'customer', 'people_whole' => 'customer', 'person' => 'customer',
        'companies' => 'customer', 'company' => 'customer',
        'orders' => 'order', 'order' => 'order',
    ];

    /** How the page names a field in "found in ...". */
    private const array FIELD_LABELS = [
        'address' => 'the address', 'city' => 'the address', 'street' => 'the address', 'contact' => 'a contact person', 'name' => 'the name', 'birth_name' => 'the birth name',
        'email' => 'the e-mail', 'tax_no' => 'the tax number', 'phone' => 'the phone', 'number' => 'the order number',
        'customer_name' => 'the customer', 'title' => 'the title', 'text' => 'the text',
    ];

    /** The fields that are exact codes (`code: true`). */
    private const array CODES = ['tax_no', 'phone', 'number'];

    public function __construct(private Connection $connection) {}

    /**
     * What a hit shows as a row, built from `Hit::$data` alone: [title, sub line, short label of the kind].
     *
     * @return array{title: string, sub: string, label: string}
     */
    public static function describe(Hit $hit): array
    {
        $d = $hit->data;
        $text = static fn (string $key): string => isset($d[$key]) ? (string) $d[$key] : '';
        $ft = static fn (mixed $n): string => number_format((int) $n, 0, '.', ' ') . ' Ft';

        return match ($hit->kind) {
            'people', 'people_whole', 'person' => ['title' => $text('name') ?: $text('title'), 'sub' => self::join([$text('email'), $text('tax_no')]), 'label' => 'person'],
            'companies', 'company' => ['title' => $text('name') ?: $text('title'), 'sub' => self::join([$text('tax_no'), $text('email'), $text('phone')]), 'label' => 'company'],
            'orders', 'order' => [
                'title' => $text('number') ?: $text('title'),
                'sub' => self::join([$text('customer_name'), $text('status'), isset($d['total']) ? $ft($d['total']) : '']),
                'label' => 'order',
            ],
            default => ['title' => (string) $hit->id, 'sub' => '', 'label' => $hit->kind],
        };
    }

    /**
     * The muted lines of a row: what the search matched in the fields the row does not show, with the query words in `<mark>` (the library's
     * highlight, HTML-escaped already, so it is printed as it is). A person without a match in the address shows the first address, and a birth
     * name that differs from the name, as plain (escaped) text. For a `rank: best` field the highlight is the best value.
     *
     * @return list<array{label: string, html: string}>
     */
    public static function matchLines(Hit $hit): array
    {
        $lines = [];
        $person = in_array($hit->kind, ['people', 'people_whole'], true);
        foreach (self::LINE_LABELS as $field => $label) {
            $html = $hit->highlights[$field] ?? null;
            if ($html === null && $person) {
                $value = $hit->data[$field] ?? null;
                $value = is_array($value) ? ($value[0] ?? null) : $value;
                if (is_string($value) && $value !== '' && !($field === 'birth_name' && $value === ($hit->data['name'] ?? null))) {
                    $html = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
                }
            }
            if ($html !== null && $html !== '') {
                $lines[] = ['label' => $label, 'html' => $html];
            }
        }

        return $lines;
    }

    /** @param list<string> $parts */
    private static function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** The URL parameters of the detail page of a hit. */
    public static function link(Hit $hit): array
    {
        return ['kind' => $hit->kind, 'id' => $hit->id];
    }

    /**
     * The "found in ..." lines of a hit (made with explainMatches()): a value of a field with several values names the value
     * ("found in the address: Budapest"), a code field says it matched as a code, anything else names the field and the word.
     *
     * @return list<string>
     */
    public static function reasons(Hit $hit, string $query = '', bool $strict = false, string $city = ''): array
    {
        $lines = [];
        // a code (tax number, phone, order number) typed whole, or its beginning, matches as a code: the row says so
        $typed = mb_strtolower(trim($query));
        foreach (self::CODES as $code) {
            $value = mb_strtolower((string) ($hit->data[$code] ?? ''));
            if ($typed !== '' && $value !== '' && str_starts_with($value, $typed) && preg_match('/\d/', $typed) === 1) {
                $lines[sprintf('found in %s: %s (%s)', self::FIELD_LABELS[$code], $hit->data[$code], $value === $typed ? 'exact code' : 'beginning of a code')] = true;
            }
        }
        // the value a `rank: best` field ranked by (ScoreBreakdown::$bestValues: field => position of the value) comes first and says so
        $matches = $hit->matches ?? [];
        $best = static fn (MatchedWord $m): bool => $m->valueOrd !== null && ($hit->breakdown->bestValues[$m->field] ?? null) === $m->valueOrd;
        usort($matches, static fn (MatchedWord $a, MatchedWord $b): int => $best($b) <=> $best($a));
        foreach ($matches as $m) {
            // strict mode: only the address that holds all the words counts; an address with just one of the words is not a match of the search
            if ($strict && $m->field === 'address' && $m->valueOrd !== null && !$best($m)) {
                continue;
            }
            $where = self::FIELD_LABELS[$m->field] ?? $m->field;
            $line = match (true) {
                $strict && $m->value !== null && $best($m) => 'one address holds all the words: ' . $m->value,
                $m->value !== null && $best($m) && count(array_unique(array_filter(array_map(static fn (MatchedWord $o): ?int => $o->valueOrd, $matches), static fn (?int $o): bool => $o !== null))) > 1 => 'found in the best address: ' . $m->value,
                $m->value !== null => sprintf('found in %s: %s', $where, $m->value),
                in_array($m->field, self::CODES, true) => sprintf('found in %s: %s (%s)', $where, $m->word, $m->via === MatchedWord::EXACT ? 'exact code' : 'beginning of a code'),
                $m->via === MatchedWord::TYPO => sprintf('found in %s: %s (a close spelling)', $where, $m->word),
                default => sprintf('found in %s: %s', $where, $m->word),
            };
            $lines[$line] = true;
        }

        $lines = array_slice(array_keys($lines), 0, 3);
        // the city filter holds for ANY address of the person, so it may be another address than the one the words matched: say which one
        if ($city !== '') {
            $addresses = $hit->data['address'] ?? [];
            foreach (is_array($addresses) ? $addresses : [$addresses] as $address) {
                if (is_string($address) && mb_stripos($address, $city) !== false) {
                    $lines[] = sprintf('city filter %s: %s', $city, $address);
                    break;
                }
            }
        }

        return $lines;
    }

    /** @return array<string, mixed>|null the customer row, or null */
    public function customer(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, kind, name, birth_name, tax_no, email, created_at FROM erp_customer WHERE id = :id', ['id' => $id]);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function addresses(int $customer): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, kind, zip, city, street FROM erp_address WHERE customer_id = :id ORDER BY id', ['id' => $customer]);
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $customer): array
    {
        return $this->connection->fetchAllAssociative('SELECT name, role, phone, email FROM erp_contact WHERE customer_id = :id ORDER BY id', ['id' => $customer]);
    }

    /** @return list<array<string, mixed>> */
    public function ordersOf(int $customer, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, number, status, total, ordered_at FROM erp_order WHERE customer_id = :id ORDER BY ordered_at DESC LIMIT ' . $limit,
            ['id' => $customer],
        );
    }

    /** @return array<string, mixed>|null the order row with its customer's name, or null */
    public function order(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT o.id, o.number, o.status, o.total, o.ordered_at, o.customer_id, cu.name AS customer_name, cu.kind AS customer_kind
             FROM erp_order o JOIN erp_customer cu ON cu.id = o.customer_id WHERE o.id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $order): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT l.qty, l.price, p.name AS product FROM erp_order_line l LEFT JOIN bench_product p ON p.id = l.product_id WHERE l.order_id = :id ORDER BY l.id',
            ['id' => $order],
        );
    }

    /** The table a kind is read from, or null for an unknown kind. */
    public static function tableOf(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }
}
