<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Http\Middleware\ResolveLocale;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\UserProperties\Jobs\RelayPropertyChanges;
use Cbox\Id\UserProperties\Models\PropertyDocument;
use Cbox\Id\UserProperties\Models\PropertyNamespace;
use Cbox\Id\UserProperties\PreferenceSchema;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** The authenticated person's own language, in the same document that apps consume. */
final readonly class ConsoleLanguage
{
    public function __construct(
        private CurrentUser $current,
        private EnvironmentAdminAuth $administrator,
        private EnvironmentContext $environments,
        private PlatformRoot $root,
        private Subjects $subjects,
        private EventBus $events,
    ) {}

    /** Called after the existing console authentication gate has admitted the request. */
    public function apply(Request $request): void
    {
        // Authorization/login pages retain the relying party's hosted locale policy.
        if ($request->attributes->has(ResolveLocale::ATTRIBUTE)) {
            return;
        }

        $language = $this->asPerson($request, fn (string $subject): mixed => $this->document($subject)?->data->language ?? null);
        $locale = is_string($language)
            ? (HostedLocale::fromTag($language) ?? HostedLocale::English)
            : $this->browserLanguage($request);

        // An old browser cookie must not carry the previous account's choice into this one.
        app()->setLocale($locale->value);
    }

    public function save(Request $request, HostedLocale $locale): void
    {
        $this->asPerson($request, function (string $subject) use ($request, $locale): void {
            abort_unless($request->input('user_id') === $subject, 409, 'The signed-in account changed. Refresh the page and try again.');

            DB::transaction(function () use ($subject, $locale): void {
                // Share the package's namespace lock, including first-document creation.
                // Creating an empty namespace grants no app access; existing grants stay intact.
                PropertyNamespace::query()->firstOrCreate(['name' => 'preferences'], [
                    'readers' => [], 'writers' => [], 'webhooks' => [],
                ]);
                PropertyNamespace::query()->where('name', 'preferences')->lockForUpdate()->firstOrFail();
                abort_unless($this->subjects->isActive($subject), 404);

                $record = $this->document($subject);
                $data = clone ($record->data ?? new stdClass);
                if (($data->language ?? null) === $locale->value) {
                    return;
                }

                $data->language = $locale->value;
                PreferenceSchema::validate($data);
                $record ??= new PropertyDocument;
                $record->fill([
                    'user_id' => $subject, 'namespace' => 'preferences',
                    'data' => $data, 'version' => ($record->version ?? 0) + 1,
                ])->save();

                // Cbox ID is the document owner, not an OAuth consumer. Use the canonical
                // transactional outbox and relay, with no fabricated client or widened grants.
                $this->events->emit(new DomainEvent('user.preferences.updated', [
                    'event_id' => (string) Str::ulid(), 'user_id' => $subject,
                    'namespace' => 'preferences', 'changed_keys' => ['language'],
                    'version' => $record->version, 'client_id' => null,
                    'occurred_at' => now()->toIso8601String(),
                ]));
                $pending = RelayPropertyChanges::dispatch()->afterCommit();
                foreach (['queue_connection' => 'onConnection', 'queue' => 'onQueue'] as $key => $method) {
                    $value = config('cbox-id.webhooks.'.$key);
                    if (is_string($value) && $value !== '') {
                        $pending->{$method}($value);
                    }
                }
            });
        });
    }

    private function document(string $subject): ?PropertyDocument
    {
        return PropertyDocument::query()->where('user_id', $subject)->where('namespace', 'preferences')->first();
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    private function asPerson(Request $request, Closure $callback): mixed
    {
        // Platform administration also lives below /admin, but authenticates the
        // ordinary root subject before selecting the tenant whose data it reads.
        if (in_array('env.admin', $request->route()?->gatherMiddleware() ?? [], true)) {
            $subject = $this->administrator->subjectId();
            $environment = $this->root->environment();
        } else {
            $subject = $this->current->id();
            $environment = $this->environments->current();
        }
        abort_unless(is_string($subject) && $subject !== '' && $environment !== null, 401);

        return $this->environments->runAs($environment, fn () => $callback($subject));
    }

    private function browserLanguage(Request $request): HostedLocale
    {
        foreach ($request->getLanguages() as $candidate) {
            if (($locale = HostedLocale::fromTag($candidate)) !== null) {
                return $locale;
            }
        }

        return HostedLocale::English;
    }
}
