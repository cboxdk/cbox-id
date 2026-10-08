<?php

declare(strict_types=1);

// Danish: the mail the hosted flows send (see lang/en/mail.php). `:brand` is the configured product name.
return [
    'layout' => [
        'footer' => '© :year :brand · Dette er en automatisk besked fra din identitetsplatform.',
    ],

    'common' => [
        'paste_link' => 'Eller indsæt dette link i din browser:',
    ],

    'roles' => [
        'owner' => 'Ejer',
        'admin' => 'Administrator',
        'developer' => 'Udvikler',
        'member' => 'Medlem',
        'viewer' => 'Læser',
    ],

    'magic_link' => [
        'subject' => 'Dit loginlink til :brand',
        'heading' => 'Log ind på :brand',
        'body' => 'Klik på knappen herunder for at logge ind. Linket kan kun bruges én gang og udløber om 15 minutter. Hvis du ikke har bedt om det, kan du roligt ignorere denne e-mail.',
        'button' => 'Log ind på :brand',
    ],

    'password_reset' => [
        'subject' => 'Nulstil din adgangskode til :brand',
        'heading' => 'Nulstil din adgangskode',
        'body' => 'Vi har modtaget en anmodning om at nulstille din adgangskode til :brand. Klik på knappen herunder for at vælge en ny. Linket kan kun bruges én gang og udløber om 60 minutter. Hvis du ikke har bedt om det, kan du roligt ignorere denne e-mail. Din adgangskode bliver ikke ændret.',
        'button' => 'Nulstil adgangskode',
    ],

    'email_verification' => [
        'subject' => 'Bekræft din e-mailadresse til :brand',
        'heading' => 'Bekræft din e-mail',
        'body' => 'Velkommen til :brand. Bekræft at dette er din e-mailadresse for at gøre sikringen af din konto færdig. Linket kan kun bruges én gang og udløber om 24 timer.',
        'button' => 'Bekræft e-mailadresse',
    ],

    'admin_assigned_password' => [
        'subject' => 'Din adgangskode til :brand er blevet nulstillet',
        'heading' => 'Din adgangskode er blevet nulstillet',
        'body' => 'En administrator har angivet en ny adgangskode til din konto. Log ind med den herunder.',
        'temporary' => 'Du bliver bedt om at vælge din egen adgangskode med det samme.',
        'expires' => 'Adgangskoden holder op med at virke :date, så log ind inden da.',
        'not_expected' => 'Hvis du ikke havde ventet dette, så kontakt din administrator. Ændringen er foretaget af en person med adgang til din organisations konsol, og den er registreret i revisionsloggen.',
    ],

    'invitation' => [
        'subject' => ':inviter har inviteret dig til :organization',
        'heading' => 'Bliv medlem af :organization',
        'invited' => ':inviter har inviteret dig til at blive medlem af :organization.',
        'invited_as' => ':inviter har inviteret dig til at blive medlem af :organization med rollen :role.',
        'accept_app' => 'Accepter for at logge ind på :app med din konto i :organization.',
        'accept' => 'Accepter for at oprette din konto og logge ind.',
        'button' => 'Se invitationen',
        'note' => 'Linket åbner en side hvor du skal bekræfte. Der sker ingenting før du gør det. Linket udløber om 7 dage. Hvis du ikke havde ventet invitationen, kan du ignorere den.',
    ],

    'organization_invite' => [
        'subject' => ':inviter har inviteret dig til at administrere :organization på :brand',
        'heading' => 'Vær med til at administrere :organization på :brand',
        'invited' => ':inviter har inviteret dig til at administrere :organization på :brand — konsollen til organisationens identitetsudbydere med miljøer, medlemmer og fakturering. Accepter for at vælge en adgangskode og logge ind.',
        'invited_as' => ':inviter har inviteret dig til at administrere :organization med rollen :role på :brand — konsollen til organisationens identitetsudbydere med miljøer, medlemmer og fakturering. Accepter for at vælge en adgangskode og logge ind.',
        'button' => 'Accepter invitation',
    ],

    'portal_link' => [
        'subject' => 'Sæt :organization op på :brand',
        'heading' => 'Sæt :organization op',
        'lead' => 'Du er blevet bedt om at sætte følgende op for :organization på :brand. Du behøver ikke en konto — knappen herunder er alt, hvad der skal til.',
        'intents' => [
            'sso' => 'Single sign-on med jeres identitetsudbyder',
            'dsync' => 'Katalogsynkronisering (SCIM)',
            'domain_verification' => 'Bekræftelse af jeres e-maildomæner',
            'log_streams' => 'Streaming af revisionsloggen til jeres SIEM',
            'certificate_renewal' => 'Fornyelse af jeres SAML-signeringscertifikat',
        ],
        'button' => 'Start opsætningen',
        'expires' => 'Linket kan bruges én gang og virker til :date. Hvis det udløber, så bed om et nyt.',
    ],

    'certificate_expiring' => [
        'subject' => 'Single sign-on via :connection holder op med at virke om :count dag|Single sign-on via :connection holder op med at virke om :count dage',
        'subject_expired' => 'Single sign-on via :connection er holdt op med at virke',
        'heading' => 'Jeres SAML-certifikat udløber snart',
        'heading_expired' => 'Jeres SAML-certifikat er udløbet',
        'lead' => 'Signeringscertifikatet for :connection, single sign-on-forbindelsen for :organization, udløber :date. Derefter kan ingen logge ind gennem den.',
        'lead_expired' => 'Signeringscertifikatet for :connection, single sign-on-forbindelsen for :organization, udløb :date. Ingen kan logge ind gennem den, før certifikatet er fornyet.',
        'what_to_do' => 'Bed den, der administrerer jeres identitetsudbyder, om det nye signeringscertifikat, og upload det derefter i jeres administrationskonsol — eller bed jeres administrator om et link til Admin Portal, så du kan uploade det der.',
        'why' => 'Du modtager denne e-mail, fordi du er ejer eller administrator af denne organisation på :brand.',
    ],
];
