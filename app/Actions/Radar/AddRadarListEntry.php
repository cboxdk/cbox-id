<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\RadarLists;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRuleInvalid;
use App\Platform\Radar\RadarTrail;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Put an IP or range, an address, a mail domain or a device on the allow or the deny list.
 *
 * Deny blocks before anything else is asked; allow lets an attempt through without a rule
 * being consulted. The trail records the list and the kind — not the value, which may be an
 * address; the entry itself is the record of it.
 */
#[AsAction(
    name: 'radar.lists.add',
    summary: 'Add an entry to this environment\'s Radar allow or deny list: an IP or CIDR range, an email address, a mail domain (and its subdomains), or a device id from the decisions explorer. Deny blocks before every rule; allow skips every rule.',
    scope: 'radar:write',
    danger: Danger::Write,
    schema: 'RadarListEntry',
    tag: 'Radar',
    rest: ['POST', '/radar/lists'],
    status: 201,
    consoleRoutes: ['environment.radar.lists.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class AddRadarListEntry implements Action
{
    public function __construct(
        private RadarLists $lists,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('list')->required()->oneOf(RadarList::values())->describe('`allow` or `deny`.'),
            Field::string('kind')->required()->oneOf(RadarListKind::values())->describe('`ip` (an address or CIDR range), `email`, `email_domain` or `device`.'),
            Field::string('value')->required()->max(255)->describe('The IP, range, address, domain or device id.'),
            Field::string('note')->nullable()->max(255)->describe('Why it is listed.'),
            Field::string('expires_at')->nullable()->format('date-time')->describe('When the entry stops applying (ISO 8601). Never, when omitted.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $expires = null;

        if ($context->nullableString('expires_at') !== null) {
            try {
                $expires = Carbon::parse($context->string('expires_at'));
            } catch (Throwable) {
                throw ActionRefused::because('invalid_entry', 'expires_at must be an ISO 8601 date and time.', 'expires_at');
            }
        }

        try {
            $entry = $this->lists->add(
                RadarList::from($context->string('list')),
                RadarListKind::from($context->string('kind')),
                $context->string('value'),
                $context->nullableString('note'),
                $expires,
            );
        } catch (RadarRuleInvalid $invalid) {
            throw ActionRefused::because('invalid_entry', $invalid->getMessage(), 'value');
        }

        $this->trail->record(RadarTrail::LIST_ENTRY_ADDED, 'radar_list_entry', $entry->id, $context->actor(), [
            'list' => $entry->list->value,
            'kind' => $entry->kind->value,
            'expires_at' => $entry->expires_at?->toIso8601String(),
        ]);

        return ActionResult::item($entry, RadarPresenter::entry($entry));
    }
}
