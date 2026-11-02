<?php

namespace Laravel\Nova;

// The package's public CI does not include the proprietary Nova dependency.
class Tool {}

class Nova
{
    public static array $scripts = [];

    public static array $styles = [];

    public static function script(string $name, string $path): void
    {
        self::$scripts[] = ['url' => '/nova-api/scripts/'.$name, 'path' => $path];
    }

    public static function style(string $name, string $path): void
    {
        self::$styles[] = ['url' => '/nova-api/styles/'.$name, 'path' => $path];
    }
}
