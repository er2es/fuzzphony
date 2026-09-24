<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Fuzzphony\Core\Fuzzphony;

/**
 * Relevance search for API Platform collections:
 *
 *   #[ApiFilter(FuzzphonySearchFilter::class)]            // GET /products?q=wireles mouse
 *   #[ApiFilter(FuzzphonySearchFilter::class, arguments: ['parameterName' => 'search', 'profile' => 'popular'])]
 *
 * Restricts the collection to the best matches (at most $maxResults) and orders by score,
 * so pagination, other filters and serialization keep working as usual.
 */
final class FuzzphonySearchFilter implements FilterInterface
{
    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly string $parameterName = 'q',
        private readonly string $profile = 'default',
        private readonly int $maxResults = 500,
        private readonly ?array $properties = null,
    ) {}

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $text = $context['filters'][$this->parameterName] ?? null;
        if (!is_string($text) || trim($text) === '' || !$this->fuzzphony->registry()->has($resourceClass)) {
            return;
        }

        $result = $this->fuzzphony->in($resourceClass)->query($text)->profile($this->profile)->limit(min(1000, max(1, $this->maxResults)))->get();
        $alias = $queryBuilder->getRootAliases()[0];
        $idField = $queryBuilder->getEntityManager()->getClassMetadata($resourceClass)->getSingleIdentifierFieldName();

        if ($result->hits === []) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $ids = $queryNameGenerator->generateParameterName('fuzzphony_ids');
        $queryBuilder->andWhere(sprintf('%s.%s IN (:%s)', $alias, $idField, $ids))->setParameter($ids, $result->ids());

        // Keep Fuzzphony's order: CASE on the id, as a hidden select used for sorting.
        $cases = [];
        foreach ($result->ids() as $position => $id) {
            $parameter = $queryNameGenerator->generateParameterName('fuzzphony_rank');
            $cases[] = sprintf('WHEN %s.%s = :%s THEN %d', $alias, $idField, $parameter, $position);
            $queryBuilder->setParameter($parameter, $id);
        }
        $queryBuilder
            ->addSelect(sprintf('(CASE %s ELSE %d END) AS HIDDEN fuzzphony_rank', implode(' ', $cases), count($cases)))
            ->orderBy('fuzzphony_rank', 'ASC');
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            $this->parameterName => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'description' => 'Relevance search: typo tolerant, accent insensitive. Supports "phrases", -exclusions, OR, prefix* and field:value.',
                'openapi' => ['example' => 'wireless mouse -cable'],
            ],
        ];
    }
}
