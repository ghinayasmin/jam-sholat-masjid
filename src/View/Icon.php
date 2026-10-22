<?php

declare(strict_types=1);

namespace App\View;

/** Ikon garis sederhana untuk panel takmir (SVG inline, tanpa file eksternal). */
final class Icon
{
    private const PATHS = [
        'home' => '<path d="M4 11l8-7 8 7v9H4z"/><path d="M10 20v-5h4v5"/>',
        'announce' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 14l3-3 3 3 4-4"/>',
        'cash' => '<path d="M4 7h16v11H4z"/><path d="M4 7l2-3h12l2 3"/><path d="M12 10v5M9.5 12.5h5"/>',
        'camera' => '<path d="M3 8h13v9H3zM16 11l5-3v9l-5-3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/>',
        'bell' => '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
        'in' => '<path d="M12 5v14M5 12l7 7 7-7"/>',
        'out' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'upload' => '<path d="M12 16V5M7.5 9.5L12 5l4.5 4.5"/><path d="M4 15v3a2 2 0 002 2h12a2 2 0 002-2v-3"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
        'text' => '<path d="M5 6h14M5 12h14M5 18h9"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    ];

    public static function svg(string $name, string $class = 'icon'): string
    {
        $paths = self::PATHS[$name] ?? '';
        return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}
