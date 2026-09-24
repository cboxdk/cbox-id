<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * "Copy to another environment", as the app page offers it: where the copy may go, where
 * the form posts, and — when it cannot be done for this app — why not, in one sentence.
 *
 * The reason is drawn rather than the button hidden: an administrator promoting an app
 * who finds no button concludes the feature does not exist.
 */
final readonly class AppCopyProps implements Prop
{
    /**
     * @param  list<CopyTargetProps>  $targets
     * @param  list<string>  $redirectUris
     */
    public function __construct(
        public string $href,
        public array $targets,
        public array $redirectUris,
        public ?string $unavailable,
    ) {}

    /**
     * @return array{href: string, targets: list<array{id: string, name: string, kind: string}>, redirectUris: string, unavailable: string|null}
     */
    public function toArray(): array
    {
        return [
            'href' => $this->href,
            'targets' => array_map(static fn (CopyTargetProps $target): array => $target->toArray(), $this->targets),
            'redirectUris' => implode("\n", $this->redirectUris),
            'unavailable' => $this->unavailable,
        ];
    }
}
