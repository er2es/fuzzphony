<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** A runtime argument a developer passed is wrong: a batch size below 1, an unknown table, an exporter that cannot express the definition. */
final class InvalidArgument extends \InvalidArgumentException implements FuzzphonyException {}
