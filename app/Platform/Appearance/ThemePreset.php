<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/** A curated starting theme for the Theme Editor — a complete, typed look. */
final readonly class ThemePreset
{
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public ThemeRadius $radius,
        public ThemeFont $font,
        public ThemeMode $light,
        public ThemeMode $dark,
    ) {}

    /** Seed an {@see Appearance} from this preset. */
    public function toAppearance(): Appearance
    {
        return new Appearance($this->id, $this->radius, $this->font, $this->light, $this->dark);
    }

    /**
     * Editor payload (a serialization boundary) — the client only needs colours,
     * label, radius and font to render swatches + previews.
     *
     * @return array{label: string, radius: string, font: string, light: array{primary: string, background: string, foreground: string, muted: string}, dark: array{primary: string, background: string, foreground: string, muted: string}}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'radius' => $this->radius->value,
            'font' => $this->font->value,
            'light' => $this->light->toArray(),
            'dark' => $this->dark->toArray(),
        ];
    }
}
