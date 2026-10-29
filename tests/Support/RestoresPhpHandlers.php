<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Booting a Symfony kernel registers ErrorHandler's error and exception handlers; the oldest supported
 * FrameworkBundle never unregisters them, which PHPUnit reports as risky. Put the handlers back after the test.
 *
 * @internal
 */
trait RestoresPhpHandlers
{
    /** @var callable|null */
    private $handlersExceptionBefore;

    /** @var callable|null */
    private $handlersErrorBefore;

    #[Before]
    protected function rememberPhpHandlers(): void
    {
        $this->handlersExceptionBefore = set_exception_handler(null);
        restore_exception_handler();
        $this->handlersErrorBefore = set_error_handler(null);
        restore_error_handler();
    }

    #[After]
    protected function restorePhpHandlers(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $current = set_exception_handler(null);
            restore_exception_handler();
            if ($current === $this->handlersExceptionBefore) {
                break;
            }
            restore_exception_handler();
        }
        for ($i = 0; $i < 20; ++$i) {
            $current = set_error_handler(null);
            restore_error_handler();
            if ($current === $this->handlersErrorBefore) {
                break;
            }
            restore_error_handler();
        }
    }
}
