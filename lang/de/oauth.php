<?php

declare(strict_types=1);

// German: the OAuth authorization pages (pages/oauth/*) and the authorize failure page.
return [
    'signed_in_as' => 'Angemeldet als :account',
    'cancel_and_return' => 'Abbrechen und zu :client zurückkehren',

    'consent' => [
        'title' => 'Autorisieren',
        'heading' => ':client autorisieren',
        'wants_access' => ':client möchte auf Ihr Konto bei :account zugreifen.',
        'registered_by' => 'Registriert von :owner – den Namen einer App legt fest, wer sie registriert hat.',
        // Follows "von", so it is in the dative.
        'unknown_owner' => 'einer Organisation, die nicht mehr existiert',
        'in_organization' => 'In :organization',
        'will_allow' => 'Damit erlauben Sie :client Folgendes:',
        'cancel' => 'Abbrechen',
        'authorize' => 'Autorisieren',
        'redirect_notice' => 'Nach der Autorisierung werden Sie zu :host weitergeleitet.',

        'scopes' => [
            'openid' => 'Ihre Identität bestätigen',
            'profile' => 'Ihr Name',
            'email' => 'Ihre E-Mail-Adresse',
            'offline_access' => 'Angemeldet bleiben',
            'organizations' => 'Organisationen, denen Sie angehören',
            'groups' => 'Ihre Rollen',
        ],
    ],

    'failure' => [
        'title' => 'Autorisierung fehlgeschlagen',
        'heading' => 'Autorisierung fehlgeschlagen',
        'back' => 'Zurück zu :name',
        'generic' => 'Diese Autorisierungsanfrage konnte nicht abgeschlossen werden.',
        'expired' => 'Diese Autorisierungsanfrage ist abgelaufen oder wurde bereits verwendet. Bitte beginnen Sie erneut.',
        'par_required' => 'Dieser Server erfordert Pushed Authorization Requests. Senden Sie die Anfrage zuerst an /oauth/par.',
        'unknown_client' => 'Unbekannter Client. Diese Anwendung ist nicht bei Cbox ID registriert.',
        'redirect_mismatch' => 'Die Weiterleitungs-URI stimmt mit keiner der für diese Anwendung registrierten URIs überein.',
        'stale' => 'Diese Autorisierungsanfrage kann nicht mehr abgeschlossen werden. Bitte beginnen Sie erneut.',
        'account_attention' => 'Ihr Konto erfordert Ihre Aufmerksamkeit, bevor Sie fortfahren können. Bitte melden Sie sich erneut an.',
        'step_up' => 'Diese Anwendung erfordert eine aktuellere oder stärkere Anmeldung. Bitte beginnen Sie erneut.',
    ],

    'organization' => [
        'title' => 'Organisation auswählen',
        'heading' => 'Organisation auswählen',
        'lead' => ':client verwendet die von Ihnen ausgewählte Organisation zusammen mit Ihrer Rolle darin.',
        'none_create' => 'Sie gehören hier noch keiner Organisation an. Erstellen Sie eine, um fortzufahren.',
        'none_invite' => 'Sie gehören hier noch keiner Organisation an. Bitten Sie jemanden, Sie in seine Organisation einzuladen, und versuchen Sie es dann erneut.',
        'list_label' => 'Ihre Organisationen',
        'suggested' => 'Vorgeschlagen',
        'continuing_with' => 'Weiter mit :name',
        'create' => 'Organisation erstellen',
        'required' => 'Wählen Sie eine Organisation aus, um fortzufahren.',
        'not_member' => 'Sie sind kein aktives Mitglied dieser Organisation.',
        'roles' => [
            'owner' => 'Inhaber',
            'admin' => 'Administrator',
            'developer' => 'Entwickler',
            'member' => 'Mitglied',
            'viewer' => 'Betrachter',
        ],
    ],

    'create_organization' => [
        'title' => 'Organisation erstellen',
        'heading' => 'Organisation erstellen',
        'lead' => 'Ihr Team oder Unternehmen in :client. Sie werden Inhaber der Organisation und können anschließend weitere Personen einladen.',
        'name_label' => 'Name der Organisation',
        'submit' => 'Erstellen und fortfahren',
        'choose_existing' => 'Vorhandene Organisation auswählen',
        'name_required' => 'Geben Sie Ihrer Organisation einen Namen.',
        'not_offered' => 'Das Erstellen einer Organisation ist hier nicht möglich. Bitten Sie einen Administrator, Sie in eine Organisation einzuladen.',
        'too_many' => 'Sie haben in kurzer Zeit mehrere Organisationen erstellt. Versuchen Sie es in :count Minute erneut.|Sie haben in kurzer Zeit mehrere Organisationen erstellt. Versuchen Sie es in :count Minuten erneut.',
    ],
];
