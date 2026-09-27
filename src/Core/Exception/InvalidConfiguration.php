<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** Engine or bundle configuration is wrong: an invalid schema name, orm_sync.async without Messenger, no index configured. */
final class InvalidConfiguration extends \InvalidArgumentException implements FuzzphonyException {}
