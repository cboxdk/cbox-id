<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * One choice in a role picker: the value that is posted, the label, and one line on what
 * holding it means.
 *
 * Built HERE for every picker that offers a membership role, so the invite form, the
 * roster's role column and the "add a member" form cannot offer three different lists —
 * which is what they did, three pages apart.
 *
 * `disabled` is how the current OWNER is shown in a roster: the picker names the role the
 * row holds without offering it, because ownership moves by transfer and not by choosing
 * it from a list.
 */
final readonly class RoleOptionProps implements Prop
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description = null,
        public bool $disabled = false,
    ) {}

    /**
     * The roles an organization's own roster offers — its People page, and the environment
     * console's view of the same organization.
     *
     * @return list<self>
     */
    public static function organization(bool $withOwner = false): array
    {
        $options = array_map(
            static fn (MembershipRole $role): self => new self($role->value, $role->label(), OrgRoles::description($role)),
            OrgRoles::assignable(),
        );

        if ($withOwner) {
            array_unshift($options, new self(
                MembershipRole::Owner->value,
                MembershipRole::Owner->label(),
                'Moves only with "Transfer ownership".',
                disabled: true,
            ));
        }

        return $options;
    }

    /**
     * The roles a customer account offers its administrators (Identity platform ›
     * Administrators) — a different question from an organization's roster, so a different
     * list, drawn by the same control.
     *
     * @return list<self>
     */
    public static function account(): array
    {
        return array_map(
            static fn (MembershipRole $role): self => new self($role->value, $role->label(), match ($role) {
                MembershipRole::Admin => 'Runs the account: people, projects, environments and billing.',
                MembershipRole::Developer => 'Creates and runs environments; no access to people or billing.',
                MembershipRole::Member => 'Works in the environments they are given; no administration.',
                MembershipRole::Viewer => 'Read-only: environments, people and billing.',
                MembershipRole::Owner => null,
            }),
            MembershipRole::assignable(),
        );
    }

    /**
     * @return array{value: string, label: string, description: string|null, disabled: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'disabled' => $this->disabled,
        ];
    }
}
