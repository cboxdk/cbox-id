<?php

declare(strict_types=1);

namespace App\Platform\Actions;

/** A source-owned sentence and its values, kept separate until a console renders it. */
final readonly class ActionMessage
{
    /** @param array<string, string|int|float|self|list<string|self>> $parameters */
    public function __construct(public string $key, public array $parameters = [], private string $listSeparator = ', ', private ?string $sourceEnglish = null) {}

    public function render(?string $locale = null): string
    {
        if ($locale === 'en' && $this->sourceEnglish !== null) {
            return $this->sourceEnglish;
        }

        $parameters = array_map(
            fn (string|int|float|self|array $value): string|int|float => match (true) {
                $value instanceof self => $value->render($locale),
                is_array($value) => implode($this->listSeparator, array_map(static fn (string|self $item): string => $item instanceof self ? $item->render($locale) : $item, $value)),
                default => $value,
            },
            $this->parameters,
        );

        return __($this->key, $parameters, $locale);
    }
}
