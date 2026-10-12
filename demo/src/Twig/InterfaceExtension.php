<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CategoryStyle;
use Twig\Attribute\AsTwigFunction;

/** Template helpers of the interface: the tile (drawing and colour) of a category. */
final readonly class InterfaceExtension
{
    public function __construct(private CategoryStyle $categories) {}

    /** @return array{icon: string, hue: int} */
    #[AsTwigFunction('category_style')]
    public function categoryStyle(string $category): array
    {
        return $this->categories->of($category);
    }
}
