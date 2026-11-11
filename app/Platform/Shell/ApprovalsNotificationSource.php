<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Platform\Agents\ActionApprovalEntry;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Console\Kit\Contracts\NotificationSource;
use Cbox\Console\Kit\Shell\NotificationItem;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Http\Request;

/**
 * THE BELL: action approvals waiting for this person in the environment the page is in —
 * an agent asked to do something only they may allow. Unread is what is still waiting; it
 * goes when they decide or it expires, so "mark read" has nothing to keep.
 *
 * Local: the approval tables, read the way the Approvals page reads them.
 */
final readonly class ApprovalsNotificationSource implements NotificationSource
{
    public function unread(Request $request): int
    {
        [$subject, $environment] = $this->who($request);

        return $subject === null || $environment === null ? 0 : app(ActionApprovalInbox::class)->waitingFor($subject, $environment);
    }

    public function latest(Request $request, int $limit = 20): array
    {
        [$subject, $environment] = $this->who($request);

        if ($subject === null || $environment === null) {
            return [];
        }

        $url = $this->url($request);
        $items = [];

        foreach (app(ActionApprovalInbox::class)->latest($environment) as $entry) {
            if (! $entry->pending() || $entry->approverId !== $subject) {
                continue;
            }

            $items[] = self::item($entry, $url);

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function markRead(Request $request, ?string $id = null): void
    {
        // An approval is read by deciding it.
    }

    /** Where the bell leads: this environment's Approvals. */
    public function url(Request $request): ?string
    {
        $path = ShellRequest::environmentPath($request);

        return $path === null ? null : route('environment.approvals', [EnvironmentConsolePath::PARAMETER => $path], false);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function who(Request $request): array
    {
        if (ShellRequest::environmentPath($request) === null) {
            return [null, null];
        }

        return [ShellRequest::subjectId(), app(EnvironmentContext::class)->current()?->environmentKey()];
    }

    private static function item(ActionApprovalEntry $entry, ?string $url): NotificationItem
    {
        return new NotificationItem(
            id: $entry->request->id,
            title: __('console.approvals_2bfc3471'),
            createdAt: $entry->request->created_at ?? now(),
            read: false,
            body: $entry->request->action,
            url: $url,
        );
    }
}
