<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Contracts\SetupTokens;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * The setup token, kept on the private local disk and announced in the application log.
 *
 * TWO PLACES, ON PURPOSE, and both of them require access the public internet does not
 * have. A bare-metal operator reads the file; someone running the image reads
 * `docker logs`, which is the only channel a container hands out without an exec shell.
 * Publishing it in neither would make the safe path so awkward that deployments would
 * reach for the unsafe one.
 *
 * The log line is written ONCE, at mint time, so a token does not accumulate copies in
 * every rotated log for the life of the deployment.
 */
final class FileSetupTokens implements SetupTokens
{
    /**
     * Under `storage/app/private`, which the shipped `.gitignore` excludes entirely —
     * so an operator who commits their deployment directory cannot publish the token
     * along with it.
     */
    private const PATH = 'cbox-id-first-run.token';

    public function __construct(
        private readonly Filesystem $disk,
        private readonly LoggerInterface $log,
    ) {}

    public function issue(): string
    {
        $existing = $this->current();

        if ($existing !== null) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));

        // 'private' visibility → 0600 on the local driver: the token is worth exactly as
        // much as read access to it, so no other account on the box gets a look.
        $this->disk->put(self::PATH, $token, 'private');

        // Deliberately at WARNING: this is the one moment an unclaimed platform is
        // reachable, and an operator scanning for it should not have to raise the log
        // level to find out that their deployment is waiting to be claimed.
        $this->log->warning(
            'Cbox ID is not installed yet. Open /first-run and paste this setup token to claim this deployment. '
            .'It is shown once, it is the only thing standing between an empty platform and whoever finds it first, '
            .'and it stops working the moment the platform is claimed.',
            ['setup_token' => $token],
        );

        return $token;
    }

    public function current(): ?string
    {
        if (! $this->disk->exists(self::PATH)) {
            return null;
        }

        $token = trim((string) $this->disk->get(self::PATH));

        return $token === '' ? null : $token;
    }

    public function matches(string $candidate): bool
    {
        $token = $this->current();

        // No token issued means nothing can match — an absent secret is not a wildcard.
        if ($token === null) {
            return false;
        }

        return hash_equals($token, trim($candidate));
    }

    public function forget(): void
    {
        $this->disk->delete(self::PATH);
    }
}
