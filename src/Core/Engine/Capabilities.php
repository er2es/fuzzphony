<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

/**
 * What an engine does natively vs. emulates. Drives the wizard/playground UI and lets
 * conformance tests skip features an engine does not claim to support.
 */
final readonly class Capabilities
{
    /**
     * @param list<Capability>          $native
     * @param array<string, string>     $emulated capability value => how it is emulated
     */
    public function __construct(
        public array $native,
        public array $emulated = [],
    ) {}

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->native, true) || isset($this->emulated[$capability->value]);
    }

    public function isNative(Capability $capability): bool
    {
        return in_array($capability, $this->native, true);
    }
}
