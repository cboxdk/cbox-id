<?php

declare(strict_types=1);

// Danish: the OAuth authorization pages (see lang/en/oauth.php).
return [
    'signed_in_as' => 'Logget ind som :account',
    'cancel_and_return' => 'Annuller og gå tilbage til :client',

    'consent' => [
        'title' => 'Giv adgang',
        'heading' => 'Giv :client adgang',
        'wants_access' => ':client vil have adgang til din :account-konto.',
        'registered_by' => 'Registreret af :owner. Navnet på en app vælges af den der har registreret den.',
        'unknown_owner' => 'en organisation der ikke længere findes',
        'in_organization' => 'I :organization',
        'will_allow' => 'Dette giver :client lov til at',
        'cancel' => 'Annuller',
        'authorize' => 'Giv adgang',
        'redirect_notice' => 'Når du har givet adgang, bliver du sendt videre til :host.',
        // A client that registered itself (RFC 7591, or a client ID metadata document).
        'self_registered_owner' => 'appen selv',
        'self_registered' => 'Denne app har registreret sig selv. Ingen hos :account har gennemgået den – fortsæt kun hvis du selv har startet dette login.',
        'published_by' => 'Beskrevet af :host – det eneste ved denne app der er blevet kontrolleret.',
        'about_app' => 'Om denne app',
        // Management-plane scopes an agent acts with as the person.
        'critical' => 'Kritisk',
        'acts_as_you' => 'Alt hvad den gør på dine vegne, er begrænset til det du selv må, og bliver registreret i revisionsloggen.',
        'critical_notice' => 'Kritiske handlinger skal stadig godkendes på din enhed – hver gang.',

        'scopes' => [
            'openid' => 'Bekræfte din identitet',
            'profile' => 'Se dit navn',
            'email' => 'Se din e-mailadresse',
            'offline_access' => 'Holde dig logget ind',
            'organizations' => 'Se hvilke organisationer du er medlem af',
            'groups' => 'Se dine roller',
        ],
    ],

    'failure' => [
        'title' => 'Godkendelsen mislykkedes',
        'heading' => 'Godkendelsen mislykkedes',
        'back' => 'Tilbage til :name',
        'generic' => 'Anmodningen om godkendelse kunne ikke gennemføres.',
        'expired' => 'Anmodningen om godkendelse er udløbet eller allerede brugt. Start forfra.',
        'par_required' => 'Denne server kræver pushed authorization requests. Send først anmodningen til /oauth/par.',
        'unknown_client' => 'Ukendt klient. Appen er ikke registreret i Cbox ID.',
        'client_document' => 'Appens beskrivelse kunne ikke læses. Den skal ligge på den adresse appen har opgivet som sit ID, men dokumentet mangler, kan ikke nås eller er ugyldigt.',
        'redirect_mismatch' => 'Redirect-URI’en matcher ingen af dem der er registreret for denne app.',
        'stale' => 'Anmodningen om godkendelse kan ikke længere gennemføres. Start forfra.',
        'account_attention' => 'Der er noget på din konto du skal tage dig af, før du kan fortsætte. Log ind igen.',
        'step_up' => 'Appen kræver et nyere eller stærkere login. Start forfra.',
        'no_workspace' => 'Dette login forbinder en agent med et arbejdsområde på Cbox ID, men din konto er ikke med i teamet for noget arbejdsområde. Bed en ejer af arbejdsområdet om at invitere dig, eller forbind i stedet agenten via dit miljøs egen adresse.',
    ],

    'organization' => [
        'title' => 'Vælg en organisation',
        'heading' => 'Vælg en organisation',
        'lead' => ':client bruger den organisation du vælger, og din rolle i den.',
        'none_create' => 'Du er endnu ikke medlem af nogen organisation her. Opret en for at fortsætte.',
        'none_invite' => 'Du er endnu ikke medlem af nogen organisation her. Bed nogen om at invitere dig til deres, og prøv så igen.',
        'list_label' => 'Dine organisationer',
        'suggested' => 'Foreslået',
        'continuing_with' => 'Fortsætter med :name',
        'create' => 'Opret en organisation',
        'required' => 'Vælg en organisation for at fortsætte.',
        'not_member' => 'Du er ikke aktivt medlem af den organisation.',
        'roles' => [
            'owner' => 'Ejer',
            'admin' => 'Administrator',
            'developer' => 'Udvikler',
            'member' => 'Medlem',
            'viewer' => 'Læser',
        ],
    ],

    'create_organization' => [
        'title' => 'Opret en organisation',
        'heading' => 'Opret en organisation',
        'lead' => 'Dit team eller din virksomhed i :client. Du bliver ejer og kan invitere andre når du er kommet ind.',
        'name_label' => 'Organisationens navn',
        'submit' => 'Opret og fortsæt',
        'choose_existing' => 'Vælg en eksisterende organisation',
        'name_required' => 'Giv din organisation et navn.',
        'not_offered' => 'Du kan ikke oprette en organisation her. Bed en administrator om at invitere dig til en.',
        'too_many' => 'Du har oprettet flere organisationer på kort tid. Prøv igen om :count minut.|Du har oprettet flere organisationer på kort tid. Prøv igen om :count minutter.',
    ],
];
