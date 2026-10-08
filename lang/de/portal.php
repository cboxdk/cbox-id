<?php

declare(strict_types=1);

// German: the admin setup portal (pages/portal/*). Protocol terms stay in English.
return [
    'layout' => [
        'badge' => 'Admin-Einrichtungsportal',
        'toggle_theme' => 'Design wechseln',
    ],

    'copy' => [
        'copy' => 'Kopieren',
        'copied' => 'Kopiert',
        'failed' => 'Kopieren fehlgeschlagen – bitte manuell markieren und kopieren',
    ],

    'confirm' => [
        'cancel' => 'Abbrechen',
        'type_to_confirm' => 'Geben Sie zur Bestätigung :name ein',
        'hint' => 'Genau wie angezeigt – :action bleibt deaktiviert, bis die Eingabe übereinstimmt.',
    ],

    'setup' => [
        'title' => 'SSO und SCIM einrichten',
        'heading' => 'Unternehmensanmeldung einrichten',
        'heading_for' => 'Unternehmensanmeldung einrichten · :organization',
        'description' => 'Sie wurden eingeladen, Single Sign-On (SSO) für diese Organisation zu konfigurieren. Auf andere Bereiche der Organisation können Sie von hier aus nicht zugreifen.',
        'step' => 'Schritt :number',
        'finish' => 'Einrichtung abschließen',

        'domain' => [
            'heading' => 'Domain verifizieren',
            'lead' => 'Fügen Sie einen DNS-Eintrag hinzu, um nachzuweisen, dass Ihnen die Domain gehört, mit der sich Ihr Team anmeldet. Dadurch werden diese Benutzer zu SSO weitergeleitet.',
            'label' => 'Domain',
            'add' => 'Domain hinzufügen',
            'record' => 'Fügen Sie diesen TXT-Eintrag für :domain hinzu und klicken Sie dann auf „Prüfen“.',
            'record_type' => 'Typ',
            'record_host' => 'Host',
            'record_value' => 'Wert',
            'empty' => 'Noch keine Domains hinzugefügt.',
            'verified' => 'Verifiziert',
            'pending' => 'DNS ausstehend',
            'check' => 'Prüfen',
            'check_label' => 'DNS für :domain prüfen',
            'remove' => 'Entfernen',
            'remove_label' => ':domain entfernen',
            'remove_title' => ':domain entfernen?',
            'remove_consequence' => 'Wer sich mit einer Adresse dieser Domain anmeldet, wird nicht mehr hierher weitergeleitet.',
            'invalid' => 'Geben Sie eine gültige Domain ein, z. B. acme.com.',
            'claimed' => 'Diese Domain wird bereits von einer anderen Organisation beansprucht.',
            'verified_status' => 'Domain verifiziert – Benutzer dieser Domain können sich jetzt per SSO anmelden.',
            'not_found' => 'Der TXT-Eintrag wurde noch nicht gefunden – es kann einige Minuten dauern, bis DNS-Änderungen übernommen sind.',
            'removed' => 'Domain entfernt.',
        ],

        'connection' => [
            'heading' => 'SSO-Verbindung',
            'new' => 'Neue Verbindung',
            'empty' => 'Noch keine SSO-Verbindungen.',
            'active' => 'Aktiv',
            'statuses' => [
                'draft' => 'Entwurf',
                'inactive' => 'inaktiv',
            ],
            'activate' => 'Aktivieren',
            'activate_label' => ':name aktivieren',
            'name_label' => 'Name der Verbindung',
            'protocol_label' => 'Protokoll',
            'idp_entity_id' => 'IdP Entity ID',
            'idp_sso_url' => 'IdP SSO URL',
            'sp_entity_id' => 'SP Entity ID',
            'sp_acs_url' => 'SP ACS URL',
            'idp_certificate' => 'IdP-X.509-Zertifikat',
            'issuer' => 'Issuer',
            'client_id' => 'Client-ID',
            'client_secret' => 'Geheimer Clientschlüssel',
            'signing_key' => 'Signaturschlüssel',
            'create' => 'Verbindung erstellen',
            'cancel' => 'Abbrechen',
            'created' => 'Verbindung als Entwurf erstellt.',
            'activated' => 'Verbindung aktiviert.',
            // :reason is the discovery client's own message, which stays in English.
            'discovery_failed' => 'Die OpenID-Konfiguration des Anbieters konnte nicht gelesen werden – prüfen Sie die Issuer-URL. (:reason)',
        ],

        'directory' => [
            'step' => 'Verzeichnissynchronisierung',
            'heading' => 'Verzeichnissynchronisierung (SCIM)',
            'new' => 'Neues Verzeichnis',
            'base_url_help' => 'Richten Sie Ihren Identitätsanbieter (Okta, Microsoft Entra) auf diese Basis-URL aus, und authentifizieren Sie sich mit dem Bearer-Token eines Verzeichnisses.',
            'copy_base_url' => 'SCIM-Basis-URL kopieren',
            'token_heading' => 'Bearer-Token für „:name“',
            'token_once' => 'Kopieren Sie es jetzt – es wird nur einmal angezeigt und kann später nicht erneut abgerufen werden.',
            'copy_token' => 'Token kopieren',
            'name_label' => 'Name des Verzeichnisses',
            'register' => 'Verzeichnis registrieren',
            'cancel' => 'Abbrechen',
            'empty' => 'Noch keine Verzeichnisse verbunden.',
            'active' => 'Aktiv',
            'paused' => 'Pausiert',
        ],
    ],

    // The organization's audit events, under a link that covers `audit_logs` — read-only.
    'audit_logs' => [
        'title' => 'Audit-Logs',
        'heading' => 'Audit-Logs',
        'heading_for' => 'Audit-Logs · :organization',
        'description' => 'Was die Anwendung über Ihre Organisation aufgezeichnet hat, neueste zuerst. Diese Ansicht ist schreibgeschützt: Hier kann nichts geändert werden.',
        'action' => 'Aktion',
        'actor' => 'Akteur-ID',
        'target' => 'Ziel-ID',
        'from' => 'Von',
        'to' => 'Bis',
        'filter' => 'Filtern',
        'clear' => 'Filter zurücksetzen',
        'export' => 'CSV exportieren',
        'export_note' => 'Die CSV-Datei enthält die neuesten passenden Ereignisse, höchstens :limit.',
        'empty' => 'Keine Ereignisse entsprechen diesen Filtern.',
        'empty_none' => 'Für diese Organisation wurden noch keine Ereignisse aufgezeichnet.',
        'time' => 'Zeitpunkt',
        'targets' => 'Ziele',
        'location' => 'Standort',
        'metadata' => 'Details',
        'newer' => 'Neueste Ereignisse',
        'older' => 'Ältere Ereignisse',
        'done' => 'Fertig',
        'count' => ':count Ereignisse auf dieser Seite',
    ],

    'done' => [
        'title' => 'Alles erledigt',
        'heading' => 'Alles erledigt',
        'body' => 'Die Unternehmensanmeldung für :organization ist konfiguriert. Dieser Einrichtungslink wurde verwendet und ist jetzt geschlossen. Sie können dieses Fenster schließen.',
        // For a link that only read the audit logs: nothing was configured.
        'audit_logs_body' => 'Sie haben die Audit-Logs für :organization durchgesehen. Dieser Link wurde verwendet und ist jetzt geschlossen. Sie können dieses Fenster schließen.',
        // Follows "für", so it is in the accusative.
        'this_organization' => 'diese Organisation',
    ],

    'expired' => [
        'title' => 'Link nicht verfügbar',
        'heading' => 'Dieser Einrichtungslink ist nicht mehr gültig',
        'body' => 'Der Link ist möglicherweise abgelaufen oder wurde bereits verwendet. Einrichtungslinks sind aus Sicherheitsgründen nur einmal verwendbar und zeitlich begrenzt. Bitten Sie die Person, die Sie eingeladen hat, Ihnen einen neuen Link zu senden.',
    ],
];
