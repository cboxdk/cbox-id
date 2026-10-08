<?php

declare(strict_types=1);

// German: the mail the hosted flows send. :brand is the deployment's configured product name.
return [
    'layout' => [
        'footer' => '© :year :brand · Dies ist eine automatisch generierte Nachricht Ihrer Identitätsplattform.',
    ],

    'common' => [
        'paste_link' => 'Oder fügen Sie diesen Link in Ihren Browser ein:',
    ],

    'roles' => [
        'owner' => 'Inhaber',
        'admin' => 'Administrator',
        'developer' => 'Entwickler',
        'member' => 'Mitglied',
        'viewer' => 'Betrachter',
    ],

    'magic_link' => [
        'subject' => 'Ihr Anmeldelink für :brand',
        'heading' => 'Bei :brand anmelden',
        'body' => 'Klicken Sie auf die Schaltfläche unten, um sich anzumelden. Dieser Link kann nur einmal verwendet werden und läuft in 15 Minuten ab. Wenn Sie ihn nicht angefordert haben, können Sie diese E-Mail bedenkenlos ignorieren.',
        'button' => 'Bei :brand anmelden',
    ],

    'password_reset' => [
        'subject' => 'Passwort für :brand zurücksetzen',
        'heading' => 'Passwort zurücksetzen',
        'body' => 'Wir haben eine Anfrage erhalten, Ihr Passwort für :brand zurückzusetzen. Klicken Sie auf die Schaltfläche unten, um ein neues festzulegen. Dieser Link kann nur einmal verwendet werden und läuft in 60 Minuten ab. Wenn Sie ihn nicht angefordert haben, können Sie diese E-Mail bedenkenlos ignorieren – Ihr Passwort wird nicht geändert.',
        'button' => 'Passwort zurücksetzen',
    ],

    'email_verification' => [
        'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse für :brand',
        'heading' => 'E-Mail-Adresse bestätigen',
        'body' => 'Willkommen bei :brand. Bestätigen Sie, dass dies Ihre E-Mail-Adresse ist, um die Absicherung Ihres Kontos abzuschließen. Dieser Link kann nur einmal verwendet werden und läuft in 24 Stunden ab.',
        'button' => 'E-Mail-Adresse bestätigen',
    ],

    'admin_assigned_password' => [
        'subject' => 'Ihr Passwort für :brand wurde zurückgesetzt',
        'heading' => 'Ihr Passwort wurde zurückgesetzt',
        'body' => 'Ein Administrator hat ein neues Passwort für Ihr Konto festgelegt. Melden Sie sich unten damit an.',
        'temporary' => 'Sie werden sofort aufgefordert, ein eigenes Passwort festzulegen.',
        'expires' => 'Dieses Passwort funktioniert ab :date nicht mehr. Bitte melden Sie sich vorher an.',
        'not_expected' => 'Wenn Sie damit nicht gerechnet haben, wenden Sie sich an Ihren Administrator – jemand mit Zugriff auf die Konsole Ihrer Organisation hat diese Änderung vorgenommen, und sie ist im Audit-Trail protokolliert.',
    ],

    'invitation' => [
        'subject' => ':inviter hat Sie eingeladen, :organization beizutreten',
        'heading' => ':organization beitreten',
        'invited' => ':inviter hat Sie eingeladen, :organization beizutreten.',
        'invited_as' => ':inviter hat Sie eingeladen, :organization als :role beizutreten.',
        'accept_app' => 'Nehmen Sie die Einladung an, um sich mit Ihrem Konto der Organisation :organization bei :app anzumelden.',
        'accept' => 'Nehmen Sie die Einladung an, um Ihr Konto einzurichten und sich anzumelden.',
        'button' => 'Einladung ansehen',
        'note' => 'Der Link öffnet eine Seite, auf der Sie bestätigen – bis Sie das tun, passiert nichts. Der Link läuft in 7 Tagen ab. Wenn Sie damit nicht gerechnet haben, können Sie diese E-Mail ignorieren.',
    ],

    'organization_invite' => [
        'subject' => ':inviter hat Sie eingeladen, :organization in :brand zu verwalten',
        'heading' => ':organization in :brand mitverwalten',
        'invited' => ':inviter hat Sie eingeladen, :organization in :brand zu verwalten – der Konsole für die Identitätsanbieter der Organisation: Umgebungen, Mitglieder und Abrechnung. Nehmen Sie die Einladung an, um ein Passwort festzulegen und sich anzumelden.',
        'invited_as' => ':inviter hat Sie eingeladen, :organization als :role in :brand zu verwalten – der Konsole für die Identitätsanbieter der Organisation: Umgebungen, Mitglieder und Abrechnung. Nehmen Sie die Einladung an, um ein Passwort festzulegen und sich anzumelden.',
        'button' => 'Einladung annehmen',
    ],

    // An Admin Portal link, mailed to the customer's IT contact. :organization is bold in the lead.
    'portal_link' => [
        'subject' => ':organization in :brand einrichten',
        'heading' => ':organization einrichten',
        'lead' => 'Sie wurden gebeten, Folgendes für :organization in :brand einzurichten. Sie benötigen kein Konto – die Schaltfläche unten genügt.',
        'intents' => [
            'sso' => 'Single Sign-On mit Ihrem Identitätsanbieter',
            'dsync' => 'Verzeichnissynchronisierung (SCIM)',
            'domain_verification' => 'Verifizierung Ihrer E-Mail-Domains',
            'log_streams' => 'Streaming Ihres Audit-Logs an Ihr SIEM',
            'certificate_renewal' => 'Erneuerung Ihres SAML-Signaturzertifikats',
        ],
        'button' => 'Einrichtung starten',
        'expires' => 'Der Link kann einmal verwendet werden und gilt bis :date. Wenn er abläuft, fordern Sie einen neuen an.',
    ],

    // From the daily certificate scan, to an organization's owners and admins. :connection
    // and :organization are bold in the lead; :date is already in the reader's format.
    'certificate_expiring' => [
        'subject' => 'Single Sign-On über :connection funktioniert noch :count Tag|Single Sign-On über :connection funktioniert noch :count Tage',
        'subject_expired' => 'Single Sign-On über :connection funktioniert nicht mehr',
        'heading' => 'Ihr SAML-Zertifikat läuft bald ab',
        'heading_expired' => 'Ihr SAML-Zertifikat ist abgelaufen',
        'lead' => 'Das Signaturzertifikat von :connection, der Single-Sign-On-Verbindung für :organization, läuft am :date ab. Danach kann sich niemand mehr darüber anmelden.',
        'lead_expired' => 'Das Signaturzertifikat von :connection, der Single-Sign-On-Verbindung für :organization, ist am :date abgelaufen. Bis es erneuert wird, kann sich niemand darüber anmelden.',
        'what_to_do' => 'Bitten Sie die Person, die Ihren Identitätsanbieter verwaltet, um das neue Signaturzertifikat, und laden Sie es dann in Ihrer Admin-Konsole hoch – oder bitten Sie Ihren Administrator um einen Admin-Portal-Link, um es dort hochzuladen.',
        'why' => 'Sie erhalten diese E-Mail, weil Sie Inhaber oder Administrator dieser Organisation in :brand sind.',
    ],
];
