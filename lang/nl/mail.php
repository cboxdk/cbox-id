<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'footer' => '© :year :brand · Dit is een automatisch bericht van uw identiteitsplatform.',
    ],
    'common' => [
        'paste_link' => 'Of plak deze link in uw browser:',
    ],
    'roles' => [
        'owner' => 'Eigenaar',
        'admin' => 'Beheerder',
        'developer' => 'Ontwikkelaar',
        'member' => 'Lid',
        'viewer' => 'Viewer',
    ],
    'magic_link' => [
        'subject' => 'Uw :brand inlog link',
        'heading' => 'Aanmelden voor :brand',
        'body' => 'Klik op de onderstaande knop om u aan te melden. Deze link is eenmalig en verloopt binnen 15 minuten. Als u er niet om vraagt, kunt u deze e-mail veilig negeren.',
        'button' => 'Aanmelden voor :brand',
    ],
    'password_reset' => [
        'subject' => 'Uw :brand-wachtwoord resetten',
        'heading' => 'Uw wachtwoord resetten',
        'body' => 'We hebben een verzoek ontvangen om uw :brand wachtwoord opnieuw in te stellen. Klik op de onderstaande knop om een nieuwe te kiezen. Deze link is eenmalig gebruik en verloopt in 60 minuten. Als u dit niet hebt aangevraagd, kunt u deze e-mail veilig negeren.Uw wachtwoord zal niet veranderen.',
        'button' => 'Wachtwoord resetten',
    ],
    'email_verification' => [
        'subject' => 'Bevestig uw e-mailadres :brand',
        'heading' => 'Bevestig uw e-mail',
        'body' => 'Welkom bij :brand. Bevestig dat dit uw e-mailadres is om uw account te beveiligen. Deze link is eenmalig en verloopt binnen 24 uur.',
        'button' => 'E-mailadres bevestigen',
        'not_you' => 'Als u geen :brand account hebt aangemaakt, kunt u deze e-mail negeren. Er gebeurt niets zonder de link.',
    ],
    'admin_assigned_password' => [
        'subject' => 'Uw :brand wachtwoord is gereset',
        'heading' => 'Uw wachtwoord is gereset',
        'body' => 'Een beheerder stelt een nieuw wachtwoord in op uw account. Meld u hieronder aan.',
        'temporary' => 'Je wordt meteen gevraagd om je eigen wachtwoord te kiezen.',
        'expires' => 'Dit wachtwoord werkt niet meer op :date, dus meld je voor die tijd aan.',
        'not_expected' => 'Als je dit niet verwachtte, neem dan contact op met je beheerder. Iemand met toegang tot de console van je organisatie heeft deze wijziging gemaakt, en het wordt opgenomen op de audit trail.',
    ],
    'invitation' => [
        'subject' => ':inviter nodigde u uit om deel te nemen :organization',
        'heading' => 'Join :organization',
        'invited' => ':inviter nodigde u uit om deel te nemen aan :organization.',
        'invited_as' => ':inviter nodigde u uit voor :organization als :role.',
        'accept_app' => 'Accepteer dat u zich aanmeldt bij :app met uw :organization account.',
        'accept' => 'Accepteer het instellen van uw account en meld u aan.',
        'button' => 'Evaluatie-uitnodiging',
        'note' => 'De link opent een pagina waar u bevestigt dat er niets gebeurt totdat je dat doet. Het verloopt in 7 dagen. Als u dit niet verwachtte, kunt u het negeren.',
    ],
    'organization_invite' => [
        'subject' => ':inviter nodigde u uit om :organization toe te dienen op :brand',
        'heading' => 'Hulp loopt :organization op :brand',
        'invited' => ':inviter nodigde u uit om :organization te beheren op :brand › de console voor haar identiteit providers: omgevingen, leden en facturering. Accepteer een wachtwoord en meld u aan.',
        'invited_as' => ':inviter nodigde u uit om :organization te beheren als :role op :brand en de console voor haar identiteitsproviders: omgevingen, leden en facturering. Accepteer een wachtwoord en meld u aan.',
        'button' => 'Uitnodiging accepteren',
        'note' => 'Als je deze uitnodiging niet verwachtte, kun je het negeren. Er gebeurt niets totdat je het accepteert.',
    ],
    'portal_link' => [
        'subject' => 'Opzetten :organization op :brand',
        'heading' => ':organization instellen',
        'lead' => 'U bent gevraagd om het volgende in te stellen voor :organization op :brand. U heeft geen account nodig, de knop hieronder is alles wat nodig is.',
        'intents' => [
            'sso' => 'Enkelvoudig aanmelden bij uw identiteitsprovider',
            'dsync' => 'Map synchroniseren (SCIM)',
            'domain_verification' => 'Uw e-maildomeinen verifiëren',
            'log_streams' => 'Uw auditlog streamen naar uw SIEM',
            'certificate_renewal' => 'Hernieuwen van uw SAML ondertekeningscertificaat',
            'audit_logs' => 'Uw auditlogs bekijken (alleen-lezen)',
        ],
        'button' => 'Startopstelling',
        'expires' => 'De link werkt eenmaal, tot :date. Als deze verloopt, vraag dan om een nieuwe link.',
        'not_expected' => 'Als je dit niet verwachtte, kun je het negeren. Er is niets ingesteld totdat iemand de link gebruikt.',
    ],
    'certificate_expiring' => [
        'subject' => 'Enkelvoudig aantekenen tot :connection stopt met werken in :count dag|Enkelvoudig aanmelden tot en met :connection stopt met werken in :count dagen',
        'subject_expired' => 'Enkelvoudig aanmelden tot en met :connection is gestopt met werken',
        'heading' => 'Uw SAML certificaat vervalt binnenkort',
        'heading_expired' => 'Uw SAML certificaat is verlopen',
        'lead' => 'Het ondertekeningscertificaat van :connection, de single ign-on verbinding voor :organization, vervalt op :date. Daarna kan niemand via het aanmelden.',
        'lead_expired' => 'Het ondertekeningscertificaat van :connection, de single ign-on verbinding voor :organization, verlopen op :date. Niemand kan inloggen door middel van het totdat het wordt vernieuwd.',
        'what_to_do' => 'Vraag degene die uw identiteitsprovider runt om zijn nieuwe ondertekeningscertificaat, upload het vervolgens in uw admin console of vraag uw beheerder om een Admin Portal link om het te uploaden.',
        'why' => 'U ontvangt dit omdat u eigenaar of beheerder bent van deze organisatie op :brand.',
    ],
    'one_time_code' => [
        'label' => 'Uw code',
        'expires' => 'Het verloopt in :count minuut en werkt slechts eenmaal.|Het verloopt in :count minuten en werkt slechts eenmaal.',
        'never_share' => 'Don\'t delen met niemand .. :brand zal nooit vragen u voor het via telefoon, chat of e-mail.',
        'not_you_title' => 'Heb je hier niet om gevraagd?',
        'sign_in' => [
            'subject' => 'Uw :brand inlogcode',
            'heading' => 'Bevestig je identiteit',
            'lead' => 'Voer deze code in om je aan te melden op :brand.',
            'not_you' => 'Voer het nergens in, en u kunt deze e-mail negeren. Als u een :brand account, het wachtwoord te wijzigen iemand die zo ver is gekomen kan het weten.',
        ],
        'device' => [
            'subject' => 'Uw :brand-code om een apparaat goed te keuren',
            'heading' => 'Aanmelden op een andere voorziening',
            'lead' => 'Voer deze code in om aan te melden bij :brand op een ander apparaat, zoals een tv of een commandoregelprogramma.',
            'not_you' => 'Niet invoeren overal. Iemand kan proberen om u te krijgen om hun apparaat goed te keuren, dat is hoe dit soort aanval begint. Negeer deze e-mail en niets is goedgekeurd.',
        ],
        'link' => [
            'subject' => 'Uw :brand code om een inlogmethode te koppelen',
            'heading' => 'Koppeling van een inlogmethode',
            'lead' => 'Voer deze code in om een nieuwe manier van aanmelden te koppelen aan uw :brand-account.',
            'not_you' => 'Voer het nergens in. Het kan zijn dat iemand probeert om zijn aanmelding aan uw account te koppelen. Negeer deze e-mail en er is niets gekoppeld.',
        ],
        'verify' => [
            'subject' => 'Uw :brand-verificatiecode',
            'heading' => 'Uw verificatiecode',
            'lead' => 'Voer deze code in op :brand om te bevestigen dat dit e-mailadres van u is.',
            'not_you' => 'U kunt deze e-mail negeren . Er gebeurt niets zonder de code.',
        ],
    ],
];
