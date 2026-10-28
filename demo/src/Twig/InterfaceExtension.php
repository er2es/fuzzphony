<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CategoryStyle;
use Twig\Attribute\AsTwigFilter;
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

    /** The doctor writes "1 item(s) waiting"; the page says "1 item waiting" or "0 items waiting". */
    #[AsTwigFilter('plain_count')]
    public function plainCount(string $message): string
    {
        return preg_replace_callback('/(\d+) item\(s\)/', static fn (array $m): string => $m[1] . ($m[1] === '1' ? ' item' : ' items'), $message) ?? $message;
    }
}
