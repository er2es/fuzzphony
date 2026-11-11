<?php

declare(strict_types=1);

namespace App\Search;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

/**
 * `people_whole`: the index `people` with the default ranking of the address field (`rank: whole`, the addresses of a person ranked
 * as one text). It exists only so the People tab can show, with the same data, what "Rank by the best address" changes. It is a
 * snapshot of the seeded data (`sync: manual`: no trigger on the ERP tables, filled by the reindex of docker/init.sh), so the
 * sync demonstration still speaks about `people` and `site` alone.
 */
#[AsIndexDefinition('people_whole')]
final class PeopleWholeIndex
{
    public function build(IndexBuilder $b): void
    {
        PeopleAdminIndex::define($b, 'whole');
        $b->sync('manual');
    }
}
