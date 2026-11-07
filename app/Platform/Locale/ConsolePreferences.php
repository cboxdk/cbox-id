<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\UserProperties\Contracts\UserPreferences;
use Cbox\Id\UserProperties\ValueObjects\PropertyPatch;
use Cbox\Id\UserProperties\ValueObjects\PropertySnapshot;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Preferences belong to the authenticated person, never the tenant they administer. */
final readonly class ConsolePreferences
{
    public function __construct(
        private CurrentUser $current,
        private EnvironmentAdminAuth $admin,
        private PlatformRoot $root,
        private UserPreferences $preferences,
        private Request $request,
    ) {}

    public function read(): PropertySnapshot
    {
        return $this->inSubjectEnvironment(fn (string $id): PropertySnapshot => $this->preferences->read($id));
    }

    public function update(string $expectedSubject, int $version, HostedLocale $language): PropertySnapshot
    {
        return $this->inSubjectEnvironment(function (string $id) use ($expectedSubject, $version, $language): PropertySnapshot {
            if (! hash_equals($id, $expectedSubject)) {
                throw new ConflictHttpException;
            }

            return $this->preferences->patch($id, new PropertyPatch($version, (object) ['language' => $language->value]));
        });
    }

    /** @param callable(string): PropertySnapshot $callback */
    private function inSubjectEnvironment(callable $callback): PropertySnapshot
    {
        $environmentConsole = $this->request->is('admin', 'admin/*') && ! $this->request->routeIs('platform.*');
        $administrator = $environmentConsole ? $this->admin->subjectId() : null;
        $id = $administrator ?? ($this->current->check() ? $this->current->id() : '');
        abort_if($id === '', 401);

        $snapshot = $administrator !== null || $this->request->routeIs('platform.*')
            ? $this->root->run(fn (): PropertySnapshot => $callback($id))
            : $callback($id);
        abort_if($snapshot === null, 503);

        return $snapshot;
    }
}
