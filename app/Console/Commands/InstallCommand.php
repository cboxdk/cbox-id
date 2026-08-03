<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Enums\DeploymentShape;
use App\Platform\Install\EnvFile;
use App\Platform\Install\InstalledPlatform;
use App\Platform\Install\InstallPlan;
use App\Platform\Install\OperatorIdentity;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * `cbox-id:install` — an empty database to a deployment someone can sign in to.
 *
 * REPLACES the framework package's command of the same name (the application's own
 * command wins registration). The package could take a deployment as far as keys,
 * migrations and a bare environment, which is where the story stopped: there was no
 * supported way to get a platform operator, an account or a usable environment, so the
 * product could not actually be installed by anyone who was not us. This does all of it
 * and then checks its own work.
 *
 * A THIN ADAPTER. Everything that touches the database is
 * {@see PlatformInstaller}, which the first-run screen calls too — the two doors into
 * provisioning must not be able to provision different things.
 *
 * NO `--force`. Idempotence here would be the bug: the second run's job would be to
 * mint a second platform root and hand out a credential on a live deployment, and no
 * flag makes that safe enough to ship beside a refusal that is one word long to bypass.
 * A deployment that genuinely needs re-installing is a deployment whose database is
 * being wiped, and that decision belongs to the operator, in their own tooling.
 */
class InstallCommand extends Command
{
    protected $signature = 'cbox-id:install
        {--email= : Email address of the first platform operator}
        {--name= : Display name of the first platform operator}
        {--password= : Their password. Omitted, a strong one is generated and shown once}
        {--multi-tenant : Install the SaaS shape (an account plane that provisions IdPs)}
        {--account-host= : Host the account console lives on (required with --multi-tenant)}
        {--environment=Production : Name of the first environment}
        {--account= : Name of the first account (multi-tenant only)}
        {--issuer= : Public HTTPS URL of this platform (the token issuer). Defaults to APP_URL}';

    protected $description = 'Install this Cbox ID deployment — operator, environment, and the first account';

    public function handle(): int
    {
        intro('Cbox ID — installing this deployment.');

        // Before anything resolves the crypto stack: {@see SecretBox} cannot boot without
        // a master key, and the installer depends on it transitively. This is also why
        // nothing here is constructor-injected — Artisan builds every registered command
        // on boot, and a command whose constructor needs the crypto layer would break
        // every OTHER command on a deployment that has no key yet.
        $this->ensureCryptoKey();

        if (! $this->ensureMigrations()) {
            return self::FAILURE;
        }

        $installer = $this->laravel->make(PlatformInstaller::class);
        $occupancy = $installer->occupancy();

        if (! $occupancy->isEmpty()) {
            $this->components->error('This deployment is already installed — '.$occupancy->describe().'.');
            note(
                "Nothing was changed. Installing again would mint a second platform root and hand out a\n".
                "credential on a live deployment, so there is deliberately no flag to force it.\n".
                '  • To add another operator, sign in and use the operator console.'."\n".
                '  • To start over, wipe the database yourself (`php artisan db:wipe`) and run this again.',
                'Refused',
            );

            return self::FAILURE;
        }

        $plan = $this->plan();

        if ($plan === null) {
            return self::FAILURE;
        }

        $this->recordShape($plan);
        $this->recordIssuer($this->input->isInteractive());

        try {
            $installed = $installer->install($plan);
        } catch (Throwable $e) {
            $this->components->error('Install failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($installed, $plan);

        return $this->verify();
    }

    /**
     * Build the plan from options, asking for whatever is missing — and refusing to
     * invent the answers when there is nobody to ask.
     */
    private function plan(): ?InstallPlan
    {
        $interactive = $this->input->isInteractive();
        $email = $this->stringOption('email');
        $name = $this->stringOption('name');

        if ($email === null) {
            if (! $interactive) {
                $this->components->error('--email is required when running non-interactively. Nothing was changed.');

                return null;
            }

            $email = text('Email address of the first platform operator', required: true);
        }

        if ($name === null) {
            $name = $interactive
                ? text('Their name', default: 'Operator', required: true)
                : 'Operator';
        }

        $shape = $this->shape($interactive);
        $accountHost = $this->accountHost($shape, $interactive);

        if ($shape->isMultiTenant() && $accountHost === null) {
            $this->components->error(
                'The multi-tenant shape needs --account-host: the account console has to live somewhere, '
                .'and a deployment that claims multi-tenancy without one serves it on no host at all. '
                .'Nothing was changed.',
            );

            return null;
        }

        $supplied = $this->stringOption('password');
        $generated = $supplied === null;

        if ($generated && $interactive) {
            // Blank is a real answer here, and the hint says so — an operator who wants a
            // generated credential should not have to invent a throwaway one first.
            $typed = password('Their password (leave blank to generate one)', hint: 'At least 12 characters.');
            $supplied = trim($typed) === '' ? null : $typed;
            $generated = $supplied === null;
        }

        $secret = $supplied ?? Str::password(28);

        if (mb_strlen($secret) < 12) {
            $this->components->error('That password is shorter than 12 characters. Nothing was changed.');

            return null;
        }

        return new InstallPlan(
            shape: $shape,
            operator: new OperatorIdentity($email, $name, $secret, $generated),
            environmentName: $this->stringOption('environment') ?? 'Production',
            accountName: $this->stringOption('account') ?? $name,
            accountHost: $accountHost,
        );
    }

    /**
     * Single-tenant unless the deployment says otherwise — the shape a fresh install
     * has, and the one whose failure mode is "a surface is missing" rather than "the
     * host bulkheads are off".
     */
    private function shape(bool $interactive): DeploymentShape
    {
        if ($this->option('multi-tenant') === true) {
            return DeploymentShape::MultiTenant;
        }

        // An explicit option beats the ambient config; an existing statement beats a
        // prompt, so re-running against a configured box cannot quietly change shape.
        if (config('cbox-id.tenancy.multi_tenant') === true) {
            return DeploymentShape::MultiTenant;
        }

        if (! $interactive) {
            return DeploymentShape::SingleTenant;
        }

        $choice = select(
            label: 'What shape is this deployment?',
            options: [
                DeploymentShape::SingleTenant->value => DeploymentShape::SingleTenant->label(),
                DeploymentShape::MultiTenant->value => DeploymentShape::MultiTenant->label(),
            ],
            default: DeploymentShape::SingleTenant->value,
            hint: 'Single-tenant is the self-hosted shape: one host, one IdP, no account plane.',
        );

        return DeploymentShape::from($choice);
    }

    private function accountHost(DeploymentShape $shape, bool $interactive): ?string
    {
        if (! $shape->isMultiTenant()) {
            return null;
        }

        $stated = $this->stringOption('account-host');

        if ($stated !== null) {
            return mb_strtolower($stated);
        }

        $configured = config('cbox-id.tenancy.account_host');

        if (is_string($configured) && trim($configured) !== '') {
            return mb_strtolower(trim($configured));
        }

        if (! $interactive) {
            return null;
        }

        $host = text(
            label: 'Which host does the account console live on?',
            placeholder: 'cboxid.com',
            hint: 'The platform-root host. Tenants get their own hosts; this is where accounts sign in.',
        );

        return trim($host) === '' ? null : mb_strtolower(trim($host));
    }

    /**
     * Write the shape to `.env` — or, when the file cannot be written, print the exact
     * lines. A deployment provisioned in one shape and configured in another is the
     * failure this step exists to prevent, so it never leaves the answer unstated.
     */
    private function recordShape(InstallPlan $plan): void
    {
        $env = $this->laravel->make(EnvFile::class);

        $lines = array_values(array_filter([
            $plan->multiTenantLine(),
            $plan->accountHostLine(),
        ]));

        // The running process reads config, not the file it just wrote — `doctor` and
        // the health checks below both ask PlaneResolver.
        config([
            'cbox-id.tenancy.multi_tenant' => $plan->shape->isMultiTenant(),
            'cbox-id.tenancy.account_host' => $plan->accountHost,
        ]);

        $written = true;

        foreach ($lines as $line) {
            [$key, $value] = explode('=', $line, 2);
            $existing = $env->get($key);

            // An existing DIFFERENT value is left alone and reported. This command owns
            // an empty database, not somebody else's configuration file.
            if ($existing !== null && $existing !== $value) {
                warning("{$key} is already set to '{$existing}' in .env — leaving it. Installing as '{$value}'.");

                $written = false;

                continue;
            }

            $written = $env->set($key, $value) && $written;
        }

        $written
            ? $this->line('  <fg=green>✓</> Recorded the deployment shape in .env.')
            : note("Set these in your environment:\n  ".implode("\n  ", $lines), 'Configuration');
    }

    /**
     * Record the token issuer, and the passkey relying party derived from it.
     *
     * DERIVED, not asked three times. `rp_id` is the issuer's host and `origin` is its
     * scheme+host by definition — a WebAuthn credential registered against anything else
     * simply will not verify — so asking for them separately only creates a way to get
     * them subtly wrong. Nothing already set is overwritten: this command owns an empty
     * database, not a configured deployment's `.env`.
     */
    private function recordIssuer(bool $interactive): void
    {
        $appUrl = is_string($configured = config('app.url')) && $configured !== ''
            ? rtrim($configured, '/')
            : 'http://localhost';

        $issuer = $this->stringOption('issuer')
            ?? (is_string($current = config('cbox-id.issuer')) && $current !== '' ? $current : null)
            ?? ($interactive
                ? text('Public URL of this platform (the token issuer)', default: $appUrl, hint: 'Your real HTTPS URL in production, e.g. https://id.acme.com')
                : $appUrl);

        $issuer = rtrim($issuer, '/');
        $host = parse_url($issuer, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'localhost';
        $scheme = parse_url($issuer, PHP_URL_SCHEME);
        $port = parse_url($issuer, PHP_URL_PORT);
        $origin = (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$host.(is_int($port) ? ':'.$port : '');

        config([
            'cbox-id.issuer' => $issuer,
            'cbox-id.webauthn.rp_id' => $host,
            'cbox-id.webauthn.origin' => $origin,
        ]);

        $env = $this->laravel->make(EnvFile::class);

        foreach ([
            'CBOX_ID_ISSUER' => $issuer,
            'CBOX_ID_WEBAUTHN_RP_ID' => $host,
            'CBOX_ID_WEBAUTHN_ORIGIN' => $origin,
        ] as $key => $value) {
            if ($env->get($key) === null) {
                $env->set($key, $value);
            }
        }
    }

    private function report(InstalledPlatform $installed, InstallPlan $plan): void
    {
        $this->line('  <fg=green>✓</> Platform root environment ['.$installed->root->slug.'].');
        $this->line('  <fg=green>✓</> Platform operator '.$installed->operator->email.'.');

        if ($installed->account !== null && $installed->tenant !== null) {
            $this->line('  <fg=green>✓</> Account ['.$installed->account->name.'] with environment ['.$installed->tenant->slug.'].');
        }

        // Shown ONCE, and only when this command invented it. A password the operator
        // supplied is never echoed — not to the terminal, not to a shell history file,
        // not to whatever is capturing CI output.
        if ($plan->operator->generated) {
            note(
                "Generated password for {$installed->operator->email}:\n\n    ".$plan->operator->password
                ."\n\nThis is the only time it is shown. Store it in a password manager now, and enrol a\n"
                .'passkey or TOTP factor at your first sign-in.',
                'Save this',
            );
        }
    }

    /**
     * Ask the deployment whether it actually works, rather than reporting the steps that
     * were attempted. An install that "succeeded" into an unhealthy platform is exactly
     * the outcome nobody discovers until the first real sign-in.
     */
    private function verify(): int
    {
        $this->line('');
        $doctor = $this->call('cbox-id:doctor');

        if ($doctor !== self::SUCCESS) {
            warning('The deployment is installed, but the health check found problems (above). Fix those before serving traffic.');

            return self::FAILURE;
        }

        outro('Cbox ID is installed.');

        return self::SUCCESS;
    }

    /** Mint the envelope-encryption master key if this deployment has none. */
    private function ensureCryptoKey(): void
    {
        $existing = config('cbox-id.crypto.key');

        if (is_string($existing) && $existing !== '' && base64_decode($existing, true) !== false) {
            return;
        }

        $key = base64_encode(random_bytes(32));
        $env = $this->laravel->make(EnvFile::class);

        config(['cbox-id.crypto.key' => $key]);
        // The SecretBox may already have been resolved with no key; drop it so the next
        // resolution picks up the one just minted.
        $this->laravel->forgetInstance(SecretBox::class);

        $env->set('CBOX_ID_CRYPTO_KEY', $key)
            ? $this->line('  <fg=green>✓</> Generated the crypto master key and wrote it to .env.')
            : note(
                "Could not write .env. Set this before the next boot, or every sealed secret\n"
                ."written during this install becomes unreadable:\n\n    CBOX_ID_CRYPTO_KEY={$key}",
                'Save this',
            );
    }

    /** Run migrations if the schema is not there yet. Returns false if it could not. */
    private function ensureMigrations(): bool
    {
        try {
            if (Schema::hasTable('signing_keys') && Schema::hasTable('environments')) {
                return true;
            }
        } catch (Throwable $e) {
            $this->components->error('Could not reach the database: '.$e->getMessage().'. Check your DB_* settings.');

            return false;
        }

        $this->line('  <fg=gray>Running migrations…</>');

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->components->error('Migrations failed. Nothing was installed.');

            return false;
        }

        return true;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
