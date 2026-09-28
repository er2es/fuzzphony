<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/**
 * Another run holds the index's rebuild lock (a full reindex, or the worker's rebuild job): try
 * again when it has finished. An \InvalidArgumentException, like InvalidArgument.
 */
final class RebuildAlreadyRunning extends \InvalidArgumentException implements FuzzphonyException {}
