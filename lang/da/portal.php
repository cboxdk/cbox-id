<?php

declare(strict_types=1);

// Danish: the admin setup portal (see lang/en/portal.php). Protocol terms stay as-is.
return [
    'layout' => [
        'badge' => 'Opsætningsportal for administratorer',
        'toggle_theme' => 'Skift tema',
    ],

    'copy' => [
        'copy' => 'Kopier',
        'copied' => 'Kopieret',
        'failed' => 'Kunne ikke kopiere. Marker teksten, og kopier den manuelt.',
    ],

    'confirm' => [
        'cancel' => 'Annuller',
        'type_to_confirm' => 'Skriv :name for at bekræfte',
        'hint' => 'Skriv det præcis som vist. Knappen :action er deaktiveret indtil teksten stemmer.',
    ],

    'setup' => [
        'title' => 'Sæt SSO og SCIM op',
        'heading' => 'Sæt virksomhedslogin op',
        'heading_for' => 'Sæt virksomhedslogin op · :organization',
        'description' => 'Du er inviteret til at konfigurere single sign-on for denne organisation. Du har ikke adgang til andet i organisationen herfra.',
        'step' => 'Trin :number',
        'finish' => 'Afslut opsætning',

        'domain' => [
            'heading' => 'Bekræft dit domæne',
            'lead' => 'Tilføj en DNS-post for at bevise at du ejer det domæne dit team logger ind med. Det er den der sender brugerne på domænet til SSO.',
            'label' => 'Domæne',
            'add' => 'Tilføj domæne',
            'record' => 'Tilføj denne TXT-post for :domain, og klik derefter på Kontroller.',
            'record_type' => 'Type',
            'record_host' => 'Host',
            'record_value' => 'Værdi',
            'empty' => 'Der er ikke tilføjet nogen domæner endnu.',
            'verified' => 'Bekræftet',
            'pending' => 'Afventer DNS',
            'check' => 'Kontroller',
            'check_label' => 'Kontroller DNS for :domain',
            'remove' => 'Fjern',
            'remove_label' => 'Fjern :domain',
            'remove_title' => 'Vil du fjerne :domain?',
            'remove_consequence' => 'Brugere der logger ind med en adresse på dette domæne, bliver ikke længere sendt hertil.',
            'invalid' => 'Angiv et gyldigt domæne, f.eks. acme.com.',
            'claimed' => 'En anden organisation har allerede gjort krav på domænet.',
            'verified_status' => 'Domænet er bekræftet. Brugere på domænet kan nu logge ind med SSO.',
            'not_found' => 'Vi kunne ikke finde TXT-posten endnu. Det kan tage et par minutter før DNS-ændringer slår igennem.',
            'removed' => 'Domænet er fjernet.',
        ],

        'connection' => [
            'heading' => 'SSO-forbindelse',
            'new' => 'Ny forbindelse',
            'empty' => 'Ingen SSO-forbindelser endnu.',
            'active' => 'Aktiv',
            'statuses' => [
                'draft' => 'kladde',
                'inactive' => 'inaktiv',
            ],
            'activate' => 'Aktiver',
            'activate_label' => 'Aktiver :name',
            'name_label' => 'Forbindelsens navn',
            'protocol_label' => 'Protokol',
            'idp_entity_id' => 'IdP entity ID',
            'idp_sso_url' => 'IdP SSO URL',
            'sp_entity_id' => 'SP entity ID',
            'sp_acs_url' => 'SP ACS URL',
            'idp_certificate' => 'IdP X.509-certifikat',
            'issuer' => 'Issuer',
            'client_id' => 'Client ID',
            'client_secret' => 'Client secret',
            'signing_key' => 'Signeringsnøgle',
            'create' => 'Opret forbindelse',
            'cancel' => 'Annuller',
            'created' => 'Forbindelsen er oprettet som kladde.',
            'activated' => 'Forbindelsen er aktiveret.',
            'discovery_failed' => 'Vi kunne ikke læse udbyderens OpenID-konfiguration. Tjek issuer-URL’en. (:reason)',
        ],

        'directory' => [
            'step' => 'Katalogsynkronisering',
            'heading' => 'Katalogsynkronisering (SCIM)',
            'new' => 'Nyt katalog',
            'base_url_help' => 'Peg din identitetsudbyder (Okta, Microsoft Entra) mod denne base-URL, og godkend med et katalogs bearer-token.',
            'copy_base_url' => 'Kopier SCIM-base-URL’en',
            'token_heading' => 'Bearer-token for »:name«',
            'token_once' => 'Kopier det nu. Det vises kun én gang og kan ikke hentes igen.',
            'copy_token' => 'Kopier token',
            'name_label' => 'Katalogets navn',
            'register' => 'Registrer katalog',
            'cancel' => 'Annuller',
            'empty' => 'Ingen kataloger er forbundet endnu.',
            'active' => 'Aktiv',
            'paused' => 'Sat på pause',
        ],
    ],

    // The organization's audit events, under a link that covers `audit_logs` — read-only.
    'audit_logs' => [
        'title' => 'Revisionslog',
        'heading' => 'Revisionslog',
        'heading_for' => 'Revisionslog · :organization',
        'description' => 'Det, applikationen har registreret om din organisation, nyeste først. Visningen er skrivebeskyttet: intet her kan ændres.',
        'action' => 'Handling',
        'actor' => 'Aktør-ID',
        'target' => 'Mål-ID',
        'from' => 'Fra',
        'to' => 'Til',
        'filter' => 'Filtrér',
        'clear' => 'Ryd filtre',
        'export' => 'Eksportér CSV',
        'export_note' => 'CSV-filen indeholder de nyeste matchende hændelser, op til :limit.',
        'empty' => 'Ingen hændelser matcher disse filtre.',
        'empty_none' => 'Der er endnu ikke registreret hændelser for denne organisation.',
        'time' => 'Tidspunkt',
        'targets' => 'Mål',
        'location' => 'Placering',
        'metadata' => 'Detaljer',
        'newer' => 'Nyeste hændelser',
        'older' => 'Ældre hændelser',
        'done' => 'Færdig',
        'count' => ':count hændelser på denne side',
    ],

    'done' => [
        'title' => 'Alt er klar',
        'heading' => 'Alt er klar',
        'body' => 'Virksomhedslogin for :organization er konfigureret. Opsætningslinket er nu brugt og lukket. Du kan lukke dette vindue.',
        // For a link that only read the audit logs: nothing was configured.
        'audit_logs_body' => 'Du er færdig med at gennemgå revisionsloggen for :organization. Linket er nu brugt og lukket. Du kan lukke dette vindue.',
        'this_organization' => 'denne organisation',
    ],

    'expired' => [
        'title' => 'Linket er ikke tilgængeligt',
        'heading' => 'Opsætningslinket er ikke længere gyldigt',
        'body' => 'Linket er måske udløbet eller allerede brugt. Af sikkerhedshensyn kan opsætningslinks kun bruges én gang og er tidsbegrænsede. Bed den der inviterede dig, om at sende et nyt.',
    ],
];
