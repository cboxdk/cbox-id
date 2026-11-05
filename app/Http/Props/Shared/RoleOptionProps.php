<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Locale\ConsoleStrings;
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
                ConsoleStrings::message('console.moves_only_with_transfer_ownership_4d9a7054'),
                disabled: true,
            ));
        }

        return $options;
    }

    /**
     * The built-in roles a workspace offers its team (Workspace › Team) — a different question from an organization's roster, so a different
     * list, drawn by the same control.
     *
     * @return list<self>
     */
    public static function account(): array
    {
        return array_map(
            static fn (MembershipRole $role): self => new self($role->value, $role->label(), match ($role) {
                MembershipRole::Admin => ConsoleStrings::message('console.runs_the_workspace_team_projects_environment_6c637b7b'),
                MembershipRole::Developer => ConsoleStrings::message('console.creates_and_runs_environments_no_access_to_p_5b16a314'),
                MembershipRole::Member => ConsoleStrings::message('console.works_in_the_environments_they_are_given_no__48df15b0'),
                MembershipRole::Viewer => ConsoleStrings::message('console.read_only_environments_people_and_billing_1c31169a'),
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
            'label' => ConsoleStrings::text($this->label),
            'description' => $this->description,
            'disabled' => $this->disabled,
        ];
    }
}
