<?php

declare(strict_types=1);

// Norwegian Bokmål: the admin setup portal (pages/portal/*). Protocol terms stay as-is.
return [
    'layout' => [
        'badge' => 'Oppsettsportal for administratorer',
        'toggle_theme' => 'Bytt tema',
    ],

    'copy' => [
        'copy' => 'Kopier',
        'copied' => 'Kopiert',
        'failed' => 'Kopiering mislyktes – merk og kopier manuelt',
    ],

    'confirm' => [
        'cancel' => 'Avbryt',
        'type_to_confirm' => 'Skriv :name for å bekrefte',
        'hint' => 'Nøyaktig som vist – :action forblir deaktivert til teksten stemmer.',
    ],

    'setup' => [
        'title' => 'Konfigurer SSO og SCIM',
        'heading' => 'Konfigurer innlogging for virksomheten',
        'heading_for' => 'Konfigurer innlogging for virksomheten · :organization',
        'description' => 'Du er invitert til å konfigurere single sign-on (SSO) for denne organisasjonen. Ingenting annet i organisasjonen er tilgjengelig herfra.',
        'step' => 'Trinn :number',
        'finish' => 'Fullfør oppsettet',

        'domain' => [
            'heading' => 'Bekreft domenet ditt',
            'lead' => 'Legg til en DNS-post for å bevise at du eier domenet teamet ditt logger inn med. Det er dette som sender disse brukerne til SSO.',
            'label' => 'Domene',
            'add' => 'Legg til domene',
            'record' => 'Legg til denne TXT-posten for :domain, og klikk deretter på Sjekk.',
            'record_type' => 'Type',
            'record_host' => 'Vert',
            'record_value' => 'Verdi',
            'empty' => 'Ingen domener er lagt til ennå.',
            'verified' => 'Bekreftet',
            'pending' => 'Venter på DNS',
            'check' => 'Sjekk',
            'check_label' => 'Sjekk DNS for :domain',
            'remove' => 'Fjern',
            'remove_label' => 'Fjern :domain',
            'remove_title' => 'Vil du fjerne :domain?',
            'remove_consequence' => 'Alle som logger inn med en adresse på dette domenet, blir ikke lenger sendt hit.',
            'invalid' => 'Skriv inn et gyldig domene, f.eks. acme.com.',
            'claimed' => 'Domenet er allerede registrert av en annen organisasjon.',
            'verified_status' => 'Domenet er bekreftet – brukere på dette domenet kan nå logge inn med SSO.',
            'not_found' => 'Vi fant ikke TXT-posten ennå – det kan ta noen minutter før DNS-endringer slår gjennom.',
            'removed' => 'Domenet er fjernet.',
        ],

        'connection' => [
            'heading' => 'SSO-tilkobling',
            'new' => 'Ny tilkobling',
            'empty' => 'Ingen SSO-tilkoblinger ennå.',
            'active' => 'Aktiv',
            'statuses' => [
                'draft' => 'utkast',
                'inactive' => 'inaktiv',
            ],
            'activate' => 'Aktiver',
            'activate_label' => 'Aktiver :name',
            'name_label' => 'Navn på tilkoblingen',
            'protocol_label' => 'Protokoll',
            'idp_entity_id' => 'IdP entity ID',
            'idp_sso_url' => 'IdP SSO URL',
            'sp_entity_id' => 'SP entity ID',
            'sp_acs_url' => 'SP ACS URL',
            'idp_certificate' => 'IdP X.509-sertifikat',
            'issuer' => 'Issuer',
            'client_id' => 'Klient-ID',
            'client_secret' => 'Klienthemmelighet',
            'signing_key' => 'Signeringsnøkkel',
            'create' => 'Opprett tilkobling',
            'cancel' => 'Avbryt',
            'created' => 'Tilkoblingen er opprettet som utkast.',
            'activated' => 'Tilkoblingen er aktivert.',
            'discovery_failed' => 'Kunne ikke lese leverandørens OpenID-konfigurasjon – sjekk issuer-URL-en. (:reason)',
        ],

        'directory' => [
            'step' => 'Katalogsynkronisering',
            'heading' => 'Katalogsynkronisering (SCIM)',
            'new' => 'Ny katalog',
            'base_url_help' => 'Pek identitetsleverandøren din (Okta, Microsoft Entra) mot denne basis-URL-en, og autentiser med katalogens bearer-token.',
            'copy_base_url' => 'Kopier SCIM-basis-URL-en',
            'token_heading' => 'Bearer-token for «:name»',
            'token_once' => 'Kopier det nå – det vises bare én gang og kan ikke hentes igjen.',
            'copy_token' => 'Kopier token',
            'name_label' => 'Navn på katalogen',
            'register' => 'Registrer katalog',
            'cancel' => 'Avbryt',
            'empty' => 'Ingen kataloger er koblet til ennå.',
            'active' => 'Aktiv',
            'paused' => 'Satt på pause',
        ],
    ],

    // The organization's audit events, under a link that covers `audit_logs` — read-only.
    'audit_logs' => [
        'title' => 'Revisjonslogger',
        'heading' => 'Revisjonslogger',
        'heading_for' => 'Revisjonslogger · :organization',
        'description' => 'Det applikasjonen har registrert om organisasjonen din, nyeste først. Visningen er skrivebeskyttet: ingenting her kan endres.',
        'action' => 'Handling',
        'actor' => 'Aktør-ID',
        'target' => 'Mål-ID',
        'from' => 'Fra',
        'to' => 'Til',
        'filter' => 'Filtrer',
        'clear' => 'Fjern filtre',
        'export' => 'Eksporter CSV',
        'export_note' => 'CSV-filen inneholder de nyeste samsvarende hendelsene, opptil :limit.',
        'empty' => 'Ingen hendelser samsvarer med disse filtrene.',
        'empty_none' => 'Ingen hendelser er registrert for denne organisasjonen ennå.',
        'time' => 'Tidspunkt',
        'targets' => 'Mål',
        'location' => 'Sted',
        'metadata' => 'Detaljer',
        'newer' => 'Nyeste hendelser',
        'older' => 'Eldre hendelser',
        'done' => 'Ferdig',
        'count' => ':count hendelser på denne siden',
    ],

    'done' => [
        'title' => 'Ferdig',
        'heading' => 'Alt er klart',
        'body' => 'Innlogging for virksomheten er konfigurert for :organization. Denne oppsettslenken er nå brukt og stengt. Du kan lukke dette vinduet.',
        // For a link that only read the audit logs: nothing was configured.
        'audit_logs_body' => 'Du er ferdig med å gå gjennom revisjonsloggene for :organization. Lenken er nå brukt og lukket. Du kan lukke dette vinduet.',
        'this_organization' => 'denne organisasjonen',
    ],

    'expired' => [
        'title' => 'Lenken er ikke tilgjengelig',
        'heading' => 'Denne oppsettslenken er ikke lenger gyldig',
        'body' => 'Lenken kan ha utløpt eller allerede blitt brukt. Av sikkerhetshensyn kan oppsettslenker bare brukes én gang og er tidsbegrensede. Be personen som inviterte deg, om å sende en ny.',
    ],
];
