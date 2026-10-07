<?php

declare(strict_types=1);

// French: the admin setup portal (pages/portal/*). Protocol terms stay as they are.
return [
    'layout' => [
        'badge' => 'Portail de configuration administrateur',
        'toggle_theme' => 'Changer de thème',
    ],

    'copy' => [
        'copy' => 'Copier',
        'copied' => 'Copié',
        'failed' => 'Échec de la copie — sélectionnez et copiez manuellement',
    ],

    'confirm' => [
        'cancel' => 'Annuler',
        'type_to_confirm' => 'Saisissez :name pour confirmer',
        'hint' => 'Exactement comme affiché — le bouton :action reste désactivé tant que la saisie ne correspond pas.',
    ],

    'setup' => [
        'title' => 'Configurer SSO et SCIM',
        'heading' => 'Configurer la connexion d’entreprise',
        'heading_for' => 'Configurer la connexion d’entreprise · :organization',
        'description' => 'Vous avez reçu une invitation à configurer le single sign-on (SSO) pour cette organisation. Rien d’autre dans l’organisation n’est accessible depuis cette page.',
        'step' => 'Étape :number',
        'finish' => 'Terminer la configuration',

        'domain' => [
            'heading' => 'Vérifiez votre domaine',
            'lead' => 'Ajoutez un enregistrement DNS pour prouver que vous possédez le domaine avec lequel votre équipe se connecte. C’est ce qui redirige ces utilisateurs vers le SSO.',
            'label' => 'Domaine',
            'add' => 'Ajouter le domaine',
            'record' => 'Ajoutez cet enregistrement TXT pour :domain, puis cliquez sur Vérifier.',
            'record_type' => 'Type',
            'record_host' => 'Hôte',
            'record_value' => 'Valeur',
            'empty' => 'Aucun domaine ajouté pour le moment.',
            'verified' => 'Vérifié',
            'pending' => 'DNS en attente',
            'check' => 'Vérifier',
            'check_label' => 'Vérifier le DNS pour :domain',
            'remove' => 'Supprimer',
            'remove_label' => 'Supprimer :domain',
            'remove_title' => 'Supprimer :domain ?',
            'remove_consequence' => 'Les personnes qui se connectent avec une adresse de ce domaine ne seront plus redirigées ici.',
            'invalid' => 'Saisissez un domaine valide, par exemple acme.com.',
            'claimed' => 'Ce domaine est déjà revendiqué par une autre organisation.',
            'verified_status' => 'Domaine vérifié — les utilisateurs de ce domaine peuvent désormais se connecter via SSO.',
            'not_found' => 'Nous n’avons pas encore trouvé l’enregistrement TXT — la propagation DNS peut prendre quelques minutes.',
            'removed' => 'Domaine supprimé.',
        ],

        'connection' => [
            'heading' => 'Connexion SSO',
            'new' => 'Nouvelle connexion',
            'empty' => 'Aucune connexion SSO pour le moment.',
            'active' => 'Active',
            'statuses' => [
                'draft' => 'brouillon',
                'inactive' => 'inactive',
            ],
            'activate' => 'Activer',
            'activate_label' => 'Activer :name',
            'name_label' => 'Nom de la connexion',
            'protocol_label' => 'Protocole',
            'idp_entity_id' => 'Entity ID de l’IdP',
            'idp_sso_url' => 'URL SSO de l’IdP',
            'sp_entity_id' => 'Entity ID du SP',
            'sp_acs_url' => 'ACS URL du SP',
            'idp_certificate' => 'Certificat X.509 de l’IdP',
            'issuer' => 'Émetteur (Issuer)',
            'client_id' => 'ID client',
            'client_secret' => 'Secret client',
            'signing_key' => 'Clé de signature',
            'create' => 'Créer la connexion',
            'cancel' => 'Annuler',
            'created' => 'Connexion créée en tant que brouillon.',
            'activated' => 'Connexion activée.',
            'discovery_failed' => 'Impossible de lire la configuration OpenID du fournisseur — vérifiez l’URL de l’émetteur. (:reason)',
        ],

        'directory' => [
            'step' => 'Synchronisation de l’annuaire',
            'heading' => 'Synchronisation de l’annuaire (SCIM)',
            'new' => 'Nouvel annuaire',
            'base_url_help' => 'Configurez votre fournisseur d’identité (Okta, Microsoft Entra) avec cette URL de base et authentifiez-vous avec le jeton Bearer d’un annuaire.',
            'copy_base_url' => 'Copier l’URL de base SCIM',
            'token_heading' => 'Jeton Bearer pour « :name »',
            'token_once' => 'Copiez-le maintenant — il ne s’affiche qu’une seule fois et ne pourra plus être récupéré.',
            'copy_token' => 'Copier le jeton',
            'name_label' => 'Nom de l’annuaire',
            'register' => 'Enregistrer l’annuaire',
            'cancel' => 'Annuler',
            'empty' => 'Aucun annuaire connecté pour le moment.',
            'active' => 'Actif',
            'paused' => 'En pause',
        ],
    ],

    'done' => [
        'title' => 'Tout est prêt',
        'heading' => 'Tout est prêt',
        'body' => 'La connexion d’entreprise pour :organization est configurée. Ce lien de configuration a été utilisé et est désormais fermé. Vous pouvez fermer cette fenêtre.',
        'this_organization' => 'cette organisation',
    ],

    'expired' => [
        'title' => 'Lien indisponible',
        'heading' => 'Ce lien de configuration n’est plus valide',
        'body' => 'Le lien a peut-être expiré ou a déjà été utilisé. Par sécurité, les liens de configuration sont à usage unique et à durée limitée. Demandez à la personne qui vous a envoyé l’invitation de vous en envoyer un nouveau.',
    ],
];
