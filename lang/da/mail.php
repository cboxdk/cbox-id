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
        'body' => 'Velkommen til :brand. Bekræft at dette er din e-mailadresse, så din konto er sikret. Linket kan kun bruges én gang og udløber om 24 timer.',
        'button' => 'Bekræft e-mailadresse',
        'not_you' => 'Hvis du ikke har oprettet en konto hos :brand, kan du ignorere denne e-mail — der sker ingenting uden linket.',
    ],

    'admin_assigned_password' => [
        'subject' => 'Din adgangskode til :brand er blevet nulstillet',
        'heading' => 'Din adgangskode er blevet nulstillet',
        'body' => 'En administrator har angivet en ny adgangskode til din konto. Log ind med den herunder.',
        'temporary' => 'Du bliver bedt om at vælge din egen adgangskode med det samme.',
        'expires' => 'Adgangskoden holder op med at virke :date, så log ind inden da.',
        'not_expected' => 'Hvis du ikke havde forventet dette, så kontakt din administrator. Ændringen er foretaget af en person med adgang til din organisations konsol, og den er registreret i revisionsloggen.',
    ],

    'invitation' => [
        'subject' => ':inviter har inviteret dig til :organization',
        'heading' => 'Bliv medlem af :organization',
        'invited' => ':inviter har inviteret dig til at blive medlem af :organization.',
        'invited_as' => ':inviter har inviteret dig til at blive medlem af :organization med rollen :role.',
        'accept_app' => 'Accepter for at logge ind på :app med din konto i :organization.',
        'accept' => 'Accepter for at oprette din konto og logge ind.',
        'button' => 'Se invitationen',
        'note' => 'Linket åbner en side hvor du skal bekræfte. Der sker ingenting før du gør det. Linket udløber om 7 dage. Hvis du ikke havde forventet invitationen, kan du bare ignorere den.',
    ],

    'organization_invite' => [
        'subject' => ':inviter har inviteret dig til at administrere :organization på :brand',
        'heading' => 'Vær med til at administrere :organization på :brand',
        'invited' => ':inviter har inviteret dig til at administrere :organization på :brand – konsollen til organisationens identitetsudbydere med miljøer, medlemmer og fakturering. Accepter for at vælge en adgangskode og logge ind.',
        'invited_as' => ':inviter har inviteret dig til at administrere :organization med rollen :role på :brand – konsollen til organisationens identitetsudbydere med miljøer, medlemmer og fakturering. Accepter for at vælge en adgangskode og logge ind.',
        'button' => 'Accepter invitation',
        'note' => 'Hvis du ikke havde forventet invitationen, kan du ignorere den — der sker ingenting, før du accepterer.',
    ],

    'portal_link' => [
        'subject' => 'Sæt :organization op på :brand',
        'heading' => 'Sæt :organization op',
        'lead' => 'Du er blevet bedt om at sætte følgende op for :organization på :brand. Du behøver ingen konto – knappen herunder er alt der skal til.',
        'intents' => [
            'sso' => 'Single sign-on med jeres identitetsudbyder',
            'dsync' => 'Katalogsynkronisering (SCIM)',
            'domain_verification' => 'Bekræftelse af jeres e-maildomæner',
            'log_streams' => 'Streaming af revisionsloggen til jeres SIEM',
            'certificate_renewal' => 'Fornyelse af jeres SAML-signeringscertifikat',
            'audit_logs' => 'Gennemgang af jeres revisionslog (skrivebeskyttet)',
        ],
        'button' => 'Start opsætningen',
        'expires' => 'Linket kan kun bruges én gang og virker indtil :date. Hvis det udløber, så bed om et nyt.',
        'not_expected' => 'Hvis du ikke havde forventet dette, kan du ignorere det — der bliver ikke sat noget op, før nogen bruger linket.',
    ],

    'certificate_expiring' => [
        'subject' => 'Single sign-on via :connection holder op med at virke om :count dag|Single sign-on via :connection holder op med at virke om :count dage',
        'subject_expired' => 'Single sign-on via :connection er holdt op med at virke',
        'heading' => 'Jeres SAML-certifikat udløber snart',
        'heading_expired' => 'Jeres SAML-certifikat er udløbet',
        'lead' => 'Signeringscertifikatet for :connection, single sign-on-forbindelsen for :organization, udløber den :date. Derefter kan ingen logge ind via den.',
        'lead_expired' => 'Signeringscertifikatet for :connection, single sign-on-forbindelsen for :organization, udløb den :date. Ingen kan logge ind via den, før certifikatet er fornyet.',
        'what_to_do' => 'Bed den der administrerer jeres identitetsudbyder om det nye signeringscertifikat, og upload det derefter i jeres administrationskonsol – eller bed jeres administrator om et link til Admin Portal, så du kan uploade det der.',
        'why' => 'Du modtager denne e-mail fordi du er ejer eller administrator af organisationen på :brand.',
    ],

    'one_time_code' => [
        'label' => 'Din kode',
        'expires' => 'Den udløber om :count minut og kan kun bruges én gang.|Den udløber om :count minutter og kan kun bruges én gang.',
        'never_share' => 'Del den ikke med nogen — :brand beder dig aldrig om den i telefonen, på chat eller pr. e-mail.',
        'not_you_title' => 'Har du ikke bedt om den?',
        'sign_in' => [
            'subject' => 'Din loginkode til :brand',
            'heading' => 'Bekræft at det er dig',
            'lead' => 'Indtast denne kode for at gøre dit login på :brand færdigt.',
            'not_you' => 'Indtast den ikke nogen steder, og ignorer bare denne e-mail. Hvis du har en konto hos :brand, så skift adgangskoden — den, der er nået så langt, kender den måske.',
        ],
        'device' => [
            'subject' => 'Din kode til :brand til at godkende en enhed',
            'heading' => 'Godkend et login på en anden enhed',
            'lead' => 'Indtast denne kode for at godkende et login på :brand fra en anden enhed, fx et tv eller et kommandolinjeværktøj.',
            'not_you' => 'Indtast den ikke nogen steder. Nogen prøver måske at få dig til at godkende deres enhed — sådan starter den slags angreb. Ignorer denne e-mail, så bliver intet godkendt.',
        ],
        'link' => [
            'subject' => 'Din kode til :brand til at tilknytte en loginmetode',
            'heading' => 'Tilknyt en loginmetode',
            'lead' => 'Indtast denne kode for at tilknytte en ny måde at logge ind på til din konto hos :brand.',
            'not_you' => 'Indtast den ikke nogen steder. Nogen prøver måske at koble deres login på din konto. Ignorer denne e-mail, så bliver intet tilknyttet.',
        ],
        'verify' => [
            'subject' => 'Din bekræftelseskode til :brand',
            'heading' => 'Din bekræftelseskode',
            'lead' => 'Indtast denne kode hos :brand for at bekræfte, at e-mailadressen er din.',
            'not_you' => 'Du kan ignorere denne e-mail — der sker ingenting uden koden.',
        ],
    ],
];
