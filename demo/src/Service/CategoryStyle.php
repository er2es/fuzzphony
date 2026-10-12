<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The drawing and the colour of a product category: one illustration per category (the sprite in
 * templates/_sprite.html.twig), one hue each, spaced evenly around the colour wheel at the same lightness and
 * chroma so no category shouts. The catalogue has ten categories; the language catalogue names the same things
 * in five languages, so the names of all of them map onto the same drawings. Unknown names get the neutral box.
 */
final class CategoryStyle
{
    /** icon => hue (degrees, OKLCH) */
    private const array HUES = [
        'mouse' => 25, 'keyboard' => 65, 'headphones' => 105, 'drill' => 145, 'kettle' => 185,
        'monitor' => 225, 'camera' => 265, 'lamp' => 305, 'plant' => 345, 'chair' => 5,
        'tent' => 125, 'generic' => -1,
    ];

    /** lowercase category name => icon */
    private const array NAMES = [
        'mice' => 'mouse', 'keyboards' => 'keyboard', 'headphones' => 'headphones', 'drills' => 'drill', 'kitchen' => 'kettle',
        'monitors' => 'monitor', 'cameras' => 'camera', 'lighting' => 'lamp', 'garden' => 'plant', 'office' => 'chair',
        // the language catalogue, in its five languages
        'audio' => 'headphones', 'média' => 'headphones', 'medien' => 'headphones',
        'tools' => 'drill', 'werkzeug' => 'drill', 'outils' => 'drill', 'herramientas' => 'drill', 'szerszámok' => 'drill',
        'cuisine' => 'kettle', 'konyha' => 'kettle', 'cocina' => 'kettle', 'küche' => 'kettle', 'haushalt' => 'kettle',
        'hogar' => 'kettle', 'maison' => 'kettle', 'háztartás' => 'kettle',
        'computers' => 'monitor', 'computer' => 'monitor', 'számítástechnika' => 'monitor', 'informatique' => 'monitor',
        'informática' => 'monitor', 'electronics' => 'monitor', 'elektronik' => 'monitor', 'électronique' => 'monitor',
        'electrónica' => 'monitor', 'elektronika' => 'monitor',
        'világítás' => 'lamp', 'iluminación' => 'lamp', 'éclairage' => 'lamp', 'beleuchtung' => 'lamp',
        'garten' => 'plant', 'kert' => 'plant', 'jardín' => 'plant', 'jardin' => 'plant',
        'outdoor' => 'tent', 'szabadidő' => 'tent', 'aire libre' => 'tent', 'plein air' => 'tent',
    ];

    /** @return array{icon: string, hue: int} icon is the id of the sprite symbol; hue is -1 for the neutral tile */
    public function of(string $category): array
    {
        $icon = self::NAMES[mb_strtolower(trim($category))] ?? 'generic';

        return ['icon' => 'cat-' . $icon, 'hue' => self::HUES[$icon]];
    }
}
