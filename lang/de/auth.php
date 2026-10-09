<?php

declare(strict_types=1);

// German: the sign-in group (pages/auth/*), its validation and flash messages.
return [
    'common' => [
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'new_password' => 'Neues Passwort',
        'confirm_new_password' => 'Neues Passwort bestätigen',
        'sign_in' => 'Anmelden',
        'back_to_sign_in' => 'Zurück zur Anmeldung',
        'check_your_inbox' => 'Prüfen Sie Ihren Posteingang',
        'verify' => 'Bestätigen',
        'cancel_and_sign_out' => 'Abbrechen und abmelden',
        'too_many_attempts' => 'Zu viele Versuche. Versuchen Sie es in :count Sekunde erneut.|Zu viele Versuche. Versuchen Sie es in :count Sekunden erneut.',
        'too_many_requests' => 'Zu viele Anfragen. Versuchen Sie es in :count Sekunde erneut.|Zu viele Anfragen. Versuchen Sie es in :count Sekunden erneut.',
        'could_not_process' => 'Diese Anfrage konnte nicht verarbeitet werden. Bitte versuchen Sie es später erneut.',
        'code_incorrect' => 'Dieser Code ist falsch oder abgelaufen.',
    ],

    'password_field' => [
        'show' => 'Passwort anzeigen',
        'hide' => 'Passwort ausblenden',
        'policy' => 'Mindestens :count Zeichen',
    ],

    'login' => [
        'title' => 'Anmelden',
        'purpose' => [
            'default' => 'Willkommen zurück. Greifen Sie auf die Identitätskonsole Ihrer Organisation zu.',
            'device' => 'Melden Sie sich an, um das wartende Gerät zu genehmigen.',
        ],
        'pending_link' => [
            'lead' => 'Jemand hat sich mit :provider und dieser E-Mail-Adresse angemeldet.',
            'body' => 'Für diese E-Mail-Adresse gibt es hier bereits ein Konto. Melden Sie sich unten an, dann fragen wir Sie, ob Sie :provider damit verbinden möchten.',
        ],
        'magic' => [
            'sent_to' => 'Wir haben einen Anmeldelink zur einmaligen Verwendung an :email gesendet.',
            'dev_note' => 'Wird angezeigt, weil in dieser Umgebung kein E-Mail-Versand konfiguriert ist.',
        ],
        'mandate' => [
            // :organization may be the fallback "Ihre Organisation" — keep it in nominative/accusative.
            'heading' => ':organization erfordert Single Sign-On',
            'continue' => 'Über :organization anmelden',
            'no_provider' => 'Für :organization ist noch kein Identitätsanbieter verbunden, daher können wir Sie nirgendwohin weiterleiten. Bitten Sie einen Administrator, die Einrichtung von Single Sign-On abzuschließen.',
            'your_organization' => 'Ihre Organisation',
            'reasons' => [
                'password' => 'Ihr Passwort ist korrekt – hier ist die Anmeldung mit Passwort jedoch nicht mehr möglich. Melden Sie sich stattdessen über den Identitätsanbieter Ihrer Organisation an.',
                'magic_link' => 'Dieser Anmeldelink hat funktioniert und ist jetzt verbraucht. Die Anmeldung über per E-Mail gesendete Links ist hier nicht mehr möglich – melden Sie sich stattdessen über den Identitätsanbieter Ihrer Organisation an.',
                'passkey' => 'Ihr Passkey hat funktioniert. Hier ist die Anmeldung mit Passkey jedoch nicht mehr möglich – melden Sie sich stattdessen über den Identitätsanbieter Ihrer Organisation an.',
                'social' => 'Diese Anmeldung hat funktioniert, aber das ist nicht der Identitätsanbieter, den Ihre Organisation festgelegt hat. Melden Sie sich stattdessen über deren Identitätsanbieter an.',
                'invitation' => 'Ihre Einladung ist angenommen, und Sie sind jetzt Mitglied. Melden Sie sich über den Identitätsanbieter Ihrer Organisation an, um loszulegen.',
                'password_reset' => 'Ihr neues Passwort ist gespeichert, aber die Anmeldung mit Passwort ist hier nicht mehr möglich. Melden Sie sich stattdessen über den Identitätsanbieter Ihrer Organisation an.',
            ],
        ],
        'use_different_email' => 'Andere E-Mail-Adresse verwenden',
        'continue' => 'Weiter',
        'sso' => [
            'continue' => 'Weiter mit Single Sign-On',
            'or_password' => 'oder Ihr Passwort verwenden',
            'instead' => 'Stattdessen mit Single Sign-On fortfahren',
        ],
        'forgot_password' => 'Passwort vergessen?',
        'or' => 'ODER',
        'continue_with' => 'Weiter mit :provider',
        'magic_link' => 'Anmeldelink per E-Mail senden',
        'passkey' => 'Mit Passkey anmelden',
        'passkey_failed' => 'Die Anmeldung mit Passkey ist fehlgeschlagen.',
        'new_organization' => 'Neue Organisation?',
        'create_one' => 'Jetzt erstellen',
        'invalid_credentials' => 'Diese Anmeldedaten stimmen nicht mit unseren Unterlagen überein.',
        'social' => [
            'failed' => 'Die Anmeldung mit :provider wurde abgebrochen oder ist fehlgeschlagen.',
            'unavailable' => 'Die Anmeldung mit :provider ist derzeit nicht verfügbar.',
        ],
        'passkey_errors' => [
            'challenge_expired' => 'Die Anmeldeanfrage ist abgelaufen. Versuchen Sie es erneut.',
            'not_registered' => 'Dieser Passkey ist nicht registriert.',
            'cloned' => 'Dieser Passkey wurde möglicherweise geklont und deshalb abgelehnt.',
            'unverified' => 'Dieser Passkey konnte nicht verifiziert werden.',
            'failed' => 'Bei der Anmeldung ist ein Fehler aufgetreten.',
        ],
    ],

    'signup' => [
        'title' => 'Jetzt loslegen',
        'heading' => [
            'creates_idp' => 'Arbeitsbereich erstellen',
            'for_app' => 'Konto erstellen',
            'default' => 'Organisation erstellen',
        ],
        'lead' => [
            'creates_idp' => 'Ein Arbeitsbereich für Ihr Unternehmen und Ihr eigener gehosteter Identitätsanbieter – SSO, Benutzer und Anmeldung vollständig unter Ihrer Kontrolle, in einer Minute einsatzbereit.',
            'join' => 'Registrieren Sie sich bei :name. Sie werden Inhaber Ihres Teams und können anschließend weitere Personen einladen.',
            'default' => 'Richten Sie Cbox ID in weniger als einer Minute für Ihr Team ein.',
        ],
        'organization_label' => [
            'creates_idp' => 'Name des Arbeitsbereichs',
            'for_app' => 'Team- oder Firmenname',
            'default' => 'Name der Organisation',
        ],
        'organization_placeholder' => 'Acme GmbH',
        'name_label' => 'Ihr Name',
        'name_placeholder' => 'Erika Mustermann',
        'email_label' => 'Geschäftliche E-Mail-Adresse',
        'breach_note' => 'Wird mit bekannten Datenlecks abgeglichen.',
        'submit' => [
            'creates_idp' => 'Arbeitsbereich erstellen',
            'for_app' => 'Konto erstellen und fortfahren',
            'default' => 'Organisation erstellen',
        ],
        'have_account' => 'Sie haben bereits ein Konto?',
        'complete_verification' => 'Bitte schließen Sie die Überprüfung unten ab und senden Sie das Formular dann erneut.',
        'sso_required' => 'Ihre Organisation erfordert die Anmeldung über SSO.',
        'account_exists' => 'Für diese E-Mail-Adresse existiert bereits ein Konto.',
        'closed' => [
            'tenant' => 'Für den Beitritt benötigen Sie eine Einladung. Bitten Sie die Person, die Ihr Team verwaltet, darum.',
            'invite_only' => 'Die Registrierung ist nur mit Einladung möglich. Bitten Sie einen Administrator um eine Einladung.',
            'closed' => 'Die Registrierung ist derzeit geschlossen.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Passwort zurücksetzen',
        'heading' => 'Passwort zurücksetzen',
        'lead' => 'Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen dann einen Link zum Zurücksetzen.',
        'sent_to' => 'Falls ein Konto für :email existiert, ist ein Link zum Zurücksetzen unterwegs.',
        'submit' => 'Link senden',
        'remembered' => 'Wieder eingefallen?',
        'throttled' => 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es dann erneut.',
    ],

    'reset_password' => [
        'title' => 'Neues Passwort festlegen',
        'lead' => 'Wählen Sie ein sicheres Passwort mit mindestens :count Zeichen.',
        'confirm_placeholder' => 'Neues Passwort erneut eingeben',
        'submit' => 'Passwort zurücksetzen',
        'invalid_link' => 'Dieser Link zum Zurücksetzen ist ungültig oder abgelaufen. Fordern Sie einen neuen an.',
        'done' => 'Ihr Passwort wurde zurückgesetzt – melden Sie sich mit Ihrem neuen Passwort an.',
    ],

    'change_password' => [
        'title' => 'Neues Passwort festlegen',
        'lead' => 'Das Passwort, mit dem Sie sich angemeldet haben, wurde von einem Administrator vergeben. Legen Sie ein Passwort fest, das nur Sie kennen, bevor Sie fortfahren.',
        'submit' => 'Passwort ändern',
        'mismatch' => 'Die Passwörter stimmen nicht überein.',
    ],

    'mfa' => [
        'title' => 'Zwei-Faktor-Authentifizierung',
        'code' => [
            'lead' => 'Geben Sie den 6-stelligen Code aus Ihrer Authentifizierungs-App ein.',
            'label' => 'Authentifizierungscode',
            'switch' => 'Stattdessen einen Wiederherstellungscode verwenden',
            'sms_switch' => 'Stattdessen einen Code per SMS erhalten',
        ],
        'recovery' => [
            'lead' => 'Geben Sie einen der Wiederherstellungscodes ein, die Sie beim Aktivieren der Zwei-Faktor-Authentifizierung gespeichert haben.',
            'label' => 'Wiederherstellungscode',
            'submit' => 'Code bestätigen',
            'switch' => 'Stattdessen Ihre Authentifizierungs-App verwenden',
            'invalid' => 'Dieser Wiederherstellungscode ist ungültig oder wurde bereits verwendet.',
            'back' => 'Eine andere Methode verwenden',
        ],
        'sms' => [
            'lead' => 'Wir senden einen Code per SMS an die Telefonnummer Ihres Kontos.',
            'send' => 'Code per SMS senden',
            'sent' => 'Wir haben einen Code an :number gesendet. Er läuft in wenigen Minuten ab.',
            'resend' => 'Neuen Code senden',
            'label' => 'Code aus der SMS',
            'switch' => 'Stattdessen Ihre Authentifizierungs-App verwenden',
            'wait' => 'Es wurde gerade ein Code gesendet. Warten Sie einen Moment, bevor Sie einen neuen anfordern.',
            'failed' => 'Die SMS konnte nicht gesendet werden. Versuchen Sie es gleich noch einmal oder verwenden Sie einen Wiederherstellungscode.',
            'unavailable' => 'SMS-Codes sind für dieses Konto nicht verfügbar.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Zusätzliche Bestätigung',
        'lead' => 'Diese Anmeldung wirkte ungewöhnlich, daher haben wir einen Einmalcode an :email gesendet. Geben Sie ihn ein, um fortzufahren.',
        'code_label' => 'Bestätigungscode',
        'resend' => 'Keinen Code erhalten? Erneut senden',
        'resent' => 'Wir haben einen neuen Code an :email gesendet.',
        'too_many_codes' => 'Zu viele Codes angefordert. Bitte warten Sie einen Moment und versuchen Sie es dann erneut.',
    ],

    'accounts' => [
        'title' => 'Benutzer wechseln',
        'lead' => 'Alle, die auf diesem Gerät angemeldet sind. Wählen Sie ein Konto aus, oder melden Sie sich als andere Person an.',
        'active' => 'Aktiv',
        'add' => 'Als andere Person anmelden',
    ],

    'accept_invite' => [
        'title' => 'Einladung annehmen',
        'heading' => 'Ihre Einladung annehmen',
        'lead_from' => ':inviter hat Sie eingeladen, :organization als :role mitzuverwalten. Sie melden sich als :email an.',
        'lead' => 'Legen Sie ein Passwort fest, um :organization als :role mitzuverwalten. Sie melden sich als :email an.',
        'organization_fallback' => 'die Organisation',
        'password_label' => 'Passwort wählen',
        'breach_note' => 'Wird mit bekannten Datenlecks abgeglichen.',
        'submit' => 'Annehmen und anmelden',
        'no_longer_valid' => 'Diese Einladung ist nicht mehr gültig. Versuchen Sie, sich anzumelden.',
        'invalid' => 'Diese Einladung ist ungültig oder abgelaufen.',
        'not_completed' => 'Diese Einladung konnte nicht abgeschlossen werden.',
    ],

    'confirm_email' => [
        'title' => 'E-Mail-Adresse bestätigen',
        'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
        'lead' => 'Sie haben den Bestätigungslink geöffnet, den wir Ihnen gesendet haben. Bestätigen Sie, um die Verifizierung dieser Adresse abzuschließen.',
        'action' => 'E-Mail-Adresse bestätigen',
        'invalid' => 'Dieser Bestätigungslink ist ungültig oder abgelaufen.',
        'verified_sign_in' => 'E-Mail-Adresse bestätigt – melden Sie sich an, um Ihre Umgebung zu öffnen.',
        'verified' => 'Ihre E-Mail-Adresse ist bestätigt – Sie können sich jetzt anmelden.',
    ],

    'confirm_sign_in' => [
        'title' => 'Anmelden',
        'heading' => 'Anmeldung abschließen',
        'lead' => 'Sie haben einen Anmeldelink geöffnet. Fahren Sie fort, um sich auf diesem Gerät anzumelden.',
        'action' => 'Anmelden',
        'note' => 'Der Link funktioniert nur einmal. Wenn Sie keine Anmeldung angefordert haben, schließen Sie diese Seite – es passiert nichts, bis Sie auf die Schaltfläche klicken.',
        'invalid' => 'Dieser Anmeldelink ist ungültig oder abgelaufen.',
    ],

    'first_run' => [
        'title' => 'Cbox ID einrichten',
        'lead' => 'Diese Bereitstellung ist leer. Übernehmen Sie sie einmalig – von dem Rechner aus, auf dem sie läuft.',
        'unmigrated' => [
            'title' => 'Die Datenbank dieser Bereitstellung hat noch kein Schema.',
            'body' => 'Führen Sie :migrate auf dem Server aus (oder :install, das in einem Schritt migriert und installiert) und laden Sie diese Seite dann neu.',
        ],
        'misconfigured' => [
            'title' => 'Diese Bereitstellung ist als mandantenfähig konfiguriert, hat aber keinen Konto-Host.',
            'body' => 'Legen Sie :console_host fest (wo die Konsole liegt) oder für eine Installation mit nur einem Host :single_host – und laden Sie diese Seite dann neu. Sie können auch :install ausführen; der Befehl fragt beides ab und schreibt die Werte für Sie.',
        ],
        'token_notice' => [
            'title' => 'Wo finden Sie das Einrichtungstoken?',
            'body' => 'Führen Sie :command auf dem Server aus — auf einer beliebigen Instanz dieser Bereitstellung — und fügen Sie die Ausgabe ein. Jeder Aufruf gibt ein neues Token aus, das eine Stunde gültig ist. Es wird auf dieser Seite niemals angezeigt.',
        ],
        'cli_hint' => 'Lieber über die Befehlszeile? :install macht dasselbe und ist der einzige Weg, auf dem auch die Art der Bereitstellung gewählt und gespeichert werden kann.',
        'token_label' => 'Einrichtungstoken',
        'token_placeholder' => 'Token vom Server einfügen',
        'name_label' => 'Ihr Name',
        'name_placeholder' => 'Root-Operator',
        'email_label' => 'Ihre E-Mail-Adresse',
        'environment_label' => 'Benennen Sie Ihre erste Umgebung',
        'environment_hint' => 'Eine Umgebung ist die harte Isolationsgrenze – mit eigenen Benutzern, eigenen Schlüsseln und eigenem Issuer.',
        'environment_default' => 'Produktion',
        'organization_label' => 'Name der Organisation',
        'organization_hint' => 'Diese Bereitstellung ist als mandantenfähig konfiguriert, daher erstellt die Installation auch den ersten Arbeitsbereich – die Organisation, der die Umgebungen und die Abrechnung gehören.',
        'organization_placeholder' => 'Ihr Unternehmen',
        'submit' => 'Bereitstellung installieren',
        'token_mismatch' => 'Dieses Einrichtungstoken passt nicht zu dieser Bereitstellung oder ist abgelaufen. Geben Sie mit php artisan cbox-id:setup-token ein neues aus.',
    ],

    'join_organization' => [
        'title' => ':organization beitreten',
        'heading' => ':organization beitreten?',
        'lead' => 'Sie wurden eingeladen, dieser Organisation beizutreten. Nehmen Sie die Einladung an, um Mitglied zu werden und sich anzumelden.',
        'lead_app' => 'Sie wurden eingeladen, dieser Organisation beizutreten. Nehmen Sie die Einladung an, um Mitglied zu werden – anschließend leiten wir Sie zu :app weiter.',
        'action' => 'Einladung annehmen',
        'note' => 'Sie haben nicht damit gerechnet? Schließen Sie diese Seite – es passiert nichts, solange Sie nicht annehmen.',
        'facts' => [
            'organization' => 'Organisation',
            'invited_by' => 'Eingeladen von',
            'built_in_role' => 'Integrierte Rolle',
            'custom_roles' => 'Benutzerdefinierte Rollen',
            'app_roles' => 'Rollen in :app',
            'email' => 'Ihre E-Mail-Adresse',
            'app' => 'App',
        ],
        'invalid' => 'Diese Einladung ist ungültig oder abgelaufen.',
    ],

    'link_confirm' => [
        'title' => 'Konto verbinden',
        'heading' => ':provider verbinden?',
        'lead' => 'Gerade hat sich jemand mit :provider angemeldet – mit einer Adresse, die bereits zu Ihrem Konto gehört.',
        'lead_email' => 'Gerade hat sich jemand mit :provider als :email angemeldet – mit einer Adresse, die bereits zu Ihrem Konto gehört.',
        // :emphasis is the bold opening phrase below.
        'was_you' => ':emphasis, verbinden Sie das Konto. Dann können Sie sich künftig mit :provider oder mit Ihrem Passwort anmelden.',
        'was_you_emphasis' => 'Wenn Sie das waren',
        'was_not_you' => ':emphasis, lehnen Sie ab. Jemand anderes hat versucht, sich mit Ihrer E-Mail-Adresse anzumelden. Ihrem Konto wird nichts hinzugefügt, und Ihr Passwort funktioniert weiterhin wie bisher.',
        'was_not_you_emphasis' => 'Wenn nicht',
        'decline' => 'Nein, das war ich nicht',
        'connect' => 'Ja, :provider verbinden',
        'disconnect_hint' => 'Sie können :provider jederzeit in den Sicherheitseinstellungen Ihres Kontos trennen.',
    ],

    'open_portal_setup' => [
        'title' => 'Admin-Einrichtung',
        'heading' => 'Anmeldung für Ihre Organisation einrichten',
        'lead' => 'Sie haben einen Einrichtungslink für Ihre Organisation erhalten — Single Sign-On, Verzeichnissynchronisierung, Domains, Log-Streams oder eine Zertifikatserneuerung. Fahren Sie fort, um die Einrichtung zu öffnen.',
        'action' => 'Einrichtung öffnen',
        'note' => 'Der Link funktioniert nur einmal, und die damit geöffnete Einrichtungssitzung läuft ab. Öffnen Sie ihn erst, wenn Sie bereit sind, die Einrichtung abzuschließen.',
    ],
];
