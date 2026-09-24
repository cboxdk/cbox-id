<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Console\KeyLifecycleProps;
use App\Http\Props\Prop;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Models\CustomerApiKey;
use DateTimeInterface;

/**
 * One API key a person holds for an app — on their own key page, and on the pages where an
 * organization's administrators see every key in it.
 *
 * ONE SHAPE FOR ALL THREE PAGES, like {@see KeyLifecycleProps}, so the holder's view and
 * the administrator's cannot disagree about what a key is. `holder` is null on the holder's
 * own page, where it would only ever say "you".
 *
 * `permissions` is what the key was ISSUED with — its ceiling. What it may do at any moment
 * is that list intersected with what its holder holds then, which is the verify endpoint's
 * answer and changes without the key changing.
 *
 * `revokeHref` is null for a key that is no longer active: there is nothing left to stop.
 */
final readonly class AppApiKeyRowProps implements Prop
{
    /**
     * @param  list<string>  $permissions
     * @param  array{name: string, email: string|null}|null  $holder
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public string $prefix,
        public string $appName,
        public array $permissions,
        public ?array $holder,
        public KeyLifecycleProps $lifecycle,
        public ?string $revokeHref,
    ) {}

    public static function from(
        CustomerApiKey $key,
        string $appName,
        ?Subject $holder,
        bool $showHolder,
        string $revokeHref,
        DateTimeInterface $now,
    ): self {
        $lifecycle = KeyLifecycleProps::of(KeyLifecycleProps::createdAt($key), $key->last_used_at, $key->expires_at, $key->revoked_at, $now);

        return new self(
            id: $key->id,
            name: $key->name,
            prefix: $key->prefix,
            appName: $appName,
            permissions: $key->permissions,
            holder: $showHolder ? self::holder($holder, $key->user_id) : null,
            lifecycle: $lifecycle,
            revokeHref: $lifecycle->active() ? $revokeHref : null,
        );
    }

    /**
     * A name a person recognises. A holder whose account is gone is still named, by id,
     * because "somebody" holding a key is exactly the row an administrator must be able to
     * trace.
     *
     * @return array{name: string, email: string|null}
     */
    private static function holder(?Subject $holder, string $userId): array
    {
        if ($holder === null) {
            return ['name' => $userId, 'email' => null];
        }

        $name = $holder->name !== null && trim($holder->name) !== '' ? $holder->name : ($holder->email ?? $userId);

        return ['name' => $name, 'email' => $holder->email];
    }

    /**
     * @return array{id: string, name: string|null, prefix: string, appName: string, permissions: list<string>, holder: array{name: string, email: string|null}|null, lifecycle: array<string, string|null>, revokeHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'appName' => $this->appName,
            'permissions' => $this->permissions,
            'holder' => $this->holder,
            'lifecycle' => $this->lifecycle->toArray(),
            'revokeHref' => $this->revokeHref,
        ];
    }
}
