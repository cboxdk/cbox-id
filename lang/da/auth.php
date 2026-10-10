<?php

declare(strict_types=1);

// Danish: the sign-in group (see lang/en/auth.php for keys and context). Terminology is
// fixed in docs/guides/languages.md ("Danish terminology"): "log ind" as the verb, "login"
// as the noun, "adgangskode", "adgangsnøgle", "totrinsbekræftelse", du-form throughout.
return [
    'common' => [
        'email' => 'E-mail',
        'password' => 'Adgangskode',
        'new_password' => 'Ny adgangskode',
        'confirm_new_password' => 'Bekræft ny adgangskode',
        'sign_in' => 'Log ind',
        'back_to_sign_in' => 'Tilbage til login',
        'check_your_inbox' => 'Tjek din indbakke',
        'verify' => 'Bekræft',
        'cancel_and_sign_out' => 'Annuller og log ud',
        'too_many_attempts' => 'For mange forsøg. Prøv igen om :count sekund.|For mange forsøg. Prøv igen om :count sekunder.',
        'too_many_requests' => 'For mange anmodninger. Prøv igen om :count sekund.|For mange anmodninger. Prøv igen om :count sekunder.',
        'could_not_process' => 'Vi kunne ikke behandle anmodningen. Prøv igen senere.',
        'code_incorrect' => 'Koden er forkert eller udløbet.',
    ],

    'password_field' => [
        'show' => 'Vis adgangskode',
        'hide' => 'Skjul adgangskode',
        'policy' => 'Mindst :count tegn',
    ],

    'login' => [
        'title' => 'Log ind',
        'purpose' => [
            'default' => 'Velkommen tilbage. Log ind på din organisations identitetskonsol.',
            'device' => 'Log ind for at godkende den enhed der venter.',
        ],
        'pending_link' => [
            'lead' => 'Nogen har logget ind via :provider med denne e-mailadresse.',
            'body' => 'Der findes allerede en konto med den e-mailadresse. Log ind herunder, så spørger vi om du vil forbinde :provider til kontoen.',
        ],
        'magic' => [
            'sent_to' => 'Vi har sendt et loginlink til :email. Det kan kun bruges én gang.',
            'dev_note' => 'Vises fordi der ikke er sat e-mail op i dette miljø.',
        ],
        'mandate' => [
            'heading' => ':organization kræver single sign-on',
            'continue' => 'Fortsæt til :organization',
            'no_provider' => 'Der er endnu ikke forbundet en identitetsudbyder til :organization, så vi kan ikke sende dig videre. Bed en administrator om at gøre opsætningen af single sign-on færdig.',
            'your_organization' => 'Din organisation',
            'reasons' => [
                'password' => 'Din adgangskode er korrekt, men den kan ikke længere bruges til at logge ind her. Log i stedet ind via din organisations identitetsudbyder.',
                'magic_link' => 'Loginlinket virkede, og det er nu brugt. Loginlinks på e-mail kan ikke længere bruges her. Log i stedet ind via din organisations identitetsudbyder.',
                'passkey' => 'Din adgangsnøgle virkede, men den kan ikke længere bruges til at logge ind her. Log i stedet ind via din organisations identitetsudbyder.',
                'social' => 'Du blev logget ind, men ikke via den identitetsudbyder din organisation har valgt. Log i stedet ind via den.',
                'invitation' => 'Din invitation er accepteret, og du er nu medlem. Log ind via din organisations identitetsudbyder for at komme i gang.',
                'password_reset' => 'Din nye adgangskode er gemt, men adgangskoder kan ikke længere bruges til at logge ind her. Log i stedet ind via din organisations identitetsudbyder.',
            ],
        ],
        'use_different_email' => 'Brug en anden e-mailadresse',
        'continue' => 'Fortsæt',
        'sso' => [
            'continue' => 'Fortsæt med single sign-on',
            'or_password' => 'eller brug din adgangskode',
            'instead' => 'Fortsæt med single sign-on i stedet',
        ],
        'forgot_password' => 'Glemt adgangskode?',
        'or' => 'ELLER',
        'continue_with' => 'Fortsæt med :provider',
        // The badge on the sign-in method this device used last, and what it means said in full
        // for a screen reader.
        'last_used' => 'Sidst brugt',
        'last_used_hint' => 'Sådan loggede du ind sidste gang på denne enhed.',
        'magic_link' => 'Send mig et loginlink',
        'passkey' => 'Log ind med adgangsnøgle',
        'passkey_failed' => 'Login med adgangsnøgle mislykkedes.',
        'new_organization' => 'Ny organisation?',
        'create_one' => 'Opret en',
        'invalid_credentials' => 'E-mailadressen eller adgangskoden er forkert.',
        'social' => [
            'failed' => 'Login med :provider blev annulleret eller mislykkedes.',
            'unavailable' => 'Login med :provider er ikke tilgængeligt lige nu.',
        ],
        'passkey_errors' => [
            'challenge_expired' => 'Loginforsøget er udløbet. Prøv igen.',
            'not_registered' => 'Adgangsnøglen er ikke registreret.',
            'cloned' => 'Adgangsnøglen blev afvist, fordi den muligvis er blevet kopieret.',
            'unverified' => 'Adgangsnøglen kunne ikke bekræftes.',
            'failed' => 'Noget gik galt under login.',
        ],
        // Answered by a passkey or magic-link door the environment has switched off.
        'method_off' => [
            'passkeys' => 'Adgangsnøgler er slået fra her. Log ind på en anden måde.',
            'magic_link' => 'Loginlinks på e-mail er slået fra her. Log ind på en anden måde.',
        ],
    ],

    'signup' => [
        'title' => 'Kom i gang',
        'heading' => [
            'creates_idp' => 'Opret dit arbejdsområde',
            'for_app' => 'Opret din konto',
            'default' => 'Opret din organisation',
        ],
        'lead' => [
            'creates_idp' => 'Et arbejdsområde til din virksomhed og jeres egen hostede identitetsudbyder: SSO, brugere og login som I selv har fuld kontrol over – klar på et minut.',
            'join' => 'Tilmeld dig :name. Du bliver ejer af dit team og kan invitere andre når du er kommet ind.',
            'default' => 'Sæt Cbox ID op til dit team på under et minut.',
        ],
        'organization_label' => [
            'creates_idp' => 'Arbejdsområdets navn',
            'for_app' => 'Teamets eller virksomhedens navn',
            'default' => 'Organisationens navn',
        ],
        'organization_placeholder' => 'Acme A/S',
        'name_label' => 'Dit navn',
        'name_placeholder' => 'Mette Hansen',
        'email_label' => 'Arbejdsmail',
        'breach_note' => 'Tjekkes mod kendte datalæk.',
        'submit' => [
            'creates_idp' => 'Opret arbejdsområde',
            'for_app' => 'Opret konto og fortsæt',
            'default' => 'Opret organisation',
        ],
        'have_account' => 'Har du allerede en konto?',
        'complete_verification' => 'Gennemfør bekræftelsen herunder, og send igen.',
        'sso_required' => 'Din organisation kræver login via single sign-on.',
        'account_exists' => 'Der findes allerede en konto med denne e-mailadresse.',
        'closed' => [
            'tenant' => 'Du skal have en invitation for at blive medlem. Spørg den der står for dit team.',
            'invite_only' => 'Tilmelding kræver en invitation. Bed en administrator om en.',
            'closed' => 'Tilmelding er lukket lige nu.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Nulstil adgangskode',
        'heading' => 'Nulstil din adgangskode',
        'lead' => 'Indtast din e-mailadresse, så sender vi dig et link til at nulstille din adgangskode.',
        'sent_to' => 'Hvis der findes en konto med :email, er et link til nulstilling på vej.',
        'submit' => 'Send link',
        'remembered' => 'Kom du i tanke om den?',
        'throttled' => 'For mange forsøg. Vent et par minutter, og prøv igen.',
    ],

    'reset_password' => [
        'title' => 'Vælg en ny adgangskode',
        'lead' => 'Vælg en stærk adgangskode på mindst :count tegn.',
        'confirm_placeholder' => 'Gentag din nye adgangskode',
        'submit' => 'Nulstil adgangskode',
        'invalid_link' => 'Linket til nulstilling er ugyldigt eller udløbet. Bed om et nyt.',
        'done' => 'Din adgangskode er nulstillet. Log ind med din nye adgangskode.',
    ],

    'change_password' => [
        'title' => 'Vælg en ny adgangskode',
        'lead' => 'Du loggede ind med en adgangskode fra en administrator. Vælg en ny som kun du kender, inden du fortsætter.',
        'submit' => 'Opdater adgangskode',
        'mismatch' => 'Adgangskoderne er ikke ens.',
    ],

    'mfa' => [
        'title' => 'Totrinsbekræftelse',
        'code' => [
            'lead' => 'Indtast den 6-cifrede kode fra din godkendelsesapp.',
            'label' => 'Godkendelseskode',
            'switch' => 'Brug en gendannelseskode i stedet',
            'sms_switch' => 'Send mig en kode på sms i stedet',
        ],
        'recovery' => [
            'lead' => 'Indtast en af de gendannelseskoder du gemte da du slog totrinsbekræftelse til.',
            'label' => 'Gendannelseskode',
            'submit' => 'Bekræft gendannelseskode',
            'switch' => 'Brug din godkendelsesapp i stedet',
            'invalid' => 'Gendannelseskoden er ugyldig eller allerede brugt.',
            'back' => 'Brug en anden metode',
        ],
        'sms' => [
            'lead' => 'Vi sender en kode på sms til telefonnummeret på din konto.',
            'send' => 'Send mig en kode',
            'sent' => 'Vi har sendt en kode til :number. Den udløber om få minutter.',
            'resend' => 'Send en ny kode',
            'label' => 'Koden fra sms\'en',
            'switch' => 'Brug din godkendelsesapp i stedet',
            'wait' => 'Der blev sendt en kode for lidt siden. Vent et øjeblik, før du beder om en ny.',
            'failed' => 'Vi kunne ikke sende sms\'en. Prøv igen om lidt, eller brug en gendannelseskode.',
            'unavailable' => 'Sms-koder er ikke tilgængelige for denne konto.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Ekstra bekræftelse',
        'lead' => 'Dette login så usædvanligt ud, så vi har sendt en engangskode til :email. Indtast den for at fortsætte.',
        'signup_title' => 'Bekræft din e-mailadresse',
        'signup_lead' => 'Indtast engangskoden, vi har sendt til :email, for at gøre oprettelsen af din konto færdig.',
        'code_label' => 'Bekræftelseskode',
        'resend' => 'Fik du den ikke? Send koden igen',
        'resent' => 'Vi har sendt en ny kode til :email.',
        'too_many_codes' => 'Du har bedt om for mange koder. Vent lidt, og prøv igen.',
    ],

    'accounts' => [
        'title' => 'Skift bruger',
        'lead' => 'Alle der er logget ind på denne enhed. Vælg en, eller log ind som en anden.',
        'active' => 'Aktiv',
        'add' => 'Log ind som en anden',
    ],

    'accept_invite' => [
        'title' => 'Accepter invitation',
        'heading' => 'Accepter din invitation',
        'lead_from' => ':inviter har inviteret dig til at være med til at administrere :organization med rollen :role. Du logger ind som :email.',
        'lead' => 'Vælg en adgangskode for at være med til at administrere :organization med rollen :role. Du logger ind som :email.',
        'organization_fallback' => 'organisationen',
        'password_label' => 'Vælg en adgangskode',
        'breach_note' => 'Tjekkes mod kendte datalæk.',
        'submit' => 'Accepter og log ind',
        'no_longer_valid' => 'Invitationen er ikke længere gyldig. Prøv at logge ind.',
        'invalid' => 'Invitationen er ugyldig eller udløbet.',
        'not_completed' => 'Invitationen kunne ikke gennemføres.',
    ],

    'confirm_email' => [
        'title' => 'Bekræft din e-mail',
        'heading' => 'Bekræft din e-mailadresse',
        'lead' => 'Du har åbnet det bekræftelseslink vi sendte dig. Bekræft adressen for at gøre det færdigt.',
        'action' => 'Bekræft e-mailadresse',
        'invalid' => 'Bekræftelseslinket er ugyldigt eller udløbet.',
        'verified_sign_in' => 'Din e-mail er bekræftet. Log ind for at åbne dit miljø.',
        'verified' => 'Din e-mail er bekræftet. Du kan nu logge ind.',
    ],

    'confirm_sign_in' => [
        'title' => 'Log ind',
        'heading' => 'Gennemfør login',
        'lead' => 'Du har åbnet et loginlink. Fortsæt for at logge ind på denne enhed.',
        'action' => 'Log ind',
        'note' => 'Linket virker kun én gang. Hvis du ikke har bedt om at logge ind, så luk denne side. Der sker ingenting før du trykker på knappen.',
        'invalid' => 'Loginlinket er ugyldigt eller udløbet.',
    ],

    'first_run' => [
        'title' => 'Sæt Cbox ID op',
        'lead' => 'Denne installation er tom. Tag den i brug fra den maskine den kører på – det kan kun gøres én gang.',
        'unmigrated' => [
            'title' => 'Databasen for denne installation har endnu intet skema.',
            'body' => 'Kør :migrate på serveren (eller :install, som migrerer og installerer i ét trin), og genindlæs derefter siden.',
        ],
        'misconfigured' => [
            'title' => 'Installationen er konfigureret som multi-tenant, men mangler en konto-host.',
            'body' => 'Angiv :console_host (hvor konsollen ligger), eller angiv :single_host til en installation med én host. Genindlæs derefter siden. Du kan også køre :install, som spørger efter begge og skriver dem for dig.',
        ],
        'token_notice' => [
            'title' => 'Hvor er opsætningstokenet?',
            'body' => 'Kør :command på serveren — på en hvilken som helst instans af installationen — og indsæt det, der bliver skrevet ud. Hver kørsel skriver et nyt token ud, som gælder i en time. Det vises aldrig på denne side.',
        ],
        'cli_hint' => 'Foretrækker du kommandolinjen? :install gør det samme og er den eneste måde der også kan vælge og gemme installationens opbygning.',
        'token_label' => 'Opsætningstoken',
        'token_placeholder' => 'Indsæt tokenet fra serveren',
        'name_label' => 'Dit navn',
        'name_placeholder' => 'Driftsansvarlig',
        'email_label' => 'Din e-mail',
        'environment_label' => 'Navngiv dit første miljø',
        'environment_hint' => 'Et miljø er fuldstændig isoleret, med egne brugere, nøgler og udsteder.',
        'environment_default' => 'Produktion',
        'organization_label' => 'Organisationens navn',
        'organization_hint' => 'Installationen er konfigureret som multi-tenant, så den opretter også det første arbejdsområde: den organisation der ejer miljøer og fakturering.',
        'organization_placeholder' => 'Din virksomhed',
        'submit' => 'Start installationen',
        'token_mismatch' => 'Opsætningstokenet passer ikke til installationens, eller det er udløbet. Skriv et nyt ud med php artisan cbox-id:setup-token.',
    ],

    'join_organization' => [
        'title' => 'Bliv medlem af :organization',
        'heading' => 'Vil du være medlem af :organization?',
        'lead' => 'Du er inviteret til at blive medlem af denne organisation. Accepter for at blive medlem og logge ind.',
        'lead_app' => 'Du er inviteret til at blive medlem af denne organisation. Accepter for at blive medlem, så sender vi dig videre til :app.',
        'action' => 'Accepter invitation',
        'note' => 'Havde du ikke ventet den? Luk siden. Der sker ingenting medmindre du accepterer.',
        'facts' => [
            'organization' => 'Organisation',
            'invited_by' => 'Inviteret af',
            'built_in_role' => 'Indbygget rolle',
            'custom_roles' => 'Brugerdefinerede roller',
            'app_roles' => 'Roller i :app',
            'email' => 'Din e-mail',
            'app' => 'App',
        ],
        'invalid' => 'Invitationen er ugyldig eller udløbet.',
    ],

    'link_confirm' => [
        'title' => 'Forbind din konto',
        'heading' => 'Vil du forbinde :provider?',
        'lead' => 'Nogen har lige logget ind via :provider med en e-mailadresse der allerede hører til din konto.',
        'lead_email' => 'Nogen har lige logget ind via :provider som :email, en adresse der allerede hører til din konto.',
        'was_you' => ':emphasis, kan du forbinde den. Så kan du fremover logge ind med :provider eller med din adgangskode.',
        'was_you_emphasis' => 'Hvis det var dig',
        'was_not_you' => ':emphasis, skal du afvise. En anden har forsøgt at logge ind med din e-mailadresse. Der bliver ikke tilføjet noget til din konto, og din adgangskode virker som før.',
        'was_not_you_emphasis' => 'Hvis det ikke var dig',
        'decline' => 'Nej, det var ikke mig',
        'connect' => 'Ja, forbind :provider',
        'disconnect_hint' => 'Du kan til enhver tid fjerne forbindelsen til :provider under sikkerhedsindstillingerne for din konto.',
    ],

    'open_portal_setup' => [
        'title' => 'Opsætning for administratorer',
        'heading' => 'Sæt login op for jeres organisation',
        'lead' => 'Du har fået et opsætningslink til jeres organisation – single sign-on, katalogsynkronisering, domæner, logstreaming eller fornyelse af et certifikat. Fortsæt for at åbne opsætningen.',
        'action' => 'Åbn opsætning',
        'note' => 'Linket virker kun én gang, og den opsætningssession det åbner, udløber. Åbn det når du er klar til at gøre opsætningen færdig.',
    ],

    'connected_services' => [
        'title' => 'Forbundne tjenester',
        'heading' => 'Forbundne tjenester',
        'lead' => 'Konti hos andre tjenester, som du har forbundet, så apps her kan bruge dem på dine vegne. Du kan til enhver tid fjerne en forbindelse.',
        'empty' => 'Der er endnu ingen tjenester at forbinde.',
        'connected_as' => 'Forbundet som :account',
        'connected_label' => 'Forbundet',
        'not_connected' => 'Ikke forbundet',
        'needs_reauth' => 'Skal forbindes igen',
        'needs_reauth_hint' => ':provider accepterer ikke længere denne forbindelse. Forbind igen for at fortsætte med at bruge den.',
        'access' => 'Adgang: :scopes',
        'connect' => 'Forbind',
        'reconnect' => 'Forbind igen',
        'disconnect' => 'Fjern forbindelse',
        'disconnect_title' => 'Vil du fjerne forbindelsen til :provider?',
        'disconnect_body' => 'Apps her vil ikke længere kunne bruge din :provider-konto. Hvor :provider understøtter det, trækkes adgangen også tilbage dér.',
        'cancel' => 'Annuller',
        'back' => 'Tilbage til min konto',
        'connected' => ':provider er forbundet.',
        'cancelled' => 'Du forbandt ikke :provider.',
        'failed' => ':provider kunne ikke forbindes. Prøv igen.',
        'disconnected' => 'Forbindelsen til :provider er fjernet.',
    ],

    'connect_service' => [
        'title' => 'Forbind :provider',
        'heading' => 'Forbind din :provider-konto',
        'lead' => 'Du sendes videre til :provider for at logge ind og godkende adgangen. Din adgangskode til :provider deles aldrig med os.',
        'lead_app' => ':app vil gerne bruge din :provider-konto på dine vegne. Du sendes videre til :provider for at logge ind og godkende adgangen.',
        'asks_for' => ':provider vil bede dig tillade:',
        'no_scopes' => ':provider viser dig præcis, hvad der deles, før du godkender.',
        'continue' => 'Fortsæt til :provider',
        'cancel' => 'Annuller',
        'later' => 'Du kan til enhver tid fjerne forbindelsen under Forbundne tjenester på din konto.',
    ],
];
