<?php

declare(strict_types=1);

namespace App\Service;

use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * The second sample of the Config wizard page (`?sample=erp`): one index over the ERP tables (sql/erp.sql) that uses what the catalogue sample
 * does not: a source joined to the billing address, `many` fields from two child tables, an exact `code` field, `returnable` columns and
 * column-aware watches. It is built with the library's public builder, so it is validated like any definition. It is NOT one of the demo's
 * indexes (those are in config/packages/fuzzphony.yaml and src/Search/PeopleAdminIndex.php).
 */
final class ErpSampleConfig
{
    public const string NAME = 'customers';

    private const string QUERY = "SELECT cu.id, cu.name, cu.tax_no, cu.email, cu.created_at, ad.city AS billing_city\n"
        . "FROM erp_customer cu\n"
        . "LEFT JOIN erp_address ad ON ad.customer_id = cu.id AND ad.kind = 'billing'";

    /** The reading guide next to the export: part => [role, why]. */
    public const array NOTES = [
        'source' => ['joined query', 'One row per customer with the city of the billing address joined in: a single value, so it is an ordinary field and an ordinary filter.'],
        'name' => ['weight A, typo tolerant', 'Persons and companies are looked up by name; a typo in a name is forgiven.'],
        'tax_no' => ['weight A, code', 'A tax number is an identifier: with code: true 12345678-2-41 matches as the whole code or as a beginning, never by similarity.'],
        'email, billing_city' => ['weights C', 'Plain words of lower rank; the billing city is fuzzy.'],
        'contact' => ['many, from erp_contact', 'A company has several contact persons: each name is one value of the field, searched together, found as "found in a contact person: ..." by explainMatches().'],
        'street' => ['many, from erp_address', 'Every address of the customer (billing and shipping), up to 20 values, read from the child table.'],
        'created_at, billing_city' => ['filters', 'A date range and a city list for where() and for facets.'],
        'watch' => ['column-aware', 'erp_customer re-indexes the customer; erp_address only re-indexes the customer when city or street changes (a change of the zip is ignored). The contact table is watched by the many field itself.'],
        'returnable' => ['name, email, tax_no, billing_city', 'with() gives an application these columns of the source row, so a result list needs no second query and no entity.'],
    ];

    public function definition(): IndexDefinition
    {
        $contact = ['from' => 'erp_contact', 'key' => 'customer_id', 'column' => 'name', 'limit' => 20];
        $street = ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'street', 'limit' => 20];

        return IndexDefinition::builder(self::NAME)
            ->fromQuery(self::QUERY)
            ->field('name', 'A', fuzzy: true)
            ->field('tax_no', 'A', code: true)
            ->field('email', 'C')
            ->field('billing_city', 'C', fuzzy: true)
            ->field('contact', 'B', fuzzy: true, many: $contact)
            ->field('street', 'D', many: $street)
            ->filter('created_at', 'datetime')
            ->filter('billing_city', 'string')
            ->watch('erp_customer')
            ->watch('erp_address', 'SELECT :id', 'customer_id', ['city', 'street'])
            ->returnable('name', 'email', 'tax_no', 'billing_city')
            ->build();
    }

    /** The same definition as a definition class (the wizard has no such export: this wraps the calls of the builder export). */
    public function definitionClass(string $builderExport): string
    {
        $start = strpos($builderExport, '->fromQuery');
        $end = strrpos($builderExport, '->build();');
        $calls = $start === false || $end === false ? '' : '    ' . substr($builderExport, $start, $end - $start);
        $body = implode("\n", array_map(static fn (string $l): string => $l === '' ? '' : '    ' . $l, explode("\n", rtrim($calls))));

        return "use Fuzzphony\\Core\\Attribute\\AsIndexDefinition;\nuse Fuzzphony\\Core\\Definition\\IndexBuilder;\n\n"
            . "#[AsIndexDefinition('" . self::NAME . "')]\nfinal class CustomerAdminIndex\n{\n"
            . "    public function build(IndexBuilder \$b): void\n    {\n        \$b\n" . $body . ";\n    }\n}\n";
    }
}
