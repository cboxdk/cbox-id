<?php

declare(strict_types=1);

// Norwegian Bokmål: the OAuth authorization pages (pages/oauth/*).
return [
    'signed_in_as' => 'Logget inn som :account',
    'cancel_and_return' => 'Avbryt og gå tilbake til :client',

    'consent' => [
        'title' => 'Godkjenn',
        'heading' => 'Godkjenn :client',
        'wants_access' => ':client vil ha tilgang til :account-kontoen din.',
        'registered_by' => 'Registrert av :owner – navnet på en app velges av den som registrerte den.',
        'unknown_owner' => 'en organisasjon som ikke lenger finnes',
        'in_organization' => 'I :organization',
        'will_allow' => 'Dette gir :client tilgang til følgende',
        'cancel' => 'Avbryt',
        'authorize' => 'Godkjenn',
        'redirect_notice' => 'Du blir sendt videre til :host etter godkjenningen.',
        // A client that registered itself (RFC 7591, or a client ID metadata document).
        'self_registered_owner' => 'appen selv',
        'self_registered' => 'Denne appen har registrert seg selv. Ingen hos :account har gjennomgått den — fortsett bare hvis du selv startet denne påloggingen.',
        'published_by' => 'Beskrevet av :host — det eneste ved denne appen som er kontrollert.',
        'about_app' => 'Om denne appen',
        // Management-plane scopes an agent acts with as the person.
        'critical' => 'Kritisk',
        'acts_as_you' => 'Alt den gjør som deg, er begrenset til det du selv har lov til, og blir ført i revisjonsloggen.',
        'critical_notice' => 'Kritiske handlinger venter fortsatt på din godkjenning på enheten din, hver gang.',

        'scopes' => [
            'openid' => 'Bekrefte identiteten din',
            'profile' => 'Navnet ditt',
            'email' => 'E-postadressen din',
            'offline_access' => 'Forbli innlogget',
            'organizations' => 'Hvilke organisasjoner du tilhører',
            'groups' => 'Rollene dine',
        ],
    ],

    'failure' => [
        'title' => 'Godkjenningen mislyktes',
        'heading' => 'Godkjenningen mislyktes',
        'back' => 'Tilbake til :name',
        'generic' => 'Denne godkjenningsforespørselen kunne ikke fullføres.',
        'expired' => 'Denne godkjenningsforespørselen har utløpt eller er allerede brukt. Start på nytt.',
        'par_required' => 'Denne serveren krever pushed authorization requests (PAR). Send forespørselen til /oauth/par først.',
        'unknown_client' => 'Ukjent klient. Denne applikasjonen er ikke registrert hos Cbox ID.',
        'client_document' => 'Beskrivelsen av applikasjonen kunne ikke leses. Den ligger på adressen applikasjonen oppga som sin ID, og dokumentet mangler, kan ikke nås eller er ugyldig.',
        'redirect_mismatch' => 'Omdirigerings-URI-en samsvarer ikke med noen som er registrert for denne applikasjonen.',
        'stale' => 'Denne godkjenningsforespørselen kan ikke lenger fullføres. Start på nytt.',
        'account_attention' => 'Kontoen din må følges opp før du kan fortsette. Logg inn på nytt.',
        'step_up' => 'Denne applikasjonen krever en nyere eller sterkere innlogging. Start på nytt.',
    ],

    'organization' => [
        'title' => 'Velg en organisasjon',
        'heading' => 'Velg en organisasjon',
        'lead' => ':client bruker organisasjonen du velger, med rollen du har i den.',
        'none_create' => 'Du er ikke med i noen organisasjon her ennå. Opprett en for å fortsette.',
        'none_invite' => 'Du er ikke med i noen organisasjon her ennå. Be noen om å invitere deg til sin, og prøv igjen.',
        'list_label' => 'Organisasjonene dine',
        'suggested' => 'Foreslått',
        'continuing_with' => 'Fortsetter med :name',
        'create' => 'Opprett en organisasjon',
        'required' => 'Velg en organisasjon for å fortsette.',
        'not_member' => 'Du er ikke et aktivt medlem av den organisasjonen.',
        'roles' => [
            'owner' => 'Eier',
            'admin' => 'Administrator',
            'developer' => 'Utvikler',
            'member' => 'Medlem',
            'viewer' => 'Leser',
        ],
    ],

    'create_organization' => [
        'title' => 'Opprett en organisasjon',
        'heading' => 'Opprett en organisasjon',
        'lead' => 'Teamet eller bedriften din i :client. Du blir eier og kan invitere andre når du er inne.',
        'name_label' => 'Organisasjonsnavn',
        'submit' => 'Opprett og fortsett',
        'choose_existing' => 'Velg en eksisterende organisasjon',
        'name_required' => 'Gi organisasjonen din et navn.',
        'not_offered' => 'Det er ikke mulig å opprette en organisasjon her. Be en administrator om å invitere deg til en.',
        'too_many' => 'Du har opprettet flere organisasjoner på kort tid. Prøv igjen om :count minutt.|Du har opprettet flere organisasjoner på kort tid. Prøv igjen om :count minutter.',
    ],
];
