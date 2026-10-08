<?php

declare(strict_types=1);

// Swedish: the admin setup portal (see lang/en/portal.php). Protocol terms stay as-is.
return [
    'layout' => [
        'badge' => 'Konfigurationsportal för administratörer',
        'toggle_theme' => 'Växla tema',
    ],

    'copy' => [
        'copy' => 'Kopiera',
        'copied' => 'Kopierat',
        'failed' => 'Kunde inte kopiera — markera och kopiera manuellt',
    ],

    'confirm' => [
        'cancel' => 'Avbryt',
        'type_to_confirm' => 'Skriv :name för att bekräfta',
        'hint' => 'Exakt som det visas — :action är inaktiverad tills det stämmer.',
    ],

    'setup' => [
        'title' => 'Konfigurera SSO och SCIM',
        'heading' => 'Konfigurera inloggning för företag',
        'heading_for' => 'Konfigurera inloggning för företag · :organization',
        'description' => 'Du har bjudits in att konfigurera single sign-on (SSO) för den här organisationen. Inget annat i organisationen är tillgängligt härifrån.',
        'step' => 'Steg :number',
        'finish' => 'Slutför konfigurationen',

        'domain' => [
            'heading' => 'Verifiera din domän',
            'lead' => 'Lägg till en DNS-post för att bevisa att du äger domänen som ditt team loggar in med. Det är den som skickar de användarna till SSO.',
            'label' => 'Domän',
            'add' => 'Lägg till domän',
            'record' => 'Lägg till den här TXT-posten för :domain och klicka sedan på Kontrollera.',
            'record_type' => 'Typ',
            'record_host' => 'Värd',
            'record_value' => 'Värde',
            'empty' => 'Inga domäner har lagts till ännu.',
            'verified' => 'Verifierad',
            'pending' => 'Väntar på DNS',
            'check' => 'Kontrollera',
            'check_label' => 'Kontrollera DNS för :domain',
            'remove' => 'Ta bort',
            'remove_label' => 'Ta bort :domain',
            'remove_title' => 'Ta bort :domain?',
            'remove_consequence' => 'Den som loggar in med en adress på den här domänen dirigeras inte längre hit.',
            'invalid' => 'Ange en giltig domän, t.ex. acme.com.',
            'claimed' => 'Domänen har redan tagits i anspråk av en annan organisation.',
            'verified_status' => 'Domänen är verifierad — användare på den här domänen kan nu logga in med SSO.',
            'not_found' => 'Vi hittade inte TXT-posten ännu — det kan ta några minuter innan DNS-ändringar har spridits.',
            'removed' => 'Domänen har tagits bort.',
        ],

        'connection' => [
            'heading' => 'SSO-anslutning',
            'new' => 'Ny anslutning',
            'empty' => 'Inga SSO-anslutningar ännu.',
            'active' => 'Aktiv',
            'statuses' => [
                'draft' => 'utkast',
                'inactive' => 'inaktiv',
            ],
            'activate' => 'Aktivera',
            'activate_label' => 'Aktivera :name',
            'name_label' => 'Anslutningens namn',
            'protocol_label' => 'Protokoll',
            'idp_entity_id' => 'IdP entity ID',
            'idp_sso_url' => 'IdP SSO URL',
            'sp_entity_id' => 'SP entity ID',
            'sp_acs_url' => 'SP ACS URL',
            'idp_certificate' => 'IdP X.509-certifikat',
            'issuer' => 'Issuer',
            'client_id' => 'Klient-ID',
            'client_secret' => 'Klienthemlighet',
            'signing_key' => 'Signeringsnyckel',
            'create' => 'Skapa anslutning',
            'cancel' => 'Avbryt',
            'created' => 'Anslutningen har skapats som utkast.',
            'activated' => 'Anslutningen har aktiverats.',
            'discovery_failed' => 'Det gick inte att läsa leverantörens OpenID-konfiguration — kontrollera issuer-URL. (:reason)',
        ],

        'directory' => [
            'step' => 'Katalogsynkronisering',
            'heading' => 'Katalogsynkronisering (SCIM)',
            'new' => 'Ny katalog',
            'base_url_help' => 'Ange den här bas-URL i din identitetsleverantör (Okta, Microsoft Entra) och autentisera med katalogens bearer-token.',
            'copy_base_url' => 'Kopiera SCIM-bas-URL',
            'token_heading' => 'Bearer-token för ”:name”',
            'token_once' => 'Kopiera den nu — den visas bara en gång och kan inte hämtas igen.',
            'copy_token' => 'Kopiera token',
            'name_label' => 'Katalogens namn',
            'register' => 'Registrera katalog',
            'cancel' => 'Avbryt',
            'empty' => 'Inga kataloger har anslutits ännu.',
            'active' => 'Aktiv',
            'paused' => 'Pausad',
        ],
    ],

    // The organization's audit events, under a link that covers `audit_logs` — read-only.
    'audit_logs' => [
        'title' => 'Granskningsloggar',
        'heading' => 'Granskningsloggar',
        'heading_for' => 'Granskningsloggar · :organization',
        'description' => 'Det som applikationen har registrerat om din organisation, nyast först. Vyn är skrivskyddad: inget här kan ändras.',
        'action' => 'Händelse',
        'actor' => 'Aktörs-ID',
        'target' => 'Mål-ID',
        'from' => 'Från',
        'to' => 'Till',
        'filter' => 'Filtrera',
        'clear' => 'Rensa filter',
        'export' => 'Exportera CSV',
        'export_note' => 'CSV-filen innehåller de nyaste matchande händelserna, högst :limit.',
        'empty' => 'Inga händelser matchar dessa filter.',
        'empty_none' => 'Inga händelser har registrerats för den här organisationen ännu.',
        'time' => 'Tidpunkt',
        'targets' => 'Mål',
        'location' => 'Plats',
        'metadata' => 'Detaljer',
        'newer' => 'Nyaste händelser',
        'older' => 'Äldre händelser',
        'done' => 'Klar',
        'count' => ':count händelser på den här sidan',
    ],

    'done' => [
        'title' => 'Klart',
        'heading' => 'Klart',
        'body' => 'Inloggning för företag är konfigurerad för :organization. Den här konfigurationslänken har nu använts och är stängd. Du kan stänga det här fönstret.',
        // For a link that only read the audit logs: nothing was configured.
        'audit_logs_body' => 'Du har granskat granskningsloggarna för :organization. Länken är nu använd och stängd. Du kan stänga det här fönstret.',
        'this_organization' => 'den här organisationen',
    ],

    'expired' => [
        'title' => 'Länken är inte tillgänglig',
        'heading' => 'Den här konfigurationslänken är inte längre giltig',
        'body' => 'Länken kan ha gått ut eller redan ha använts. Av säkerhetsskäl kan konfigurationslänkar bara användas en gång och har en tidsgräns. Be personen som bjöd in dig att skicka en ny.',
    ],
];
