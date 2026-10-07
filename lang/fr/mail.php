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
];
