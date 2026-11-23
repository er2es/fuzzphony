<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Twig\Components\Playground;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * The Live Component writes every URL-mapped prop into the address; the address of the page should hold only what differs from the
 * defaults (a link to "/tune" is the default page), so the values equal to the prop's default are taken out again here, after the
 * bundle has written them (X-Live-Url). `start` (the wizard's link) is spent once its values are in the controls.
 */
final class LiveUrlDefaults
{
    /** @var array<string, string>|null address name => the default value as it is written in an address */
    private ?array $defaults = null;

    #[AsEventListener(priority: -10)]
    public function __invoke(ResponseEvent $event): void
    {
        $header = $event->getResponse()->headers->get('X-Live-Url');
        if ($header === null || !$event->getRequest()->attributes->has('_live_component')) {
            return;
        }
        [$path, $query] = array_pad(explode('?', $header, 2), 2, '');
        parse_str($query, $params);
        if (!isset($params['q']) && !isset($params['text']) && !isset($params['fuzzy'])) {
            return; // not the Tune page
        }
        unset($params['start']);
        foreach ($this->defaults() as $name => $default) {
            if (isset($params[$name]) && is_scalar($params[$name]) && self::equal((string) $params[$name], $default)) {
                unset($params[$name]);
            }
        }
        $event->getResponse()->headers->set('X-Live-Url', $path . ($params === [] ? '' : '?' . http_build_query($params)));
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }
        $defaults = [];
        foreach ((new ReflectionClass(Playground::class))->getProperties() as $property) {
            $live = $property->getAttributes(LiveProp::class)[0] ?? null;
            if ($live === null || $property->getDefaultValue() === null) {
                continue;
            }
            $mapping = $live->newInstance()->url();
            if ($mapping === false) {
                continue;
            }
            $default = $property->getDefaultValue();
            $defaults[$mapping->as ?? $property->getName()] = is_bool($default) ? ($default ? '1' : '0') : (string) $default;
        }

        return $this->defaults = $defaults;
    }

    private static function equal(string $a, string $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 1e-9 : $a === $b;
    }
}
