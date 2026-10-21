<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\AdminPanel;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Fuzzphony\Core\Fuzzphony;

// region: filter
/**
 * Restricts the collection to what the search finds and leaves the order to the other filters
 * (?order[name]=asc), which FuzzphonySearchFilter does not: it orders by relevance.
 */
final class SearchRestrictionFilter implements FilterInterface
{
    public function __construct(private readonly Fuzzphony $fuzzphony) {}

    /** @param array<string, mixed> $context */
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $filters = $context['filters'] ?? null;
        $text = is_array($filters) ? ($filters['q'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            return;
        }

        $ids = $this->fuzzphony->in($resourceClass)->query($text)->limit(500)->get()->ids();
        $parameter = $queryNameGenerator->generateParameterName('search_ids');
        $queryBuilder
            ->andWhere(sprintf('%s.id IN (:%s)', $queryBuilder->getRootAliases()[0], $parameter))
            ->setParameter($parameter, $ids === [] ? [0] : $ids);
    }

    /** @return array<string, array{type?: string, required?: bool}> */
    public function getDescription(string $resourceClass): array
    {
        return ['q' => ['type' => 'string', 'required' => false]];
    }
}
// endregion
