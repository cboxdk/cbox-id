<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Actions\ActionMessage;
use Cbox\LaravelSiem\Enums\DatadogSite;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Exceptions\InvalidStreamConfiguration;

/** Exact source-owned SIEM messages only. Never parse or translate submitted values. */
final class LogStreamRefusalCopy
{
    private const array FIXED = [
        'A Google Cloud Storage stream needs a service-account JSON key.' => 'console.stream_validation_1d51837c243f',
        'The secret must be a service-account JSON key (type "service_account").' => 'console.stream_validation_7caca8d80e0b',
        'The service-account key has no valid client_email.' => 'console.stream_validation_7e690b1f98f4',
        'The service-account key has no usable private_key.' => 'console.stream_validation_51def6b68916',
        'Give either an access key ID (with its secret) or a role ARN to assume — exactly one.' => 'console.stream_validation_3e999c2925c2',
        'Server-side encryption must be AES256 or aws:kms.' => 'console.stream_validation_3f54205f96e8',
        'A KMS key only applies with aws:kms server-side encryption.' => 'console.stream_validation_e89e8bcb1f8e',
        'The tags option must be a list or a comma-separated string.' => 'console.stream_validation_5b2a33188ddc',
        'Every tag must be a string.' => 'console.stream_validation_6ce9e5da7d22',
        'A tag must start with a letter and use letters, digits and _.-:/ only (key:value).' => 'console.stream_validation_ee2f689d36d8',
        'At most 100 tags.' => 'console.stream_validation_4c9dbcdd37b9',
        'Unknown destination.' => 'console.stream_validation_42721a8d62cf',
        'An endpoint URL is required.' => 'console.stream_validation_c33169c200c1',
        'A Datadog stream needs an API key as its secret.' => 'console.stream_validation_bb4c9fe57b48',
        'An assumed-role S3 stream stores no secret — the platform assumes the role with its own identity.' => 'console.stream_validation_cda89dfee5ae',
        'An access-key S3 stream needs the secret access key as its secret.' => 'console.stream_validation_fdbdadfcd013',
        'A custom endpoint must be an https:// URL.' => 'console.stream_validation_7b3a393cdd21',
        'A custom endpoint may not carry credentials, a query or a fragment.' => 'console.stream_validation_6677882bc295',
        'The prefix may contain letters, digits, slashes and !_.*\'()-= only (at most 512 characters).' => 'console.stream_validation_a496d374c089',
        'The prefix may not contain empty, "." or ".." segments.' => 'console.stream_validation_443ffea74b48',
    ];

    public static function message(InvalidStreamConfiguration $invalid): string|ActionMessage
    {
        $source = $invalid->getMessage();
        if (isset(self::FIXED[$source])) {
            return new ActionMessage(self::FIXED[$source], sourceEnglish: $source);
        }

        // All possible option names come from the adapter, never from submitted data.
        $options = array_unique([...array_merge(...array_values(LogStreamDestinations::OPTIONS)), 'external_id']);
        foreach ($options as $option) {
            if ($source === "The {$option} option must be a string.") {
                return new ActionMessage('console.stream_validation_5961c24c6a6a', ['option' => $option], sourceEnglish: $source);
            }
            if ($source === "The {$option} option is required.") {
                return new ActionMessage('console.stream_validation_a27554d06a90', ['option' => $option], sourceEnglish: $source);
            }
            if ($source === "The {$option} option must be true or false.") {
                return new ActionMessage('console.stream_validation_f8cd1e74a5a1', ['option' => $option], sourceEnglish: $source);
            }
        }

        $hints = [
            'Use letters, digits and _.-/: only.' => 'console.stream_validation_60e1a7a7195d',
            'Use a host name.' => 'console.stream_validation_f649ffc8e058',
            'Use the access key ID exactly as issued.' => 'console.stream_validation_616ad08ea367',
            'Expected arn:aws:iam::<account>:role/<name>.' => 'console.stream_validation_31b58f71e33c',
            'Use a KMS key ID, alias or ARN.' => 'console.stream_validation_8e7de2b28aa0',
            'Bucket names are 3–63 lowercase letters, digits, dots and hyphens.' => 'console.stream_validation_19ceefc276b9',
            'For example eu-west-1 (or "auto" for R2).' => 'console.stream_validation_04e82dd5f6e9',
            'Use 2–1224 characters from [A-Za-z0-9+=,.@:/_-].' => 'console.stream_validation_e2e0e5019f75',
            'Bucket names are lowercase letters, digits, dots, hyphens and underscores.' => 'console.stream_validation_f0178403715c',
        ];
        foreach ($options as $option) {
            foreach ($hints as $hint => $key) {
                if ($source === "The {$option} option is not valid. {$hint}") {
                    return new ActionMessage($key, ['option' => $option], sourceEnglish: $source);
                }
            }
        }

        foreach (Destination::cases() as $destination) {
            if ($source === "The {$destination->value} destination takes no options.") {
                return new ActionMessage('console.stream_validation_db42eb70e34b', ['destination' => $destination->value], sourceEnglish: $source);
            }
            if ($source === "The {$destination->value} destination has no typed options.") {
                return new ActionMessage('console.stream_validation_14dbbd32b6a3', ['destination' => $destination->value], sourceEnglish: $source);
            }
        }

        $sites = implode(', ', array_map(static fn (DatadogSite $site): string => $site->value, DatadogSite::cases()));
        if ($source === "Unknown Datadog site. Choose one of: {$sites}.") {
            return new ActionMessage('console.stream_validation_6b86c259739c', ['sites' => $sites], sourceEnglish: $source);
        }

        return $source;
    }
}
