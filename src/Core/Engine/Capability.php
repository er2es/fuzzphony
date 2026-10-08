<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

enum Capability: string
{
    case FullText = 'full_text';
    case Stemming = 'stemming';
    case AccentFolding = 'accent_folding';
    case Fuzzy = 'fuzzy';
    case FieldWeights = 'field_weights';
    case FieldScopedQueries = 'field_scoped_queries';
    case Phrase = 'phrase';
    case Prefix = 'prefix';
    case Highlight = 'highlight';
    case DatabaseTriggers = 'database_triggers';
    case ConcurrentIndexBuild = 'concurrent_index_build';
}
