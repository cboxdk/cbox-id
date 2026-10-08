<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use Cbox\LaravelSiem\Enums\DatadogSite;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Enums\S3Encryption;
use Cbox\LaravelSiem\Exceptions\InvalidStreamConfiguration;
use Cbox\LaravelSiem\Models\LogStream;

/**
 * What this platform says about a log stream's DESTINATION beyond what the SIEM package
 * stores — shared by the actions, the console and the Admin Portal, so the three describe
 * one stream the same way.
 *
 * THE CLOUD DESTINATIONS (Datadog, Amazon S3, Google Cloud Storage) take typed `options`
 * instead of a collector URL and a token. The package validates them
 * ({@see InvalidStreamConfiguration}, naming the field); this says which option belongs to
 * which destination, how a refusal reads at the door, and — for an S3 bucket — the two AWS
 * policies somebody has to paste before a single object lands: the write-only permissions
 * policy, and for an assumed role the trust policy that names the platform's AWS principal
 * and requires the stream's own external ID.
 *
 * Nothing here is a secret. The external ID is generated per stream for confused-deputy
 * protection and is MEANT to be shown: the customer puts it in their role's trust policy.
 */
final class LogStreamDestinations
{
    /**
     * Destination value => the option keys it accepts, in the order a form asks for them.
     * An HTTP collector takes none. TOTAL over the cloud destinations.
     *
     * @var array<string, list<string>>
     */
    public const array OPTIONS = [
        'datadog' => ['site', 'service', 'source', 'tags', 'hostname'],
        's3' => ['bucket', 'region', 'prefix', 'access_key_id', 'role_arn', 'sse', 'kms_key_id', 'path_style', 'gzip'],
        'gcs' => ['bucket', 'prefix', 'gzip'],
    ];

    /** Input fields a refusal names as they are, rather than as an option. */
    private const array TOP_LEVEL = ['secret', 'endpoint_url', 'destination', 'options'];

    /**
     * The `options` input every log stream action that configures a destination declares —
     * each key typed and bounded at the door, so an API reference and an MCP client are told
     * the exact shape. Which key applies to which destination is the package's to enforce:
     * a key a destination does not take is refused there, on its own field.
     *
     * Nullable members, because `log_streams.update` merges `options` over the stored ones
     * and a null is how a caller REMOVES a key (an access key ID, when switching the S3
     * stream to an assumed role). On create, a null is simply not given.
     */
    public static function optionsField(): Field
    {
        return Field::object('options', [
            Field::string('site')->nullable()->oneOf(array_map(static fn (DatadogSite $site): string => $site->value, DatadogSite::cases()))
                ->describe('Datadog: the site your account lives on — the domain you sign in at, e.g. `datadoghq.eu` for EU1. Default `datadoghq.com` (US1). An API key only works on its own site.'),
            Field::string('service')->nullable()->max(100)->describe('Datadog: the `service` attribute on every entry. Default: this platform\'s name.'),
            Field::string('source')->nullable()->max(100)->describe('Datadog: the `ddsource` attribute. Default `cbox`.'),
            Field::list('tags', Field::string('tag')->max(200))->nullable()->max(100)->describe('Datadog: `key:value` tags (`ddtags`) on every entry, e.g. `env:prod`.'),
            Field::string('hostname')->nullable()->max(255)->describe('Datadog: the `hostname` attribute. Default: this platform\'s host.'),
            Field::string('bucket')->nullable()->max(222)->describe('S3 and GCS: the bucket objects are written to.'),
            Field::string('region')->nullable()->max(32)->describe('S3: the bucket\'s AWS Region, e.g. `eu-west-1` (`auto` for Cloudflare R2).'),
            Field::string('prefix')->nullable()->max(512)->describe('S3 and GCS: the object key prefix, e.g. `cbox/audit`. Objects are written to `{prefix}/{yyyy}/{mm}/{dd}/{hh}/{batch}.ndjson.gz`.'),
            Field::string('access_key_id')->nullable()->max(128)->describe('S3 with an access key: the IAM access key ID. Its secret access key is the stream\'s `secret`.'),
            Field::string('role_arn')->nullable()->max(2048)->describe('S3 with an assumed role: the IAM role the platform assumes. No secret is stored; the role\'s trust policy must require the stream\'s `external_id`.'),
            Field::string('sse')->nullable()->oneOf(array_map(static fn (S3Encryption $mode): string => $mode->value, S3Encryption::cases()))
                ->describe('S3: server-side encryption requested on every object. Left out, the bucket\'s default.'),
            Field::string('kms_key_id')->nullable()->max(2048)->describe('S3 with `aws:kms`: the KMS key ID, alias or ARN.'),
            Field::boolean('path_style')->nullable()->describe('S3: path-style addressing. Default: virtual-hosted on AWS, path-style on a custom endpoint (MinIO, R2).'),
            Field::boolean('gzip')->nullable()->describe('S3 and GCS: gzip each object (`.ndjson.gz`). Default true.'),
        ])->nullable()->describe('Datadog, S3 and GCS only: the destination\'s settings. An HTTP collector takes none.');
    }

    /**
     * Datadog site => how Datadog names it: the region, then the domain you sign in at. A
     * proper name in every language, so the console and the hosted portal say it alike.
     */
    public const array SITE_LABELS = [
        'datadoghq.com' => 'US1 — datadoghq.com',
        'us3.datadoghq.com' => 'US3 — us3.datadoghq.com',
        'us5.datadoghq.com' => 'US5 — us5.datadoghq.com',
        'datadoghq.eu' => 'EU1 — datadoghq.eu',
        'ap1.datadoghq.com' => 'AP1 — ap1.datadoghq.com',
        'ap2.datadoghq.com' => 'AP2 — ap2.datadoghq.com',
        'ddog-gov.com' => 'US1-FED — ddog-gov.com',
    ];

    /**
     * A form's `options` as the action takes them: every key the destination takes and no
     * other, an empty one as null (so on an edit it REMOVES the stored value — the action
     * merges), the tags field's comma-separated line as a list, and a checkbox as a boolean.
     * Shared by the console and the Admin Portal, whose forms send the same strings.
     *
     * @return array<string, mixed>
     */
    public static function fromForm(Destination $destination, mixed $sent): array
    {
        $sent = is_array($sent) ? $sent : [];
        $options = [];

        foreach (self::OPTIONS[$destination->value] ?? [] as $key) {
            $value = $sent[$key] ?? null;

            $options[$key] = match (true) {
                $key === 'tags' => self::tags($value),
                // An unticked "force path-style" box means the default — virtual-hosted on
                // AWS, path-style on a custom endpoint — not "never path-style".
                $key === 'path_style' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? true : null,
                is_bool($value) => $value,
                $key === 'gzip' => $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_scalar($value) && trim((string) $value) !== '' => trim((string) $value),
                default => null,
            };
        }

        return $options;
    }

    /**
     * The package's refusal, said at the door: the stable code `invalid_stream_configuration`
     * and the field as the caller sent it — `options.bucket`, not `bucket` — so a form puts
     * the message beside the input it is about. The package's messages never carry a secret.
     */
    public static function refusal(InvalidStreamConfiguration $invalid): ActionRefused
    {
        $field = in_array($invalid->field, self::TOP_LEVEL, true) ? $invalid->field : 'options.'.$invalid->field;

        return ActionRefused::because('invalid_stream_configuration', $invalid->getMessage(), $field);
    }

    /**
     * Whether this platform can assume a customer's IAM role at all: it needs its OWN AWS
     * identity — `SIEM_AWS_ACCESS_KEY_ID` / `SIEM_AWS_SECRET_ACCESS_KEY` (laravel-siem's
     * `siem.aws.*`), a user allowed nothing but `sts:AssumeRole` — to sign the STS call.
     */
    public static function assumedRoleAvailable(): bool
    {
        return self::filled(config('siem.aws.access_key_id')) && self::filled(config('siem.aws.secret_access_key'));
    }

    /**
     * The options a caller actually gave: string keys, nulls dropped — a null on create is
     * simply not given.
     *
     * @param  array<mixed>  $options
     * @return array<string, mixed>
     */
    public static function given(array $options): array
    {
        $given = [];

        foreach ($options as $key => $value) {
            if (is_string($key) && $value !== null) {
                $given[$key] = $value;
            }
        }

        return $given;
    }

    /**
     * Refuse a role this platform could never assume, before it is stored as a stream that
     * fails every delivery with nobody able to fix it from the form.
     *
     * @param  array<array-key, mixed>  $options
     *
     * @throws ActionRefused
     */
    public static function assertRoleAssumable(Destination $destination, array $options): void
    {
        if ($destination === Destination::S3 && self::filled($options['role_arn'] ?? null) && ! self::assumedRoleAvailable()) {
            throw ActionRefused::because('assumed_role_unavailable', 'Assuming an IAM role is not set up on this platform. Use an access key instead.', 'options.role_arn');
        }
    }

    /** True for an S3 stream that assumes a role rather than holding a key. */
    public static function assumesRole(LogStream $stream): bool
    {
        return $stream->destination === Destination::S3 && self::filled($stream->destinationOptions()['role_arn'] ?? null);
    }

    /** The external ID an assumed-role S3 stream's trust policy must require, or null. */
    public static function externalId(LogStream $stream): ?string
    {
        $externalId = $stream->destinationOptions()['external_id'] ?? null;

        return self::assumesRole($stream) && is_string($externalId) ? $externalId : null;
    }

    /**
     * The platform's AWS principal — the IAM user `SIEM_AWS_ACCESS_KEY_ID` belongs to — as
     * the trust policy names it. The access key ID alone does not say which user it is, so
     * the operator states it (`SIEM_AWS_PRINCIPAL_ARN`); unset, the policy carries a
     * placeholder rather than a guess.
     */
    public static function awsPrincipal(): ?string
    {
        $arn = config('cbox-id.log_streams.aws_principal_arn');

        return is_string($arn) && trim($arn) !== '' ? trim($arn) : null;
    }

    /**
     * The trust policy the customer's role needs: the platform's principal may assume it,
     * and only when it presents this stream's external ID. Null for any other stream.
     */
    public static function trustPolicy(LogStream $stream): ?string
    {
        $externalId = self::externalId($stream);

        if ($externalId === null) {
            return null;
        }

        return self::json([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => self::awsPrincipal() ?? 'arn:aws:iam::<platform-account-id>:user/<platform-user>'],
                'Action' => 'sts:AssumeRole',
                'Condition' => ['StringEquals' => ['sts:ExternalId' => $externalId]],
            ]],
        ]);
    }

    /**
     * The least-privilege permissions policy for an S3 stream — attached to the role it
     * assumes, or to the IAM user whose key it holds: `s3:PutObject` under the prefix and
     * nothing else. Nothing to read, list or delete: every batch is a fresh object, so a
     * retry never overwrites one. With SSE-KMS, the one KMS call the write needs.
     */
    public static function permissionsPolicy(LogStream $stream): ?string
    {
        if ($stream->destination !== Destination::S3) {
            return null;
        }

        $options = $stream->destinationOptions();
        $partition = self::partition($options);
        $bucket = is_string($options['bucket'] ?? null) ? $options['bucket'] : '<bucket>';
        $prefix = is_string($options['prefix'] ?? null) && $options['prefix'] !== '' ? $options['prefix'].'/' : '';

        $statements = [[
            'Sid' => 'CboxAuditWriteOnly',
            'Effect' => 'Allow',
            'Action' => 's3:PutObject',
            'Resource' => "arn:{$partition}:s3:::{$bucket}/{$prefix}*",
        ]];

        if (($options['sse'] ?? null) === S3Encryption::Kms->value) {
            $key = $options['kms_key_id'] ?? null;

            $statements[] = [
                'Sid' => 'CboxAuditEncrypt',
                'Effect' => 'Allow',
                'Action' => 'kms:GenerateDataKey',
                // An alias or a bare key id is not a resource ARN; the policy needs the key's.
                'Resource' => is_string($key) && str_starts_with($key, 'arn:') ? $key : '<your KMS key ARN>',
            ];
        }

        return self::json(['Version' => '2012-10-17', 'Statement' => $statements]);
    }

    /**
     * The AWS partition the bucket's ARN is written in — `aws`, or the GovCloud and China
     * partitions, read off the role ARN where there is one and the region otherwise.
     *
     * @param  array<string, mixed>  $options
     */
    private static function partition(array $options): string
    {
        $role = $options['role_arn'] ?? null;

        if (is_string($role) && preg_match('/^arn:(aws(?:-[a-z]+)*):/', $role, $match) === 1) {
            return $match[1];
        }

        $region = is_string($options['region'] ?? null) ? $options['region'] : '';

        return match (true) {
            str_starts_with($region, 'us-gov-') => 'aws-us-gov',
            str_starts_with($region, 'cn-') => 'aws-cn',
            default => 'aws',
        };
    }

    /** @return list<string>|null */
    private static function tags(mixed $value): ?array
    {
        $tags = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
        $tags = array_values(array_filter(array_map(static fn (mixed $tag): string => is_string($tag) ? trim($tag) : '', $tags), static fn (string $tag): bool => $tag !== ''));

        return $tags === [] ? null : $tags;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function json(array $document): string
    {
        return (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
