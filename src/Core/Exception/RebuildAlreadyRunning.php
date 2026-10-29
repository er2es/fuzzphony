<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/**
 * Another run holds the index's rebuild lock (a full reindex, or the worker's rebuild job): try
 * again when it has finished. A \RuntimeException: the state of the index, not a wrong argument.
 */
final class RebuildAlreadyRunning extends \RuntimeException implements FuzzphonyException {}
