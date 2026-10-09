<?php

declare(strict_types=1);

// Norwegian Bokmål: the sign-in group (pages/auth/*).
return [
    'common' => [
        'email' => 'E-post',
        'password' => 'Passord',
        'new_password' => 'Nytt passord',
        'confirm_new_password' => 'Bekreft nytt passord',
        'sign_in' => 'Logg inn',
        'back_to_sign_in' => 'Tilbake til innlogging',
        'check_your_inbox' => 'Sjekk innboksen din',
        'verify' => 'Bekreft',
        'cancel_and_sign_out' => 'Avbryt og logg ut',
        'too_many_attempts' => 'For mange forsøk. Prøv igjen om :count sekund.|For mange forsøk. Prøv igjen om :count sekunder.',
        'too_many_requests' => 'For mange forespørsler. Prøv igjen om :count sekund.|For mange forespørsler. Prøv igjen om :count sekunder.',
        'could_not_process' => 'Vi kunne ikke behandle denne forespørselen. Prøv igjen senere.',
        'code_incorrect' => 'Koden er feil eller har utløpt.',
    ],

    'password_field' => [
        'show' => 'Vis passord',
        'hide' => 'Skjul passord',
        'policy' => 'Minst :count tegn',
    ],

    'login' => [
        'title' => 'Logg inn',
        'purpose' => [
            'default' => 'Velkommen tilbake. Få tilgang til identitetskonsollen for organisasjonen din.',
            'device' => 'Logg inn for å godkjenne enheten som venter.',
        ],
        'pending_link' => [
            'lead' => 'Noen logget inn med :provider med denne e-postadressen.',
            'body' => 'Denne e-postadressen har allerede en konto her. Logg inn nedenfor, så spør vi om du vil koble :provider til den.',
        ],
        'magic' => [
            'sent_to' => 'Vi har sendt en innloggingslenke til engangsbruk til :email.',
            'dev_note' => 'Vises fordi e-post ikke er konfigurert i dette miljøet.',
        ],
        'mandate' => [
            'heading' => ':organization krever single sign-on (SSO)',
            'continue' => 'Fortsett til :organization',
            'no_provider' => 'Ingen identitetsleverandør er koblet til :organization ennå, så vi har ikke noe sted å sende deg. Be en administrator om å fullføre oppsettet av single sign-on.',
            'your_organization' => 'Organisasjonen din',
            'reasons' => [
                'password' => 'Passordet ditt er riktig – det er bare ikke lenger en måte å logge inn på her. Logg inn via organisasjonens identitetsleverandør i stedet.',
                'magic_link' => 'Innloggingslenken virket, og den er nå brukt opp. Lenker på e-post er ikke lenger en måte å logge inn på her – logg inn via organisasjonens identitetsleverandør i stedet.',
                'passkey' => 'Passnøkkelen din virket. Den er bare ikke lenger en måte å logge inn på her – logg inn via organisasjonens identitetsleverandør i stedet.',
                'social' => 'Innloggingen virket, men det er ikke identitetsleverandøren organisasjonen din har valgt. Logg inn via organisasjonens leverandør i stedet.',
                'invitation' => 'Invitasjonen er godtatt, og du er nå medlem. Logg inn via organisasjonens identitetsleverandør for å komme i gang.',
                'password_reset' => 'Det nye passordet ditt er lagret, men passord er ikke lenger en måte å logge inn på her. Logg inn via organisasjonens identitetsleverandør i stedet.',
            ],
        ],
        'use_different_email' => 'Bruk en annen e-postadresse',
        'continue' => 'Fortsett',
        'sso' => [
            'continue' => 'Fortsett med single sign-on',
            'or_password' => 'eller bruk passordet ditt',
            'instead' => 'Fortsett med single sign-on i stedet',
        ],
        'forgot_password' => 'Glemt passordet?',
        'or' => 'ELLER',
        'continue_with' => 'Fortsett med :provider',
        'magic_link' => 'Send meg en innloggingslenke',
        'passkey' => 'Logg inn med passnøkkel',
        'passkey_failed' => 'Innlogging med passnøkkel mislyktes.',
        'new_organization' => 'Ny organisasjon?',
        'create_one' => 'Opprett en',
        'invalid_credentials' => 'Innloggingsopplysningene samsvarer ikke med det vi har registrert.',
        'social' => [
            'failed' => 'Innlogging med :provider ble avbrutt eller mislyktes.',
            'unavailable' => 'Innlogging med :provider er ikke tilgjengelig akkurat nå.',
        ],
        'passkey_errors' => [
            'challenge_expired' => 'Innloggingsforespørselen har utløpt. Prøv igjen.',
            'not_registered' => 'Denne passnøkkelen er ikke registrert.',
            'cloned' => 'Denne passnøkkelen kan ha blitt klonet og ble avvist.',
            'unverified' => 'Passnøkkelen kunne ikke bekreftes.',
            'failed' => 'Noe gikk galt under innloggingen.',
        ],
    ],

    'signup' => [
        'title' => 'Kom i gang',
        'heading' => [
            'creates_idp' => 'Opprett arbeidsområdet ditt',
            'for_app' => 'Opprett kontoen din',
            'default' => 'Opprett organisasjonen din',
        ],
        'lead' => [
            'creates_idp' => 'Et arbeidsområde for bedriften din, og din egen hostede identitetsleverandør – SSO, brukere og innlogging du har full kontroll over, klart på et minutt.',
            'join' => 'Registrer deg for :name. Du blir eier av teamet ditt og kan invitere andre når du er inne.',
            'default' => 'Sett opp Cbox ID for teamet ditt på under ett minutt.',
        ],
        'organization_label' => [
            'creates_idp' => 'Navn på arbeidsområdet',
            'for_app' => 'Team- eller bedriftsnavn',
            'default' => 'Organisasjonsnavn',
        ],
        'organization_placeholder' => 'Acme AS',
        'name_label' => 'Navnet ditt',
        'name_placeholder' => 'Kari Nordmann',
        'email_label' => 'Jobb-e-post',
        'breach_note' => 'Kontrollert mot kjente datalekkasjer.',
        'submit' => [
            'creates_idp' => 'Opprett arbeidsområde',
            'for_app' => 'Opprett konto og fortsett',
            'default' => 'Opprett organisasjon',
        ],
        'have_account' => 'Har du allerede en konto?',
        'complete_verification' => 'Fullfør verifiseringen nedenfor, og send inn på nytt.',
        'sso_required' => 'Organisasjonen din krever innlogging via SSO.',
        'account_exists' => 'Det finnes allerede en konto med denne e-postadressen.',
        'closed' => [
            'tenant' => 'Du trenger en invitasjon for å bli med. Be den som administrerer teamet ditt, om en.',
            'invite_only' => 'Registrering skjer kun via invitasjon. Be en administrator om en invitasjon.',
            'closed' => 'Registrering er stengt for øyeblikket.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Tilbakestill passord',
        'heading' => 'Tilbakestill passordet ditt',
        'lead' => 'Skriv inn e-postadressen din, så sender vi deg en lenke for å tilbakestille passordet.',
        'sent_to' => 'Hvis det finnes en konto for :email, er en tilbakestillingslenke på vei.',
        'submit' => 'Send tilbakestillingslenke',
        'remembered' => 'Husker du det likevel?',
        'throttled' => 'For mange forsøk. Vent noen minutter, og prøv igjen.',
    ],

    'reset_password' => [
        'title' => 'Velg et nytt passord',
        'lead' => 'Velg et sterkt passord på minst :count tegn.',
        'confirm_placeholder' => 'Skriv inn det nye passordet på nytt',
        'submit' => 'Tilbakestill passord',
        'invalid_link' => 'Denne tilbakestillingslenken er ugyldig eller har utløpt. Be om en ny.',
        'done' => 'Passordet ditt er tilbakestilt – logg inn med det nye passordet.',
    ],

    'change_password' => [
        'title' => 'Velg et nytt passord',
        'lead' => 'Passordet du logget inn med, ble angitt av en administrator. Velg et passord som bare du kjenner, før du fortsetter.',
        'submit' => 'Oppdater passord',
        'mismatch' => 'Passordene er ikke like.',
    ],

    'mfa' => [
        'title' => 'Tofaktorautentisering',
        'code' => [
            'lead' => 'Skriv inn den sekssifrede koden fra autentiseringsappen din.',
            'label' => 'Autentiseringskode',
            'switch' => 'Bruk en gjenopprettingskode i stedet',
            'sms_switch' => 'Send meg en kode på SMS i stedet',
        ],
        'recovery' => [
            'lead' => 'Skriv inn en av gjenopprettingskodene du lagret da du slo på tofaktorautentisering.',
            'label' => 'Gjenopprettingskode',
            'submit' => 'Bekreft gjenopprettingskode',
            'switch' => 'Bruk autentiseringsappen i stedet',
            'invalid' => 'Gjenopprettingskoden er ugyldig eller allerede brukt.',
            'back' => 'Bruk en annen metode',
        ],
        'sms' => [
            'lead' => 'Vi sender en kode på SMS til telefonnummeret på kontoen din.',
            'send' => 'Send meg en kode',
            'sent' => 'Vi har sendt en kode til :number. Den utløper om noen minutter.',
            'resend' => 'Send en ny kode',
            'label' => 'Koden fra SMS-en',
            'switch' => 'Bruk autentiseringsappen i stedet',
            'wait' => 'Det ble nettopp sendt en kode. Vent litt før du ber om en ny.',
            'failed' => 'Vi kunne ikke sende SMS-en. Prøv igjen om litt, eller bruk en gjenopprettingskode.',
            'unavailable' => 'SMS-koder er ikke tilgjengelige for denne kontoen.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Ekstra bekreftelse',
        'lead' => 'Denne innloggingen så uvanlig ut, så vi har sendt en engangskode til :email. Skriv den inn for å fortsette.',
        'signup_title' => 'Bekreft e-postadressen din',
        'signup_lead' => 'Skriv inn engangskoden vi har sendt til :email for å fullføre opprettelsen av kontoen din.',
        'code_label' => 'Bekreftelseskode',
        'resend' => 'Fikk du den ikke? Send koden på nytt',
        'resent' => 'Vi har sendt en ny kode til :email.',
        'too_many_codes' => 'Du har bedt om for mange koder. Vent litt, og prøv igjen.',
    ],

    'accounts' => [
        'title' => 'Bytt bruker',
        'lead' => 'Alle som er logget inn på denne enheten. Velg én, eller logg inn som en annen.',
        'active' => 'Aktiv',
        'add' => 'Logg inn som en annen',
    ],

    'accept_invite' => [
        'title' => 'Godta invitasjon',
        'heading' => 'Godta invitasjonen',
        'lead_from' => ':inviter har invitert deg til å være med og administrere :organization som :role. Du logger inn som :email.',
        'lead' => 'Angi et passord for å være med og administrere :organization som :role. Du logger inn som :email.',
        'organization_fallback' => 'organisasjonen',
        'password_label' => 'Velg et passord',
        'breach_note' => 'Kontrollert mot kjente datalekkasjer.',
        'submit' => 'Godta og logg inn',
        'no_longer_valid' => 'Denne invitasjonen er ikke lenger gyldig. Prøv å logge inn.',
        'invalid' => 'Invitasjonen er ugyldig eller har utløpt.',
        'not_completed' => 'Invitasjonen kunne ikke fullføres.',
    ],

    'confirm_email' => [
        'title' => 'Bekreft e-postadressen',
        'heading' => 'Bekreft e-postadressen din',
        'lead' => 'Du åpnet bekreftelseslenken vi sendte. Bekreft for å fullføre verifiseringen av denne adressen.',
        'action' => 'Bekreft e-postadresse',
        'invalid' => 'Bekreftelseslenken er ugyldig eller har utløpt.',
        'verified_sign_in' => 'E-postadressen er bekreftet – logg inn for å åpne miljøet ditt.',
        'verified' => 'E-postadressen din er bekreftet – du kan logge inn.',
    ],

    'confirm_sign_in' => [
        'title' => 'Logg inn',
        'heading' => 'Fullfør innloggingen',
        'lead' => 'Du åpnet en innloggingslenke. Fortsett for å logge inn på denne enheten.',
        'action' => 'Logg inn',
        'note' => 'Lenken virker bare én gang. Hvis du ikke har bedt om å logge inn, lukker du denne siden – ingenting skjer før du trykker på knappen.',
        'invalid' => 'Innloggingslenken er ugyldig eller har utløpt.',
    ],

    'first_run' => [
        'title' => 'Sett opp Cbox ID',
        'lead' => 'Denne installasjonen er tom. Gjør krav på den én gang, fra maskinen den kjører på.',
        'unmigrated' => [
            'title' => 'Databasen til denne installasjonen har ikke noe skjema ennå.',
            'body' => 'Kjør :migrate på serveren (eller :install, som migrerer og installerer i ett steg), og last deretter inn denne siden på nytt.',
        ],
        'misconfigured' => [
            'title' => 'Denne installasjonen er konfigurert for flere leietakere (multi-tenant), men har ingen vert for kontoer.',
            'body' => 'Angi :console_host (der konsollen ligger), eller angi :single_host for en installasjon med én vert – og last deretter inn denne siden på nytt. Du kan også kjøre :install, som spør etter begge og skriver dem for deg.',
        ],
        'token_notice' => [
            'title' => 'Hvor finner jeg oppsettstokenet?',
            'body' => 'Kjør :command på serveren — på hvilken som helst instans av installasjonen — og lim inn det som skrives ut. Hver kjøring skriver ut et nytt token som gjelder i én time. Det vises aldri på denne siden.',
        ],
        'cli_hint' => 'Foretrekker du kommandolinjen? :install gjør det samme, og er den eneste måten som også kan velge og registrere installasjonstypen.',
        'token_label' => 'Oppsettstoken',
        'token_placeholder' => 'Lim inn tokenet fra serveren',
        'name_label' => 'Navnet ditt',
        'name_placeholder' => 'Kari Nordmann',
        'email_label' => 'E-postadressen din',
        'environment_label' => 'Gi det første miljøet ditt et navn',
        'environment_hint' => 'Et miljø er den harde isolasjonsgrensen – med egne brukere, nøkler og utsteder.',
        'environment_default' => 'Produksjon',
        'organization_label' => 'Organisasjonsnavn',
        'organization_hint' => 'Denne installasjonen er konfigurert for flere leietakere, så installasjonen oppretter også det første arbeidsområdet – organisasjonen som eier miljøer og fakturering.',
        'organization_placeholder' => 'Bedriften din',
        'submit' => 'Fullfør installasjonen',
        'token_mismatch' => 'Oppsettstokenet samsvarer ikke med installasjonens, eller det har utløpt. Skriv ut et nytt med php artisan cbox-id:setup-token.',
    ],

    'join_organization' => [
        'title' => 'Bli med i :organization',
        'heading' => 'Bli med i :organization?',
        'lead' => 'Du er invitert til å bli med i denne organisasjonen. Godta for å bli medlem og logge inn.',
        'lead_app' => 'Du er invitert til å bli med i denne organisasjonen. Godta for å bli medlem, så sender vi deg videre til :app.',
        'action' => 'Godta invitasjonen',
        'note' => 'Ventet du ikke dette? Lukk denne siden – ingenting skjer med mindre du godtar.',
        'facts' => [
            'organization' => 'Organisasjon',
            'invited_by' => 'Invitert av',
            'built_in_role' => 'Innebygd rolle',
            'custom_roles' => 'Egendefinerte roller',
            'app_roles' => 'Roller i :app',
            'email' => 'E-postadressen din',
            'app' => 'App',
        ],
        'invalid' => 'Invitasjonen er ugyldig eller har utløpt.',
    ],

    'link_confirm' => [
        'title' => 'Koble til kontoen din',
        'heading' => 'Koble til :provider?',
        'lead' => 'Noen logget nettopp inn med :provider – en adresse som allerede tilhører kontoen din.',
        'lead_email' => 'Noen logget nettopp inn med :provider som :email – en adresse som allerede tilhører kontoen din.',
        'was_you' => ':emphasis, kan du koble den til. Da kan du fra nå av logge inn med :provider eller med passordet ditt.',
        'was_you_emphasis' => 'Hvis det var deg',
        'was_not_you' => ':emphasis, avslår du. Noen andre prøvde å logge inn med e-postadressen din. Ingenting blir lagt til kontoen din, og passordet ditt virker som før.',
        'was_not_you_emphasis' => 'Hvis det ikke var deg',
        'decline' => 'Nei, det var ikke meg',
        'connect' => 'Ja, koble til :provider',
        'disconnect_hint' => 'Du kan når som helst koble fra :provider i sikkerhetsinnstillingene for kontoen din.',
    ],

    'open_portal_setup' => [
        'title' => 'Administratoroppsett',
        'heading' => 'Konfigurer innlogging for organisasjonen din',
        'lead' => 'Du har fått en oppsettslenke for organisasjonen din — single sign-on, katalogsynkronisering, domener, loggstrømmer eller fornyelse av et sertifikat. Fortsett for å åpne oppsettsiden.',
        'action' => 'Åpne oppsett',
        'note' => 'Lenken virker bare én gang, og oppsettsøkten den åpner, utløper. Åpne den når du er klar til å fullføre.',
    ],
];
