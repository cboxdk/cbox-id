<?php

declare(strict_types=1);

// Norwegian Bokmål: the mail the hosted flows send. `:brand` is the configured product name.
return [
    'layout' => [
        'footer' => '© :year :brand · Dette er en automatisk melding fra identitetsplattformen din.',
    ],

    'common' => [
        'paste_link' => 'Eller lim inn denne lenken i nettleseren din:',
    ],

    'roles' => [
        'owner' => 'Eier',
        'admin' => 'Administrator',
        'developer' => 'Utvikler',
        'member' => 'Medlem',
        'viewer' => 'Leser',
    ],

    'magic_link' => [
        'subject' => 'Innloggingslenken din for :brand',
        'heading' => 'Logg inn på :brand',
        'body' => 'Klikk på knappen nedenfor for å logge inn. Lenken kan bare brukes én gang og utløper om 15 minutter. Hvis du ikke har bedt om den, kan du trygt se bort fra denne e-posten.',
        'button' => 'Logg inn på :brand',
    ],

    'password_reset' => [
        'subject' => 'Tilbakestill passordet ditt for :brand',
        'heading' => 'Tilbakestill passordet ditt',
        'body' => 'Vi har mottatt en forespørsel om å tilbakestille passordet ditt for :brand. Klikk på knappen nedenfor for å velge et nytt. Lenken kan bare brukes én gang og utløper om 60 minutter. Hvis du ikke har bedt om dette, kan du trygt se bort fra denne e-posten – passordet ditt blir ikke endret.',
        'button' => 'Tilbakestill passord',
    ],

    'email_verification' => [
        'subject' => 'Bekreft e-postadressen din for :brand',
        'heading' => 'Bekreft e-postadressen din',
        'body' => 'Velkommen til :brand. Bekreft at dette er e-postadressen din for å fullføre sikringen av kontoen din. Lenken kan bare brukes én gang og utløper om 24 timer.',
        'button' => 'Bekreft e-postadresse',
    ],

    'admin_assigned_password' => [
        'subject' => 'Passordet ditt for :brand er tilbakestilt',
        'heading' => 'Passordet ditt er tilbakestilt',
        'body' => 'En administrator har angitt et nytt passord for kontoen din. Logg inn med det nedenfor.',
        'temporary' => 'Du blir bedt om å velge ditt eget passord med en gang.',
        'expires' => 'Dette passordet slutter å virke :date, så logg inn før den tid.',
        'not_expected' => 'Hvis du ikke ventet dette, tar du kontakt med administratoren din – noen med tilgang til organisasjonens konsoll har gjort denne endringen, og den er registrert i revisjonsloggen.',
    ],

    'invitation' => [
        'subject' => ':inviter har invitert deg til å bli med i :organization',
        'heading' => 'Bli med i :organization',
        'invited' => ':inviter har invitert deg til å bli med i :organization.',
        'invited_as' => ':inviter har invitert deg til å bli med i :organization som :role.',
        'accept_app' => 'Godta for å logge inn på :app med kontoen din i :organization.',
        'accept' => 'Godta for å sette opp kontoen din og logge inn.',
        'button' => 'Se invitasjonen',
        'note' => 'Lenken åpner en side der du bekrefter – ingenting skjer før du gjør det. Den utløper om 7 dager. Hvis du ikke ventet dette, kan du se bort fra det.',
    ],

    'organization_invite' => [
        'subject' => ':inviter har invitert deg til å administrere :organization på :brand',
        'heading' => 'Vær med og administrer :organization på :brand',
        'invited' => ':inviter har invitert deg til å administrere :organization på :brand – konsollen for organisasjonens identitetsleverandører: miljøer, medlemmer og fakturering. Godta for å angi et passord og logge inn.',
        'invited_as' => ':inviter har invitert deg til å administrere :organization som :role på :brand – konsollen for organisasjonens identitetsleverandører: miljøer, medlemmer og fakturering. Godta for å angi et passord og logge inn.',
        'button' => 'Godta invitasjonen',
    ],

    'portal_link' => [
        'subject' => 'Sett opp :organization på :brand',
        'heading' => 'Sett opp :organization',
        'lead' => 'Du er bedt om å sette opp følgende for :organization på :brand. Du trenger ingen konto – knappen nedenfor er alt som skal til.',
        'intents' => [
            'sso' => 'Single sign-on med identitetsleverandøren din',
            'dsync' => 'Katalogsynkronisering (SCIM)',
            'domain_verification' => 'Bekreftelse av e-postdomenene dine',
            'log_streams' => 'Strømming av revisjonsloggen til SIEM-en din',
            'certificate_renewal' => 'Fornyelse av SAML-signeringssertifikatet ditt',
        ],
        'button' => 'Start oppsettet',
        'expires' => 'Lenken kan brukes én gang, frem til :date. Hvis den utløper, ber du om en ny.',
    ],

    // From the daily certificate scan, to an organization's owners and admins.
    'certificate_expiring' => [
        'subject' => 'Single sign-on via :connection slutter å virke om :count dag|Single sign-on via :connection slutter å virke om :count dager',
        'subject_expired' => 'Single sign-on via :connection har sluttet å virke',
        'heading' => 'SAML-sertifikatet ditt utløper snart',
        'heading_expired' => 'SAML-sertifikatet ditt har utløpt',
        'lead' => 'Signeringssertifikatet til :connection, single sign-on-tilkoblingen for :organization, utløper :date. Etter det kan ingen logge inn via den.',
        'lead_expired' => 'Signeringssertifikatet til :connection, single sign-on-tilkoblingen for :organization, utløp :date. Ingen kan logge inn via den før det er fornyet.',
        'what_to_do' => 'Be den som drifter identitetsleverandøren din, om det nye signeringssertifikatet, og last det deretter opp i administrasjonskonsollen – eller be administratoren din om en lenke til Admin Portal for å laste det opp.',
        'why' => 'Du mottar denne e-posten fordi du er eier eller administrator for denne organisasjonen på :brand.',
    ],
];
