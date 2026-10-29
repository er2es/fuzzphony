<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CategoryStyle;
use App\Service\Version;
use Twig\Attribute\AsTwigFunction;

/** Template helpers of the interface: the tile (drawing and colour) of a category, the version for the footer. */
final readonly class InterfaceExtension
{
    public function __construct(private CategoryStyle $categories, private Version $version) {}

    /** @return array{icon: string, hue: int} */
    #[AsTwigFunction('category_style')]
    public function categoryStyle(string $category): array
    {
        return $this->categories->of($category);
    }

    /** The version of Fuzzphony for the footer (see Version). */
    #[AsTwigFunction('fuzzphony_version')]
    public function fuzzphonyVersion(): string
    {
        return $this->version->label();
    }
}
