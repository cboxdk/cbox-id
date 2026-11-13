<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Suojattu :name:n avulla',
        'theme' => 'Teema',
        'toggle_theme' => 'Vaihda vaalean ja tumman teeman välillä',
        'about' => 'Tietoja tästä tuotteesta',
        'hero_body' => 'Yritystason SSO, SCIM-hakemistosynkronointi, MFA ja pääsyavaimet, RBAC sekä tarkastusloki, josta muutokset voidaan havaita — omassa ympäristössäsi ajettavaksi, omassa hallinnassasi.',
        'features' => [
            'sso' => 'Kertakirjautuminen SAML- ja OIDC-protokollilla',
            'scim' => 'Käyttäjätilien provisiointi SCIM 2.0:lla',
            'mfa' => 'Pääsyavaimet, TOTP ja kertakäyttöiset kirjautumislinkit',
            'audit' => 'Hash-ketjutettu tarkastusloki, josta muutokset voidaan havaita',
        ],
        'hero_title' => 'Yksi identiteettikerros kaikille sovelluksillesi.',
    ],
    'language' => [
        'label' => 'Kieli',
    ],

    'theme' => [
        'system' => 'Järjestelmä',
        'light' => 'Vaalea',
        'dark' => 'Tumma',
    ],
    'skip_to_content' => 'Siirry sisältöön',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Avaa Cbox ID -sovellus',
        'lead' => 'Cbox ID -sovellus ei avautunut tällä laitteella.',
        'other_phone' => 'Onko sovellus toisessa puhelimessa? Hyväksy pyyntö siellä ja palaa sitten kirjautumissivulle. Se jatkuu itsestään.',
        'open' => 'Avaa sovelluksessa',
        'install' => 'Hanki sovellus',
        'back' => 'Tai palaa takaisin ja valitse toinen vahvistustapa.',
    ],
];
