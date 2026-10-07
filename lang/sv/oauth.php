<?php

declare(strict_types=1);

// Swedish: the OAuth authorization pages (see lang/en/oauth.php).
return [
    'signed_in_as' => 'Inloggad som :account',
    'cancel_and_return' => 'Avbryt och gå tillbaka till :client',

    'consent' => [
        'title' => 'Ge åtkomst',
        'heading' => 'Ge :client åtkomst',
        'wants_access' => ':client vill få åtkomst till ditt :account-konto.',
        'registered_by' => 'Registrerad av :owner — en apps namn väljs av den som registrerade den.',
        'unknown_owner' => 'en organisation som inte längre finns',
        'in_organization' => 'I :organization',
        'will_allow' => 'Det här ger :client behörighet att',
        'cancel' => 'Avbryt',
        'authorize' => 'Godkänn',
        'redirect_notice' => 'Du omdirigeras till :host när du har godkänt.',
        // A client that registered itself (RFC 7591, or a client ID metadata document).
        'self_registered_owner' => 'appen själv',
        'self_registered' => 'Den här appen har registrerat sig själv. Ingen på :account har granskat den — fortsätt bara om du själv startade den här inloggningen.',
        'published_by' => 'Beskriven av :host — det enda med den här appen som har kontrollerats.',
        'about_app' => 'Om den här appen',
        // Management-plane scopes an agent acts with as the person.
        'critical' => 'Kritisk',
        'acts_as_you' => 'Allt den gör som du begränsas till det du själv får göra, och registreras i granskningsloggen.',
        'critical_notice' => 'Kritiska åtgärder väntar fortfarande på ditt godkännande på din enhet, varje gång.',

        'scopes' => [
            'openid' => 'Verifiera din identitet',
            'profile' => 'Ditt namn',
            'email' => 'Din e-postadress',
            'offline_access' => 'Förbli inloggad',
            'organizations' => 'Vilka organisationer du tillhör',
            'groups' => 'Dina roller',
        ],
    ],

    'failure' => [
        'title' => 'Auktoriseringen misslyckades',
        'heading' => 'Auktoriseringen misslyckades',
        'back' => 'Tillbaka till :name',
        'generic' => 'Den här auktoriseringsbegäran kunde inte slutföras.',
        'expired' => 'Den här auktoriseringsbegäran har gått ut eller har redan använts. Börja om.',
        'par_required' => 'Den här servern kräver pushed authorization requests (PAR). Skicka först begäran till /oauth/par.',
        'unknown_client' => 'Okänd klient. Den här applikationen är inte registrerad i Cbox ID.',
        'client_document' => 'Appens beskrivning kunde inte läsas. Den finns på adressen som appen angav som sitt ID, och dokumentet saknas, kan inte nås eller är ogiltigt.',
        'redirect_mismatch' => 'Angiven omdirigerings-URI matchar inte någon av dem som har registrerats för den här applikationen.',
        'stale' => 'Den här auktoriseringsbegäran kan inte längre slutföras. Börja om.',
        'account_attention' => 'Ditt konto behöver åtgärdas innan du kan fortsätta. Logga in igen.',
        'step_up' => 'Den här applikationen kräver en nyare eller starkare inloggning. Börja om.',
    ],

    'organization' => [
        'title' => 'Välj en organisation',
        'heading' => 'Välj en organisation',
        'lead' => ':client använder organisationen du väljer, med din roll i den.',
        'none_create' => 'Du är inte med i någon organisation här ännu. Skapa en för att fortsätta.',
        'none_invite' => 'Du är inte med i någon organisation här ännu. Be någon att bjuda in dig till sin och försök sedan igen.',
        'list_label' => 'Dina organisationer',
        'suggested' => 'Föreslagen',
        'continuing_with' => 'Fortsätter med :name',
        'create' => 'Skapa en organisation',
        'required' => 'Välj en organisation för att fortsätta.',
        'not_member' => 'Du är inte aktiv medlem i den organisationen.',
        'roles' => [
            'owner' => 'Ägare',
            'admin' => 'Administratör',
            'developer' => 'Utvecklare',
            'member' => 'Medlem',
            'viewer' => 'Läsare',
        ],
    ],

    'create_organization' => [
        'title' => 'Skapa en organisation',
        'heading' => 'Skapa en organisation',
        'lead' => 'Ditt team eller företag i :client. Du blir ägare och kan bjuda in andra när du är inne.',
        'name_label' => 'Organisationens namn',
        'submit' => 'Skapa och fortsätt',
        'choose_existing' => 'Välj en befintlig organisation',
        'name_required' => 'Ge din organisation ett namn.',
        'not_offered' => 'Det går inte att skapa en organisation här. Be en administratör att bjuda in dig till en.',
        'too_many' => 'Du har skapat flera organisationer på kort tid. Försök igen om :count minut.|Du har skapat flera organisationer på kort tid. Försök igen om :count minuter.',
    ],
];
