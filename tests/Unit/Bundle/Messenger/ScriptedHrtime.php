<?php

declare(strict_types=1);

/*
 * A scripted clock for RefreshDocumentsHandler: PHP looks an unqualified function call up in the
 * calling namespace first, so declaring hrtime() in Fuzzphony\Bundle\Messenger makes the handler read
 * this one. It reads the real clock unless a test scripts the next readings (ScriptedHrtime::$readings).
 */

namespace Fuzzphony\Tests\Unit\Bundle\Messenger {
    final class ScriptedHrtime
    {
        /** @var list<int> nanoseconds, returned one per hrtime(true) call; the real clock when empty */
        public static array $readings = [];
    }
}

namespace Fuzzphony\Bundle\Messenger {
    use Fuzzphony\Tests\Unit\Bundle\Messenger\ScriptedHrtime;

    function hrtime(bool $asNumber = false): int|float
    {
        $scripted = array_shift(ScriptedHrtime::$readings);

        return $scripted ?? \hrtime(true);
    }
}
