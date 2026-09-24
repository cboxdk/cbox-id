<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Staff\ValueObjects\StaffRole;

/**
 * A staff role as the console's pickers draw it — the same shape on the Staff page and on
 * a user's page, so the two cannot describe one role differently.
 */
final readonly class StaffRoleProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        /** Null reads "All apps": the role reaches every app's tokens. */
        public ?string $app,
        /** A role no organization's administrators may grant. */
        public bool $staffOnly,
    ) {}

    public static function from(StaffRole $role): self
    {
        return new self($role->id, $role->name, $role->appName, ! $role->tenantAssignable);
    }

    /**
     * @param  list<StaffRole>  $roles
     * @return list<self>
     */
    public static function list(array $roles): array
    {
        return array_map(self::from(...), $roles);
    }

    /**
     * @return array{id: string, name: string, app: string|null, staffOnly: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'app' => $this->app,
            'staffOnly' => $this->staffOnly,
        ];
    }
}
