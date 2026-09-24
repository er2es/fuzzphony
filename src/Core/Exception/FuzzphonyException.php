<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/**
 * Marker for every exception thrown by Fuzzphony.
 *
 * Rule of thumb: end-user search text NEVER throws (it degrades and reports warnings);
 * developer mistakes (bad definitions, unknown filters, wrong value types) always throw
 * with a message that says what is wrong and how to fix it.
 */
interface FuzzphonyException extends \Throwable {}
