<?php

declare(strict_types=1);

namespace App\Search;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

/**
 * The index `people` of the ERP data set (sql/erp.sql) as a definition class: the customers who are persons, searched by name,
 * birth name and e-mail, with the cities and streets of their addresses as several values each (`many`). The other ERP indexes
 * are in config/packages/fuzzphony.yaml; the class is a service by autoconfiguration (config/services.yaml).
 */
#[AsIndexDefinition('people')]
final class PeopleAdminIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromQuery("SELECT id, name, birth_name, email, tax_no, created_at FROM erp_customer WHERE kind = 'person'")
            ->field('name', 'A', fuzzy: true)
            ->field('birth_name', 'B', fuzzy: true)
            ->field('email', 'C')
            ->field('city', 'C', fuzzy: true, many: ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'city', 'limit' => 50])
            ->field('street', 'D', many: ['from' => 'erp_address', 'key' => 'customer_id', 'column' => 'street', 'limit' => 50])
            ->filter('created_at', 'datetime')
            ->watch('erp_customer')
            ->returnable('name', 'email', 'tax_no');
    }
}
