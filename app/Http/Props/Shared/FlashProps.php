<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use Illuminate\Contracts\Session\Session;

/**
 * The one-shot message a redirect left behind.
 *
 * Under Volt this was two mechanisms that disagreed. A redirecting action flashed
 * `status` and the layout rendered it; a non-redirecting action dispatched a browser
 * event, because Livewire does not re-render the layout on an action round trip — so a
 * flash written by one of those 63 components displayed nothing, and then surfaced later
 * on an unrelated page, because a flash survives to the next request.
 *
 * Every mutation is a real request now and every one of them redirects, so there is one
 * mechanism: flash it, and the toaster in the React shell shows it exactly once.
 */
final readonly class FlashProps implements Prop
{
    public function __construct(
        public ?string $status,
        public ?string $error,
    ) {}

    public static function from(Session $session): self
    {
        return new self(
            status: self::string($session->get('status')),
            error: self::string($session->get('error')),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{status: string|null, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
