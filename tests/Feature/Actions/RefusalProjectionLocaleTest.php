<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\Integrations\LogStreamDestinations;
use Cbox\LaravelSiem\Exceptions\InvalidStreamConfiguration;
use Cbox\LaravelSiem\ValueObjects\Options\DatadogOptions;
use Cbox\LaravelSiem\ValueObjects\Options\GcsOptions;
use Cbox\LaravelSiem\ValueObjects\Options\S3Options;

it('renders missing resources in the console language while retaining protocol messages', function (): void {
    app()->setLocale('da');
    $refused = ActionRefused::notFound('payment_method');
    expect($refused->getMessage())->toBe('Payment_method not found.')
        ->and($refused->forConsole()->getMessage())->toBe('Kunne ikke finde betalingsmetoden.')
        ->and($refused->forConsole()->status)->toBe(404)
        ->and($refused->forConsole()->error)->toBe('not_found');
    $unknown = ActionRefused::notFound('extension resource');
    expect($unknown->getMessage())->toBe('Extension resource not found.')
        ->and($unknown->forConsole()->getMessage())->toBe('Det ønskede element blev ikke fundet.');
});

it('translates actual SIEM option failures without echoing submitted values', function (string $type, array $options, string $field, string $translated): void {
    app()->setLocale('da');
    try {
        $type::fromArray($options);
        $this->fail('The invalid configuration was accepted.');
    } catch (InvalidStreamConfiguration $invalid) {
        $refused = LogStreamDestinations::refusal($invalid);
        expect($refused->getMessage())->toBe($invalid->getMessage())
            ->and($refused->forConsole()->getMessage())->toBe($translated)
            ->and($refused->forConsole()->field)->toBe($field)
            ->and($refused->forConsole()->error)->toBe('invalid_stream_configuration');
    }
})->with([
    [GcsOptions::class, [], 'options.bucket', 'Indstillingen bucket skal angives.'],
    [GcsOptions::class, ['bucket' => 'bad secret value'], 'options.bucket', 'Indstillingen bucket er ugyldig. Bucketnavne må indeholde små bogstaver, cifre, punktummer, bindestreger og understregninger.'],
    [DatadogOptions::class, ['service' => []], 'options.service', 'Indstillingen service skal være en tekststreng.'],
    [S3Options::class, [], 'options.access_key_id', 'Angiv enten et adgangsnøgle-id med tilhørende hemmelig nøgle eller ARN for den rolle, der skal påtages. Vælg præcis én af mulighederne.'],
]);

it('leaves unrecognized SIEM diagnostics unchanged rather than guessing their meaning', function (): void {
    app()->setLocale('da');
    $source = 'Extension diagnostic: The customer option is not valid. Private detail.';
    $refused = LogStreamDestinations::refusal(InvalidStreamConfiguration::for('custom', $source));
    expect($refused->forConsole()->getMessage())->toBe($source)
        ->and($refused->forConsole()->field)->toBe('options.custom');
});
