<?php

declare(strict_types=1);

// French: the mail the hosted flows send. `:brand` is the deployment's configured product name.
return [
    'layout' => [
        'footer' => '© :year :brand · Ceci est un message automatique envoyé par votre plateforme d’identité.',
    ],

    'common' => [
        'paste_link' => 'Ou collez ce lien dans votre navigateur :',
    ],

    'roles' => [
        'owner' => 'Propriétaire',
        'admin' => 'Administrateur',
        'developer' => 'Développeur',
        'member' => 'Membre',
        'viewer' => 'Lecteur',
    ],

    'magic_link' => [
        'subject' => 'Votre lien de connexion à :brand',
        'heading' => 'Connexion à :brand',
        'body' => 'Cliquez sur le bouton ci-dessous pour vous connecter. Ce lien est à usage unique et expire dans 15 minutes. Si vous ne l’avez pas demandé, vous pouvez ignorer cet e-mail sans risque.',
        'button' => 'Se connecter à :brand',
    ],

    'password_reset' => [
        'subject' => 'Réinitialisez votre mot de passe :brand',
        'heading' => 'Réinitialisez votre mot de passe',
        'body' => 'Nous avons reçu une demande de réinitialisation de votre mot de passe :brand. Cliquez sur le bouton ci-dessous pour en choisir un nouveau. Ce lien est à usage unique et expire dans 60 minutes. Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail sans risque — votre mot de passe ne sera pas modifié.',
        'button' => 'Réinitialiser le mot de passe',
    ],

    'email_verification' => [
        'subject' => 'Confirmez votre adresse e-mail :brand',
        'heading' => 'Confirmez votre adresse e-mail',
        'body' => 'Bienvenue sur :brand. Confirmez qu’il s’agit bien de votre adresse e-mail pour finir de sécuriser votre compte. Ce lien est à usage unique et expire dans 24 heures.',
        'button' => 'Confirmer l’adresse e-mail',
    ],

    'admin_assigned_password' => [
        'subject' => 'Votre mot de passe :brand a été réinitialisé',
        'heading' => 'Votre mot de passe a été réinitialisé',
        'body' => 'Un administrateur a défini un nouveau mot de passe pour votre compte. Utilisez-le pour vous connecter ci-dessous.',
        'temporary' => 'Il vous sera immédiatement demandé de choisir votre propre mot de passe.',
        'expires' => 'Ce mot de passe cessera de fonctionner le :date ; connectez-vous donc avant cette date.',
        'not_expected' => 'Si vous ne vous attendiez pas à ce message, contactez votre administrateur — une personne ayant accès à la console de votre organisation a effectué cette modification, et celle-ci est consignée dans le journal d’audit.',
    ],

    'invitation' => [
        'subject' => ':inviter vous invite à rejoindre :organization',
        'heading' => 'Rejoignez :organization',
        'invited' => ':inviter vous invite à rejoindre :organization.',
        'invited_as' => ':inviter vous invite à rejoindre :organization en tant que :role.',
        'accept_app' => 'Acceptez pour vous connecter à :app avec votre compte :organization.',
        'accept' => 'Acceptez pour configurer votre compte et vous connecter.',
        'button' => 'Voir l’invitation',
        'note' => 'Le lien ouvre une page sur laquelle vous confirmez — rien ne se passe tant que vous ne l’avez pas fait. Il expire dans 7 jours. Si vous ne vous attendiez pas à cette invitation, vous pouvez l’ignorer.',
    ],

    'organization_invite' => [
        'subject' => ':inviter vous invite à administrer :organization sur :brand',
        'heading' => 'Aidez à gérer :organization sur :brand',
        'invited' => ':inviter vous invite à administrer :organization sur :brand — la console de ses fournisseurs d’identité : environnements, membres et facturation. Acceptez pour définir un mot de passe et vous connecter.',
        'invited_as' => ':inviter vous invite à administrer :organization en tant que :role sur :brand — la console de ses fournisseurs d’identité : environnements, membres et facturation. Acceptez pour définir un mot de passe et vous connecter.',
        'button' => 'Accepter l’invitation',
    ],

    'portal_link' => [
        'subject' => 'Configurez :organization sur :brand',
        'heading' => 'Configurez :organization',
        'lead' => 'Vous êtes invité à configurer les éléments suivants pour :organization sur :brand. Aucun compte n’est nécessaire — le bouton ci-dessous suffit.',
        'intents' => [
            'sso' => 'Le single sign-on (SSO) avec votre fournisseur d’identité',
            'dsync' => 'La synchronisation de l’annuaire (SCIM)',
            'domain_verification' => 'La vérification de vos domaines de messagerie',
            'log_streams' => 'L’envoi de votre journal d’audit vers votre SIEM',
            'certificate_renewal' => 'Le renouvellement de votre certificat de signature SAML',
            'audit_logs' => 'La consultation de vos journaux d’audit (lecture seule)',
        ],
        'button' => 'Commencer la configuration',
        'expires' => 'Le lien fonctionne une seule fois, jusqu’au :date. S’il expire, demandez-en un nouveau.',
    ],

    'certificate_expiring' => [
        'subject' => 'Le SSO via :connection cessera de fonctionner dans :count jour|Le SSO via :connection cessera de fonctionner dans :count jours',
        'subject_expired' => 'Le SSO via :connection a cessé de fonctionner',
        'heading' => 'Votre certificat SAML expire bientôt',
        'heading_expired' => 'Votre certificat SAML a expiré',
        'lead' => 'Le certificat de signature de :connection, la connexion SSO de :organization, expire le :date. Passé cette date, plus personne ne pourra se connecter par ce biais.',
        'lead_expired' => 'Le certificat de signature de :connection, la connexion SSO de :organization, a expiré le :date. Personne ne peut se connecter par ce biais tant qu’il n’a pas été renouvelé.',
        'what_to_do' => 'Demandez à la personne qui gère votre fournisseur d’identité son nouveau certificat de signature, puis téléversez-le dans votre console d’administration — ou demandez à votre administrateur un lien vers le portail d’administration pour le téléverser.',
        'why' => 'Vous recevez ce message parce que vous êtes propriétaire ou administrateur de cette organisation sur :brand.',
    ],
];
