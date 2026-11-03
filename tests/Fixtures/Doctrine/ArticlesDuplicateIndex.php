<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures\Doctrine;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

/** Takes the name of the attribute index of Article: a conflict. */
#[AsIndexDefinition('articles', entity: Article::class)]
final class ArticlesDuplicateIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromTable('fz_article')->field('title', 'A');
    }
}
