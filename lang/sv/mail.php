<?php

declare(strict_types=1);

// Swedish: the mail the hosted flows send (see lang/en/mail.php).
return [
    'layout' => [
        'footer' => '© :year :brand · Det här är ett automatiskt meddelande från din identitetsplattform.',
    ],

    'common' => [
        'paste_link' => 'Eller klistra in den här länken i webbläsaren:',
    ],

    'roles' => [
        'owner' => 'Ägare',
        'admin' => 'Administratör',
        'developer' => 'Utvecklare',
        'member' => 'Medlem',
        'viewer' => 'Läsare',
    ],

    'magic_link' => [
        'subject' => 'Din inloggningslänk till :brand',
        'heading' => 'Logga in på :brand',
        'body' => 'Klicka på knappen nedan för att logga in. Länken kan bara användas en gång och går ut om 15 minuter. Om du inte har begärt den kan du lugnt ignorera det här mejlet.',
        'button' => 'Logga in på :brand',
    ],

    'password_reset' => [
        'subject' => 'Återställ ditt lösenord för :brand',
        'heading' => 'Återställ ditt lösenord',
        'body' => 'Vi har fått en begäran om att återställa ditt lösenord för :brand. Klicka på knappen nedan för att välja ett nytt. Länken kan bara användas en gång och går ut om 60 minuter. Om du inte har begärt det kan du lugnt ignorera det här mejlet — ditt lösenord ändras inte.',
        'button' => 'Återställ lösenord',
    ],

    'email_verification' => [
        'subject' => 'Bekräfta din e-postadress för :brand',
        'heading' => 'Bekräfta din e-postadress',
        'body' => 'Välkommen till :brand. Bekräfta att det här är din e-postadress för att slutföra säkringen av ditt konto. Länken kan bara användas en gång och går ut om 24 timmar.',
        'button' => 'Bekräfta e-postadress',
    ],

    'admin_assigned_password' => [
        'subject' => 'Ditt lösenord för :brand har återställts',
        'heading' => 'Ditt lösenord har återställts',
        'body' => 'En administratör har angett ett nytt lösenord för ditt konto. Logga in med det nedan.',
        'temporary' => 'Du kommer att ombes välja ett eget lösenord direkt.',
        'expires' => 'Lösenordet slutar fungera :date, så logga in innan dess.',
        'not_expected' => 'Om du inte väntade dig det här, kontakta din administratör — någon med åtkomst till din organisations konsol har gjort ändringen, och den har registrerats i granskningsloggen.',
    ],

    'invitation' => [
        'subject' => ':inviter har bjudit in dig att gå med i :organization',
        'heading' => 'Gå med i :organization',
        'invited' => ':inviter har bjudit in dig att gå med i :organization.',
        'invited_as' => ':inviter har bjudit in dig att gå med i :organization som :role.',
        'accept_app' => 'Acceptera för att logga in på :app med ditt konto hos :organization.',
        'accept' => 'Acceptera för att konfigurera ditt konto och logga in.',
        'button' => 'Visa inbjudan',
        'note' => 'Länken öppnar en sida där du bekräftar — ingenting händer förrän du gör det. Den går ut om 7 dagar. Om du inte väntade dig det här kan du ignorera det.',
    ],

    'organization_invite' => [
        'subject' => ':inviter har bjudit in dig att administrera :organization på :brand',
        'heading' => 'Hjälp till att administrera :organization på :brand',
        'invited' => ':inviter har bjudit in dig att administrera :organization på :brand — konsolen för organisationens identitetsleverantörer: miljöer, medlemmar och fakturering. Acceptera för att välja ett lösenord och logga in.',
        'invited_as' => ':inviter har bjudit in dig att administrera :organization som :role på :brand — konsolen för organisationens identitetsleverantörer: miljöer, medlemmar och fakturering. Acceptera för att välja ett lösenord och logga in.',
        'button' => 'Acceptera inbjudan',
    ],
];
