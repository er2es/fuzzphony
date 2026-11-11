<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CategoryStyle;
use App\Service\Erp;
use Fuzzphony\Core\Search\Hit;
use App\Service\SettingHelp;
use App\Service\Version;
use Twig\Attribute\AsTwigFunction;

/** Template helpers of the interface: the tile (drawing and colour) of a category, the version for the footer, the help of a Tune control. */
final readonly class InterfaceExtension
{
    public function __construct(private CategoryStyle $categories, private Version $version, private SettingHelp $help) {}

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

    /**
     * The info tooltip text of a control of the Tune page (see SettingHelp).
     *
     * @return array{what: \Twig\Markup, example: \Twig\Markup}
     */
    #[AsTwigFunction('setting_help')]
    public function settingHelp(string $key): array
    {
        return $this->help->get($key) ?? throw new \LogicException(sprintf('No help text for the setting "%s".', $key));
    }

    /** @return array{title: string, sub: string, label: string} the row of an ERP hit, from Hit::$data (the ERP page) */
    #[AsTwigFunction('erp_row')]
    public function erpRow(Hit $hit): array
    {
        return Erp::describe($hit);
    }

    /** @return list<string> the "found in ..." lines of an ERP hit, from Hit::$matches */
    /** @return list<array{label: string, html: string}> */
    #[AsTwigFunction('erp_lines')]
    public function erpLines(Hit $hit): array
    {
        return Erp::matchLines($hit);
    }

    #[AsTwigFunction('erp_reasons')]
    public function erpReasons(Hit $hit, string $query = ''): array
    {
        return Erp::reasons($hit, $query);
    }
}
