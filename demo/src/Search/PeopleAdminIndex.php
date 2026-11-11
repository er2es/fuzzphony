<?php

declare(strict_types=1);

namespace App\Search;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

/**
 * The index `people` of the ERP data set (sql/erp.sql) as a definition class: the customers who are persons, searched by name,
 * birth name and e-mail, with their addresses as several values each (`many`). The address is one text per address ("1051 Budapest,
 * Kossuth Lajos utca 12", the generated column erp_address.line) and the field ranks by its best address (`rank: best`): a person
 * whose Fő utca and Szeged are in two different addresses does not outrank one whose single address has both. The People tab
 * compares it with PeopleWholeIndex (the same index with the default ranking). The other ERP indexes are in
 * config/packages/fuzzphony.yaml; the class is a service by autoconfiguration (config/services.yaml).
 */
#[AsIndexDefinition('people')]
final class PeopleAdminIndex
{
    public function build(IndexBuilder $b): void
    {
        self::define($b, 'best');
    }

    /** The definition of both people indexes; only the ranking of the address field differs. */
    public static function define(IndexBuilder $b, string $rank): void
    {
        $b->fromQuery("SELECT id, name, birth_name, email, tax_no, created_at FROM erp_customer WHERE kind = 'person'")
            ->field('name', 'A', fuzzy: true)
            ->field('birth_name', 'B', fuzzy: true)
            ->field('email', 'C')
            ->field('address', 'C', fuzzy: true, many: ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'line', 'limit' => 50, 'rank' => $rank])
            ->filter('city', 'string', many: ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'city', 'limit' => 50])
            ->filter('created_at', 'datetime')
            ->watch('erp_customer')
            ->returnable('name', 'email', 'tax_no', 'birth_name', 'address');
    }
}
