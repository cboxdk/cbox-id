<?php

declare(strict_types=1);

// French: the OAuth authorization pages (pages/oauth/*).
return [
    'signed_in_as' => 'Connecté en tant que :account',
    'cancel_and_return' => 'Annuler et revenir à :client',

    'consent' => [
        'title' => 'Autoriser',
        'heading' => 'Autoriser :client',
        'wants_access' => ':client souhaite accéder à votre compte :account.',
        'registered_by' => 'Application enregistrée par :owner — le nom d’une application est choisi par celui qui l’a enregistrée.',
        'unknown_owner' => 'une organisation qui n’existe plus',
        'in_organization' => 'Dans :organization',
        'will_allow' => 'Autorisations accordées à :client',
        'cancel' => 'Annuler',
        'authorize' => 'Autoriser',
        'redirect_notice' => 'Après l’autorisation, vous serez redirigé vers :host.',
        // A client that registered itself (RFC 7591, or a client ID metadata document).
        'self_registered_owner' => 'l’application elle-même',
        'self_registered' => 'Cette application s’est enregistrée elle-même. Personne chez :account ne l’a vérifiée — ne continuez que si vous avez lancé cette connexion vous-même.',
        'published_by' => 'Décrite par :host — la seule chose vérifiée au sujet de cette application.',
        'about_app' => 'À propos de cette application',
        // Management-plane scopes an agent acts with as the person.
        'critical' => 'Critique',
        'acts_as_you' => 'Tout ce qu’elle fait en votre nom est limité à ce que vous pouvez faire vous-même, et consigné dans le journal d’audit.',
        'critical_notice' => 'Les actions critiques attendent toujours votre approbation sur votre appareil, à chaque fois.',

        'scopes' => [
            'openid' => 'Vérifier votre identité',
            'profile' => 'Votre nom',
            'email' => 'Votre adresse e-mail',
            'offline_access' => 'Rester connecté',
            'organizations' => 'Les organisations dont vous faites partie',
            'groups' => 'Vos rôles',
        ],
    ],

    'failure' => [
        'title' => 'Échec de l’autorisation',
        'heading' => 'Échec de l’autorisation',
        'back' => 'Retour à :name',
        'generic' => 'Cette demande d’autorisation n’a pas pu aboutir.',
        'expired' => 'Cette demande d’autorisation a expiré ou a déjà été utilisée. Veuillez recommencer.',
        'par_required' => 'Ce serveur exige des requêtes d’autorisation poussées (PAR). Envoyez d’abord la requête à /oauth/par.',
        'unknown_client' => 'Client inconnu. Cette application n’est pas enregistrée auprès de Cbox ID.',
        'client_document' => 'La description de cette application n’a pas pu être lue. Elle est publiée à l’adresse que l’application a donnée comme identifiant, et ce document est absent, inaccessible ou invalide.',
        'redirect_mismatch' => 'L’URI de redirection ne correspond à aucune de celles enregistrées pour cette application.',
        'stale' => 'Cette demande d’autorisation ne peut plus aboutir. Veuillez recommencer.',
        'account_attention' => 'Votre compte nécessite votre attention avant de pouvoir continuer. Veuillez vous reconnecter.',
        'step_up' => 'Cette application exige une authentification plus récente ou plus forte. Veuillez recommencer.',
    ],

    'organization' => [
        'title' => 'Choisissez une organisation',
        'heading' => 'Choisissez une organisation',
        'lead' => ':client utilisera l’organisation que vous choisissez, avec le rôle que vous y occupez.',
        'none_create' => 'Vous ne faites encore partie d’aucune organisation ici. Créez-en une pour continuer.',
        'none_invite' => 'Vous ne faites encore partie d’aucune organisation ici. Demandez à quelqu’un de vous inviter dans la sienne, puis réessayez.',
        'list_label' => 'Vos organisations',
        'suggested' => 'Suggérée',
        'continuing_with' => 'Vous continuez avec :name',
        'create' => 'Créer une organisation',
        'required' => 'Choisissez une organisation pour continuer.',
        'not_member' => 'Vous n’êtes pas un membre actif de cette organisation.',
        'roles' => [
            'owner' => 'Propriétaire',
            'admin' => 'Administrateur',
            'developer' => 'Développeur',
            'member' => 'Membre',
            'viewer' => 'Lecteur',
        ],
    ],

    'create_organization' => [
        'title' => 'Créer une organisation',
        'heading' => 'Créer une organisation',
        'lead' => 'Votre équipe ou votre entreprise dans :client. Vous en serez le propriétaire et pourrez inviter des personnes une fois l’organisation créée.',
        'name_label' => 'Nom de l’organisation',
        'submit' => 'Créer et continuer',
        'choose_existing' => 'Choisir une organisation existante',
        'name_required' => 'Donnez un nom à votre organisation.',
        'not_offered' => 'La création d’organisation n’est pas disponible ici. Demandez à un administrateur de vous inviter dans une organisation.',
        'too_many' => 'Vous avez créé plusieurs organisations en peu de temps. Réessayez dans :count minute.|Vous avez créé plusieurs organisations en peu de temps. Réessayez dans :count minutes.',
    ],
];
