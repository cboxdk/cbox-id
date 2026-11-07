<?php

declare(strict_types=1);

namespace App\Platform\Invitations\Exceptions;

use App\Platform\Actions\ActionMessage;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\OrgRoles;
use Cbox\Id\Organization\Enums\MembershipRole;
use RuntimeException;

/**
 * An invitation the service would not send, re-send or withdraw — with the reason, and the
 * sentence a person should read.
 *
 * The sentences live HERE rather than at each caller, because there are several callers and
 * one set of rules: a refusal that reads differently on the People page and on the
 * environment console is how people learn the two are different products.
 */
final class InvitationRefused extends RuntimeException
{
    private function __construct(public readonly InvitationRefusalReason $reason, public readonly string|ActionMessage $sourceMessage)
    {
        parent::__construct($sourceMessage instanceof ActionMessage ? $sourceMessage->render('en') : $sourceMessage);
    }

    public function field(): string
    {
        return $this->reason->field();
    }

    public static function alreadyMember(): self
    {
        return new self(InvitationRefusalReason::AlreadyMember, new ActionMessage('console.that_person_is_already_a_member_of_this_organization_78646280'));
    }

    /**
     * The workspace team's own sentence. "Already a member of this organization" is the
     * tenant roster's; on Workspace › Team the person is on a list of administrators.
     */
    public static function alreadyOnTeam(): self
    {
        return new self(InvitationRefusalReason::AlreadyMember, new ActionMessage('console.that_person_is_already_on_this_list_e4bbac22'));
    }

    /**
     * A workspace team offers its own four roles, not an organization's two.
     */
    public static function teamRoleNotOffered(): self
    {
        return new self(
            InvitationRefusalReason::RoleNotOffered,
            new ActionMessage('console.choose_one_of_roles_ownership_is_handed_over_with_trans_90a222bb', ['roles' => self::roleMessages(MembershipRole::assignable())]),
        );
    }

    public static function roleNotOffered(): self
    {
        return new self(
            InvitationRefusalReason::RoleNotOffered,
            new ActionMessage('console.choose_one_of_roles_ownership_is_handed_over_with_trans_90a222bb', ['roles' => self::roleMessages(OrgRoles::assignable())]),
        );
    }

    /**
     * An access role this organization does not offer — staff-only, another organization's,
     * or no role at all. Refused rather than dropped: an invitation sent without the role
     * the administrator ticked is one they believe carries it.
     */
    public static function accessRoleNotOffered(): self
    {
        return new self(InvitationRefusalReason::AccessRoleNotOffered, new ActionMessage('console.that_access_role_is_not_offered_in_this_organization_ch_f6d5199a'));
    }

    public static function accessRoleConflict(string $message): self
    {
        return new self(InvitationRefusalReason::AccessRoleConflict, $message);
    }

    public static function returnWithoutApp(): self
    {
        return new self(
            InvitationRefusalReason::ReturnWithoutApp,
            new ActionMessage('console.a_return_address_needs_the_app_it_belongs_to_send_the_a_f30025ab'),
        );
    }

    public static function unknownApp(): self
    {
        return new self(
            InvitationRefusalReason::UnknownApp,
            new ActionMessage('console.no_app_with_that_client_id_is_available_to_this_organiz_a80ad89d'),
        );
    }

    public static function returnToMalformed(): self
    {
        return new self(
            InvitationRefusalReason::ReturnToMalformed,
            new ActionMessage('console.the_return_address_must_be_a_full_https_url_http_only_f_b853f42d'),
        );
    }

    public static function returnToNotRegistered(string $app): self
    {
        return new self(
            InvitationRefusalReason::ReturnToNotRegistered,
            new ActionMessage('console.the_return_address_must_be_on_one_of_app_s_registered_r_20ea9b1f', ['app' => $app]),
        );
    }

    public static function notPending(): self
    {
        return new self(
            InvitationRefusalReason::NotPending,
            new ActionMessage('console.that_invitation_has_already_been_accepted_withdrawn_or_4c902e98'),
        );
    }

    public static function tooSoon(int $seconds): self
    {
        return new self(
            InvitationRefusalReason::TooSoon,
            new ActionMessage('console.already_sent_try_again_in_seconds_seconds_and_check_the_e294712c', ['seconds' => $seconds]),
        );
    }

    public static function mailFailed(bool $invitationKept): self
    {
        return new self(
            InvitationRefusalReason::MailFailed,
            $invitationKept
                ? new ActionMessage('console.that_invitation_could_not_be_sent_the_mail_server_refus_59435126')
                : new ActionMessage('console.we_could_not_send_that_invitation_the_mail_server_refus_81a3f4df'),
        );
    }

    /** @param list<MembershipRole> $roles
     * @return list<ActionMessage>
     */
    private static function roleMessages(array $roles): array
    {
        return array_map(static fn (MembershipRole $role): ActionMessage => new ActionMessage(match ($role) {
            MembershipRole::Owner => 'console.owner_4b1b8aa3',
            MembershipRole::Admin => 'console.admin_c1c224b0',
            MembershipRole::Developer => 'console.developer_3fb7b394',
            MembershipRole::Member => 'console.member_7c968fb7',
            MembershipRole::Viewer => 'console.viewer_678bfa6a',
        }), $roles);
    }
}
