<?php

declare(strict_types=1);

namespace App\Service;

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The version of Fuzzphony the footer shows. Composer knows it (`\Composer\InstalledVersions`, the pretty version), but only as a
 * release when the library was installed from a tag. The demo installs it from a path repository, where Composer says `dev-main` and
 * a reference that is not a commit of the repository: useless to a reader. So a release-looking Composer version wins; otherwise the
 * release is the newest `## [x.y.z]` heading of the CHANGELOG.md next to the library (it is copied into the image); only without that
 * file the Composer string is shown, with the short reference when there is one.
 */
final class Version
{
    private ?string $label = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/../CHANGELOG.md')]
        private readonly string $changelog,
    ) {}

    public function label(): string
    {
        return $this->label ??= $this->resolve();
    }

    private function resolve(): string
    {
        $pretty = null;
        $reference = null;
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('fuzzphony/fuzzphony')) {
            $pretty = InstalledVersions::getPrettyVersion('fuzzphony/fuzzphony');
            $reference = InstalledVersions::getReference('fuzzphony/fuzzphony');
        }
        if ($pretty !== null && preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/', $pretty, $m) === 1) {
            return $m[1];
        }
        $released = $this->fromChangelog();
        if ($released !== null) {
            return $released;
        }
        if ($pretty === null) {
            return 'dev';
        }

        return $reference === null || $reference === '' ? $pretty : $pretty . ' ' . substr($reference, 0, 7);
    }

    /** The newest released version in the CHANGELOG (only its head is read), or null. */
    private function fromChangelog(): ?string
    {
        $handle = @fopen($this->changelog, 'r');
        if ($handle === false) {
            return null;
        }
        try {
            for ($i = 0; $i < 200 && ($line = fgets($handle)) !== false; ++$i) {
                if (preg_match('/^## \[(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\]/', $line, $m) === 1) {
                    return $m[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
