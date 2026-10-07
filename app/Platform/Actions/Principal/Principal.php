<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who is running an action, whichever door they came through.
 *
 * The console, a management key, a delegated token and (next) an operator are different
 * credentials with different rules, and an action must not care which: it asks the
 * principal whether it may run, and records what it did as the principal's act.
 */
interface Principal
{
    /** A short, stable kind for the trail and for idempotency: `console`, `environment_key`. */
    public function kind(): string;

    /** A stable identifier within the kind — the key id, the acting person's id. */
    public function id(): string;

    /** Who the audit trail names for this principal's acts. */
    public function auditActor(): AuditActor;

    /**
     * Refuse unless this principal may run the action at all. Target-level checks (this
     * API, that organization) are the action's own.
     *
     * @throws AuthorizationException
     */
    public function authorize(ActionDefinition $action): void;

    /**
     * Whether retries from this principal are made safe by an `Idempotency-Key`. A console
     * session has its own guard against a double submit (the redirect after POST), so only
     * machine principals take part.
     */
    public function supportsIdempotency(): bool;

    /** A short human name for prompts and approvals: the key's name, the person's. */
    public function label(): string;

    /** Which of this principal's actions need a person's approval first; null for none. */
    public function stepUpPolicy(): ?StepUpPolicy;

    /**
     * The person who approves this principal's held actions — a subject of the environment
     * {@see approverEnvironmentId()} names, where their devices are enrolled — or null when
     * there is nobody to ask.
     */
    public function approverSubjectId(): ?string;

    /**
     * Where the approver is a subject, and so where an approval is filed and answered: null
     * for the platform root — the people behind keys and consoles live there — or the id of
     * the environment a signed-in person belongs to.
     */
    public function approverEnvironmentId(): ?string;

    /**
     * The organization this principal acts within, or null when it acts with the
     * environment's own authority, above every organization in it.
     *
     * The ONE question every action that reaches into organizations asks
     * ({@see OrganizationTarget}, {@see IntegrationReach}, the app actions' lookup), so a new
     * kind of principal is confined by saying so here rather than by being remembered at
     * each of them. A principal that should be confined and cannot say to what throws: null
     * is a grant of the whole environment, never a "don't know".
     *
     * @throws AuthorizationException
     */
    public function confinedToOrganization(): ?string;
}
