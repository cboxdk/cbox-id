<?php

declare(strict_types=1);

// Swedish: the sign-in group (see lang/en/auth.php).
return [
    'common' => [
        'email' => 'E-post',
        'password' => 'Lösenord',
        'new_password' => 'Nytt lösenord',
        'confirm_new_password' => 'Bekräfta nytt lösenord',
        'sign_in' => 'Logga in',
        'back_to_sign_in' => 'Tillbaka till inloggningen',
        'check_your_inbox' => 'Kolla din inkorg',
        'verify' => 'Verifiera',
        'cancel_and_sign_out' => 'Avbryt och logga ut',
        'too_many_attempts' => 'För många försök. Försök igen om :count sekund.|För många försök. Försök igen om :count sekunder.',
        'too_many_requests' => 'För många förfrågningar. Försök igen om :count sekund.|För många förfrågningar. Försök igen om :count sekunder.',
        'could_not_process' => 'Vi kunde inte behandla begäran. Försök igen senare.',
        'code_incorrect' => 'Koden är felaktig eller har gått ut.',
    ],

    'password_field' => [
        'show' => 'Visa lösenord',
        'hide' => 'Dölj lösenord',
        'policy' => 'Minst :count tecken',
    ],

    'login' => [
        'title' => 'Logga in',
        'purpose' => [
            'default' => 'Välkommen tillbaka. Gå till din organisations identitetskonsol.',
            'device' => 'Logga in för att godkänna enheten som väntar.',
        ],
        'pending_link' => [
            'lead' => 'Någon loggade in med :provider med den här e-postadressen.',
            'body' => 'Den e-postadressen har redan ett konto här. Logga in nedan så frågar vi om du vill koppla :provider till det.',
        ],
        'magic' => [
            'sent_to' => 'Vi har skickat en inloggningslänk för engångsbruk till :email.',
            'dev_note' => 'Visas eftersom e-post inte är konfigurerat i den här miljön.',
        ],
        'mandate' => [
            'heading' => ':organization kräver single sign-on (SSO)',
            'continue' => 'Fortsätt till :organization',
            'no_provider' => 'Ingen identitetsleverantör är ansluten för :organization ännu, så det finns ingenstans att skicka dig. Be en administratör att slutföra konfigurationen av single sign-on (SSO).',
            'your_organization' => 'Din organisation',
            'reasons' => [
                'password' => 'Ditt lösenord är rätt — men det går inte längre att logga in här med lösenord. Logga in via din organisations identitetsleverantör i stället.',
                'magic_link' => 'Inloggningslänken fungerade, och nu är den förbrukad. Det går inte längre att logga in här med länkar via e-post — logga in via din organisations identitetsleverantör i stället.',
                'passkey' => 'Din lösennyckel fungerade. Men det går inte längre att logga in här med den — logga in via din organisations identitetsleverantör i stället.',
                'social' => 'Inloggningen fungerade, men det är inte den identitetsleverantör som din organisation har valt. Logga in via deras i stället.',
                'invitation' => 'Din inbjudan är accepterad och du är nu medlem. Logga in via din organisations identitetsleverantör för att komma igång.',
                'password_reset' => 'Ditt nya lösenord har sparats, men det går inte längre att logga in här med lösenord. Logga in via din organisations identitetsleverantör i stället.',
            ],
        ],
        'use_different_email' => 'Använd en annan e-postadress',
        'continue' => 'Fortsätt',
        'sso' => [
            'continue' => 'Fortsätt med single sign-on',
            'or_password' => 'eller använd ditt lösenord',
            'instead' => 'Fortsätt med single sign-on i stället',
        ],
        'forgot_password' => 'Glömt lösenordet?',
        'or' => 'ELLER',
        'continue_with' => 'Fortsätt med :provider',
        'magic_link' => 'Skicka en inloggningslänk till mig',
        'passkey' => 'Logga in med lösennyckel',
        'passkey_failed' => 'Inloggningen med lösennyckel misslyckades.',
        'new_organization' => 'Ny organisation?',
        'create_one' => 'Skapa en',
        'invalid_credentials' => 'Inloggningsuppgifterna stämmer inte med våra register.',
        'social' => [
            'failed' => 'Inloggningen med :provider avbröts eller misslyckades.',
            'unavailable' => 'Inloggning med :provider är inte tillgänglig just nu.',
        ],
        'passkey_errors' => [
            'challenge_expired' => 'Inloggningsförfrågan har gått ut. Försök igen.',
            'not_registered' => 'Lösennyckeln är inte registrerad.',
            'cloned' => 'Lösennyckeln kan ha klonats och avvisades.',
            'unverified' => 'Lösennyckeln kunde inte verifieras.',
            'failed' => 'Något gick fel vid inloggningen.',
        ],
    ],

    'signup' => [
        'title' => 'Kom igång',
        'heading' => [
            'creates_idp' => 'Skapa din arbetsyta',
            'for_app' => 'Skapa ditt konto',
            'default' => 'Skapa din organisation',
        ],
        'lead' => [
            'creates_idp' => 'En arbetsyta för ditt företag och din egen hostade identitetsleverantör — SSO, användare och inloggning som du har full kontroll över, igång på en minut.',
            'join' => 'Registrera dig för :name. Du blir ägare av ditt team och kan bjuda in andra när du är inne.',
            'default' => 'Konfigurera Cbox ID för ditt team på under en minut.',
        ],
        'organization_label' => [
            'creates_idp' => 'Arbetsytans namn',
            'for_app' => 'Team- eller företagsnamn',
            'default' => 'Organisationens namn',
        ],
        'organization_placeholder' => 'Acme AB',
        'name_label' => 'Ditt namn',
        'name_placeholder' => 'Anna Lindqvist',
        'email_label' => 'E-postadress på jobbet',
        'breach_note' => 'Kontrolleras mot kända dataläckor.',
        'submit' => [
            'creates_idp' => 'Skapa arbetsyta',
            'for_app' => 'Skapa konto och fortsätt',
            'default' => 'Skapa organisation',
        ],
        'have_account' => 'Har du redan ett konto?',
        'complete_verification' => 'Slutför verifieringen nedan och skicka sedan igen.',
        'sso_required' => 'Din organisation kräver inloggning via SSO.',
        'account_exists' => 'Det finns redan ett konto med den här e-postadressen.',
        'closed' => [
            'tenant' => 'Du behöver en inbjudan för att gå med. Be den som ansvarar för ditt team om en.',
            'invite_only' => 'Registrering sker endast via inbjudan. Be en administratör om en inbjudan.',
            'closed' => 'Registreringen är stängd för tillfället.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Återställ lösenord',
        'heading' => 'Återställ ditt lösenord',
        'lead' => 'Ange din e-postadress så skickar vi en återställningslänk.',
        'sent_to' => 'Om det finns ett konto för :email är en återställningslänk på väg.',
        'submit' => 'Skicka återställningslänk',
        'remembered' => 'Kom du ihåg det?',
        'throttled' => 'För många försök. Vänta några minuter och försök igen.',
    ],

    'reset_password' => [
        'title' => 'Välj ett nytt lösenord',
        'lead' => 'Välj ett starkt lösenord på minst :count tecken.',
        'confirm_placeholder' => 'Ange ditt nya lösenord igen',
        'submit' => 'Återställ lösenord',
        'invalid_link' => 'Återställningslänken är ogiltig eller har gått ut. Begär en ny.',
        'done' => 'Ditt lösenord har återställts — logga in med ditt nya lösenord.',
    ],

    'change_password' => [
        'title' => 'Välj ett nytt lösenord',
        'lead' => 'Lösenordet du loggade in med har tilldelats av en administratör. Välj ett som bara du känner till innan du fortsätter.',
        'submit' => 'Uppdatera lösenord',
        'mismatch' => 'Lösenorden matchar inte.',
    ],

    'mfa' => [
        'title' => 'Tvåfaktorsverifiering',
        'code' => [
            'lead' => 'Ange den 6-siffriga koden från din autentiseringsapp.',
            'label' => 'Autentiseringskod',
            'switch' => 'Använd en återställningskod i stället',
        ],
        'recovery' => [
            'lead' => 'Ange en av de återställningskoder som du sparade när du aktiverade tvåfaktorsautentisering.',
            'label' => 'Återställningskod',
            'submit' => 'Verifiera återställningskod',
            'switch' => 'Använd din autentiseringsapp i stället',
            'invalid' => 'Återställningskoden är ogiltig eller har redan använts.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Ytterligare verifiering',
        'lead' => 'Den här inloggningen såg ovanlig ut, så vi har skickat en engångskod till :email. Ange den för att fortsätta.',
        'signup_title' => 'Bekräfta din e-postadress',
        'signup_lead' => 'Ange engångskoden vi har skickat till :email för att slutföra skapandet av ditt konto.',
        'code_label' => 'Verifieringskod',
        'resend' => 'Fick du ingen kod? Skicka igen',
        'resent' => 'Vi har skickat en ny kod till :email.',
        'too_many_codes' => 'För många koder har begärts. Vänta en stund och försök igen.',
    ],

    'accounts' => [
        'title' => 'Byt användare',
        'lead' => 'Alla som är inloggade på den här enheten. Välj en, eller logga in som någon annan.',
        'active' => 'Aktiv',
        'add' => 'Logga in som någon annan',
    ],

    'accept_invite' => [
        'title' => 'Acceptera inbjudan',
        'heading' => 'Acceptera din inbjudan',
        'lead_from' => ':inviter har bjudit in dig att hjälpa till att administrera :organization som :role. Du loggar in som :email.',
        'lead' => 'Välj ett lösenord för att hjälpa till att administrera :organization som :role. Du loggar in som :email.',
        'organization_fallback' => 'organisationen',
        'password_label' => 'Välj ett lösenord',
        'breach_note' => 'Kontrolleras mot kända dataläckor.',
        'submit' => 'Acceptera och logga in',
        'no_longer_valid' => 'Den här inbjudan är inte längre giltig. Försök logga in.',
        'invalid' => 'Inbjudan är ogiltig eller har gått ut.',
        'not_completed' => 'Inbjudan kunde inte slutföras.',
    ],

    'confirm_email' => [
        'title' => 'Bekräfta din e-post',
        'heading' => 'Bekräfta din e-postadress',
        'lead' => 'Du har öppnat bekräftelselänken som vi skickade. Bekräfta för att slutföra verifieringen av den här adressen.',
        'action' => 'Bekräfta e-postadress',
        'invalid' => 'Verifieringslänken är ogiltig eller har gått ut.',
        'verified_sign_in' => 'E-postadressen är verifierad — logga in för att öppna din miljö.',
        'verified' => 'Din e-postadress är verifierad — du kan logga in.',
    ],

    'confirm_sign_in' => [
        'title' => 'Logga in',
        'heading' => 'Slutför inloggningen',
        'lead' => 'Du har öppnat en inloggningslänk. Fortsätt för att logga in på den här enheten.',
        'action' => 'Logga in',
        'note' => 'Länken fungerar en gång. Om du inte har bett om att logga in kan du stänga den här sidan — ingenting händer förrän du trycker på knappen.',
        'invalid' => 'Inloggningslänken är ogiltig eller har gått ut.',
    ],

    'first_run' => [
        'title' => 'Konfigurera Cbox ID',
        'lead' => 'Den här installationen är tom. Ta den i anspråk en gång, från maskinen som kör den.',
        'unmigrated' => [
            'title' => 'Databasen för den här installationen har inget schema ännu.',
            'body' => 'Kör :migrate på servern (eller :install, som migrerar och installerar i ett steg) och ladda sedan om den här sidan.',
        ],
        'misconfigured' => [
            'title' => 'Den här installationen är konfigurerad som multi-tenant men saknar kontovärd.',
            'body' => 'Ange :console_host (där konsolen finns), eller ange :single_host för en installation med en enda värd — och ladda sedan om den här sidan. Du kan också köra :install, som frågar efter båda och skriver in dem åt dig.',
        ],
        'token_notice' => [
            'title' => 'Var finns installationstoken?',
            'body' => 'Kör :command på servern — på valfri instans av den här installationen — och klistra in det som skrivs ut. Varje körning skriver ut en ny token som gäller i en timme. Den visas aldrig på den här sidan.',
        ],
        'cli_hint' => 'Föredrar du kommandoraden? :install gör samma sak, och är det enda sättet som också kan välja och registrera installationens upplägg.',
        'token_label' => 'Installationstoken',
        'token_placeholder' => 'Klistra in token från servern',
        'name_label' => 'Ditt namn',
        'name_placeholder' => 'Root-operatör',
        'email_label' => 'Din e-postadress',
        'environment_label' => 'Namnge din första miljö',
        'environment_hint' => 'En miljö är den strikta isoleringsgränsen — med egna användare, nycklar och egen utfärdare (issuer).',
        'environment_default' => 'Produktion',
        'organization_label' => 'Organisationens namn',
        'organization_hint' => 'Den här installationen är konfigurerad som multi-tenant, så installationen skapar också den första arbetsytan — organisationen som äger miljöer och fakturering.',
        'organization_placeholder' => 'Ditt företag',
        'submit' => 'Installera',
        'token_mismatch' => 'Installationstoken matchar inte den här installationens, eller så har den gått ut. Skriv ut en ny med php artisan cbox-id:setup-token.',
    ],

    'join_organization' => [
        'title' => 'Gå med i :organization',
        'heading' => 'Gå med i :organization?',
        'lead' => 'Du har blivit inbjuden att gå med i den här organisationen. Acceptera för att bli medlem och logga in.',
        'lead_app' => 'Du har blivit inbjuden att gå med i den här organisationen. Acceptera för att bli medlem, så tar vi dig till :app.',
        'action' => 'Acceptera inbjudan',
        'note' => 'Väntade du dig inte det här? Stäng den här sidan — ingenting händer om du inte accepterar.',
        'facts' => [
            'organization' => 'Organisation',
            'invited_by' => 'Inbjuden av',
            'built_in_role' => 'Inbyggd roll',
            'custom_roles' => 'Anpassade roller',
            'app_roles' => 'Roller i :app',
            'email' => 'Din e-postadress',
            'app' => 'App',
        ],
        'invalid' => 'Inbjudan är ogiltig eller har gått ut.',
    ],

    'link_confirm' => [
        'title' => 'Koppla ditt konto',
        'heading' => 'Koppla :provider?',
        'lead' => 'Någon loggade precis in med :provider — en adress som redan hör till ditt konto.',
        'lead_email' => 'Någon loggade precis in med :provider som :email — en adress som redan hör till ditt konto.',
        'was_you' => ':emphasis, koppla det så kan du från och med nu logga in med :provider eller med ditt lösenord.',
        'was_you_emphasis' => 'Om det var du',
        'was_not_you' => ':emphasis, avböj. Någon annan försökte logga in med din e-postadress. Ingenting läggs till på ditt konto, och ditt lösenord fungerar som tidigare.',
        'was_not_you_emphasis' => 'Om det inte var du',
        'decline' => 'Nej, det var inte jag',
        'connect' => 'Ja, koppla :provider',
        'disconnect_hint' => 'Du kan när som helst koppla bort :provider i ditt kontos säkerhetsinställningar.',
    ],

    'open_portal_setup' => [
        'title' => 'Administratörskonfiguration',
        'heading' => 'Konfigurera inloggning för din organisation',
        'lead' => 'Du har fått en konfigurationslänk för din organisation — single sign-on, katalogsynkronisering, domäner, loggströmmar eller förnyelse av ett certifikat. Fortsätt för att öppna konfigurationssidan.',
        'action' => 'Öppna konfigurationen',
        'note' => 'Länken fungerar en gång, och konfigurationssessionen som den öppnar har en tidsgräns. Öppna den när du är redo att slutföra.',
    ],
];
