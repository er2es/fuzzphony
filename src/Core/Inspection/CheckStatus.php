<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Inspection;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';
}
