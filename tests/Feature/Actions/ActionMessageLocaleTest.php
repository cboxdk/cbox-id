<?php

declare(strict_types=1);

use App\Actions\Invitations\InvitationFields;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\RadarConditions;
use App\Platform\Radar\RadarLists;
use App\Platform\Radar\RadarRuleInvalid;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidSchema;
use Cbox\Id\Kernel\Authorization\Schema\SchemaParser;
use Cbox\Id\OAuthServer\Exceptions\InvalidApiDefinition;

it('localizes a console refusal without changing its API message or authority fields', function (): void {
    app('translator')->addLines(['console.locale_test_refusal' => 'The role [:role] cannot be assigned.'], 'en');
    app('translator')->addLines(['console.locale_test_refusal' => 'Rollen [:role] kan ikke tildeles.'], 'da');
    app()->setLocale('da');
    $refused = new ActionRefused('role_not_assignable', new ActionMessage('console.locale_test_refusal', ['role' => 'deploy:production']), 409, 'roles');

    expect($refused->getMessage())->toBe('The role [deploy:production] cannot be assigned.');
    $console = $refused->forConsole();
    expect($console->getMessage())->toBe('Rollen [deploy:production] kan ikke tildeles.')
        ->and($console->error)->toBe('role_not_assignable')
        ->and($console->status)->toBe(409)
        ->and($console->field)->toBe('roles')
        ->and($refused->getMessage())->toBe('The role [deploy:production] cannot be assigned.');
});

it('keeps field keys and external text intact while translating source-owned field messages', function (): void {
    app('translator')->addLines(['console.locale_test_required' => 'Enter the client ID.'], 'en');
    app('translator')->addLines(['console.locale_test_required' => 'Angiv klient-id.'], 'da');
    app()->setLocale('da');
    $refused = ActionRefused::onFields('missing_credentials', [
        'client_id' => new ActionMessage('console.locale_test_required'),
        'external' => 'A provider supplied this text: :client_id',
    ]);
    $console = $refused->forConsole();

    expect($refused->fields['client_id'])->toBe('Enter the client ID.')
        ->and($console->fields)->toBe([
            'client_id' => 'Angiv klient-id.',
            'external' => 'A provider supplied this text: :client_id',
        ])
        ->and($console->field)->toBe('client_id')
        ->and($console->error)->toBe('missing_credentials')
        ->and($console->getMessage())->toBe('Angiv klient-id. A provider supplied this text: :client_id');
});

it('keeps invitation role lists and recipient values intact across API and console rendering', function (): void {
    app()->setLocale('da');
    $refused = InvitationRefused::roleNotOffered();
    expect($refused->getMessage())->toBe('Choose one of: Admin, Member. Ownership is handed over with "Transfer ownership", never by invitation.');
    $console = InvitationFields::refused($refused)->forConsole();
    expect($console->error)->toBe('role_not_offered')
        ->and($console->getMessage())->not->toContain('Choose one of')
        ->and($console->getMessage())->not->toContain('console.')
        ->and($console->field)->toBe('role');

    $app = 'Acme:preview <testing>';
    $refused = InvitationRefused::returnToNotRegistered($app);
    expect($refused->getMessage())->toBe("The return address must be on one of {$app}'s registered redirect URI origins.");
    $console = InvitationFields::refused($refused)->forConsole();
    expect($console->getMessage())->toContain($app)
        ->not->toContain('The return address')
        ->and($console->getMessage())->not->toContain('console.');
});

it('translates structured framework refusals without treating submitted values as messages', function (): void {
    app()->setLocale('da');
    $identifier = 'https://example.test/Choose another';
    $framework = InvalidApiDefinition::identifierTaken($identifier);
    $refused = ActionRefused::because('invalid_api', FrameworkMessage::from($framework), 'identifier');
    expect($refused->getMessage())->toBe($framework->getMessage())
        ->and($refused->forConsole()->getMessage())->toBe("Der er allerede registreret et API med identifikatoren [{$identifier}] i dette miljø.")
        ->and($refused->forConsole()->field)->toBe('identifier');

    $password = PolicyViolation::tooShort(17);
    $refused = ActionRefused::because('password_policy', FrameworkMessage::from($password), 'password');
    expect($refused->getMessage())->toBe('The password must be at least 17 characters.')
        ->and($refused->forConsole()->getMessage())->toBe('Adgangskoden skal være på mindst 17 tegn.');
});

it('preserves a protocol message independently from its localized presentation', function (): void {
    app()->setLocale('da');
    $message = new ActionMessage('console.cancel_77dfd213', sourceEnglish: 'Exact API message.');
    expect($message->render('en'))->toBe('Exact API message.');
    // A custom exception from an extension is never translated by guessing its wording.
    $custom = new RuntimeException('Customer-supplied diagnostic: Choose another');
    expect(FrameworkMessage::from($custom))->toBe($custom->getMessage());
});

it('translates nested schema line and relation descriptions while preserving their identifiers', function (): void {
    app()->setLocale('da');
    try {
        (new SchemaParser)->parse("type document\n relation viewer: missing\n");
        test()->fail('The invalid schema was accepted.');
    } catch (InvalidSchema $error) {
        $apiMessage = $error->getMessage();
        $refusal = ActionRefused::because($error->errorCode(), FrameworkMessage::from($error), 'schema');
        expect($refusal->getMessage())->toBe($apiMessage)
            ->and($refusal->forConsole()->getMessage())->toContain('Linje 2:', '`viewer` på `document`', '`missing`')
            ->not->toContain('console.')
            ->and($refusal->forConsole()->getMessage())->not->toContain('refers to')
            ->and($refusal->forConsole()->getMessage())->not->toContain(' on ');
    }
});

it('translates numbered Radar validation without embedding an English condition label', function (): void {
    app()->setLocale('da');
    try {
        RadarConditions::normalize([['field' => 'unknown', 'operator' => 'eq', 'value' => 'unchanged']]);
        test()->fail('The invalid condition was accepted.');
    } catch (RadarRuleInvalid $error) {
        $refusal = ActionRefused::because('invalid_rule', FrameworkMessage::from($error), 'conditions');
        expect($refusal->getMessage())->toBe($error->getMessage())
            ->and($refusal->forConsole()->getMessage())->toStartWith('Betingelse 1:')
            ->not->toContain('Condition')
            ->and($refusal->forConsole()->getMessage())->not->toContain('console.');
    }
});

it('localizes duplicate Radar list entries without raw enum labels and preserves API diagnostics', function (): void {
    app()->setLocale('da');
    $lists = app(RadarLists::class);
    foreach (RadarList::cases() as $list) {
        $lists->add($list, RadarListKind::Email, 'someone@example.test');
        try {
            $lists->add($list, RadarListKind::Email, 'someone@example.test');
            test()->fail('The duplicate was accepted.');
        } catch (RadarRuleInvalid $error) {
            $refusal = ActionRefused::because('duplicate_entry', FrameworkMessage::from($error));
            expect($refusal->getMessage())->toBe("That email is already on the {$list->value} list.")
                ->and($refusal->forConsole()->getMessage())->toBe($list === RadarList::Allow
                    ? 'Denne post findes allerede på tilladelseslisten.'
                    : 'Denne post findes allerede på blokeringslisten.');
        }
    }
});
