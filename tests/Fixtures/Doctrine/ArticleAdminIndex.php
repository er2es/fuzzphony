<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures\Doctrine;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\SyncMode;

/** A second index over the entity of the attribute index "articles": the body weighs more, unpublished articles are searchable too. */
#[AsIndexDefinition('articles_admin', entity: Article::class)]
final class ArticleAdminIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromTable('fz_article')->field('title', 'A', fuzzy: true)->field('body', 'B')->filter('published', 'bool')->sync(SyncMode::Orm)->language('english', false);
    }
}
