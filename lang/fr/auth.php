<?php

declare(strict_types=1);

// French: the sign-in group (pages/auth/*) and the PHP that speaks on them.
return [
    'common' => [
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'new_password' => 'Nouveau mot de passe',
        'confirm_new_password' => 'Confirmer le nouveau mot de passe',
        'sign_in' => 'Se connecter',
        'back_to_sign_in' => 'Retour à la connexion',
        'check_your_inbox' => 'Consultez votre boîte de réception',
        'verify' => 'Vérifier',
        'cancel_and_sign_out' => 'Annuler et se déconnecter',
        'too_many_attempts' => 'Trop de tentatives. Réessayez dans :count seconde.|Trop de tentatives. Réessayez dans :count secondes.',
        'too_many_requests' => 'Trop de requêtes. Réessayez dans :count seconde.|Trop de requêtes. Réessayez dans :count secondes.',
        'could_not_process' => 'Nous n’avons pas pu traiter cette demande. Veuillez réessayer plus tard.',
        'code_incorrect' => 'Ce code est incorrect ou a expiré.',
    ],

    'password_field' => [
        'show' => 'Afficher le mot de passe',
        'hide' => 'Masquer le mot de passe',
        'policy' => 'Au moins :count caractères',
    ],

    'login' => [
        'title' => 'Connexion',
        'purpose' => [
            'default' => 'Bon retour parmi nous. Accédez à la console d’identité de votre organisation.',
            'device' => 'Connectez-vous pour approuver l’appareil en attente.',
        ],
        'pending_link' => [
            'lead' => 'Quelqu’un s’est connecté avec :provider en utilisant cette adresse e-mail.',
            'body' => 'Cette adresse e-mail possède déjà un compte ici. Connectez-vous ci-dessous et nous vous demanderons si vous souhaitez y associer :provider.',
        ],
        'magic' => [
            'sent_to' => 'Nous avons envoyé un lien de connexion à usage unique à :email.',
            'dev_note' => 'Affiché, car l’envoi d’e-mails n’est pas configuré dans cet environnement.',
        ],
        'mandate' => [
            'heading' => ':organization exige une connexion via SSO',
            'continue' => 'Continuer vers :organization',
            'no_provider' => 'Aucun fournisseur d’identité n’est encore connecté pour :organization : il n’y a donc nulle part où vous rediriger. Demandez à un administrateur de terminer la configuration du single sign-on (SSO).',
            'your_organization' => 'Votre organisation',
            'reasons' => [
                'password' => 'Votre mot de passe est correct, mais il ne permet plus de se connecter ici. Connectez-vous plutôt via le fournisseur d’identité de votre organisation.',
                'magic_link' => 'Ce lien de connexion a fonctionné, et il ne peut plus être réutilisé. Les liens envoyés par e-mail ne permettent plus de se connecter ici — connectez-vous plutôt via le fournisseur d’identité de votre organisation.',
                'passkey' => 'Votre clé d’accès a fonctionné. Elle ne permet simplement plus de se connecter ici — connectez-vous plutôt via le fournisseur d’identité de votre organisation.',
                'social' => 'Cette connexion a fonctionné, mais ce n’est pas le fournisseur d’identité choisi par votre organisation. Connectez-vous plutôt via celui-ci.',
                'invitation' => 'Votre invitation est acceptée et vous êtes désormais membre. Connectez-vous via le fournisseur d’identité de votre organisation pour commencer.',
                'password_reset' => 'Votre nouveau mot de passe est enregistré, mais un mot de passe ne permet plus de se connecter ici. Connectez-vous plutôt via le fournisseur d’identité de votre organisation.',
            ],
        ],
        'use_different_email' => 'Utiliser une autre adresse e-mail',
        'continue' => 'Continuer',
        'sso' => [
            'continue' => 'Continuer avec le SSO',
            'or_password' => 'ou utilisez votre mot de passe',
            'instead' => 'Continuer plutôt avec le SSO',
        ],
        'forgot_password' => 'Mot de passe oublié ?',
        'or' => 'OU',
        'continue_with' => 'Continuer avec :provider',
        'magic_link' => 'M’envoyer un lien de connexion',
        'passkey' => 'Se connecter avec une clé d’accès',
        'passkey_failed' => 'Échec de la connexion avec la clé d’accès.',
        'new_organization' => 'Nouvelle organisation ?',
        'create_one' => 'Créez-en une',
        'invalid_credentials' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
        'social' => [
            'failed' => 'La connexion avec :provider a été annulée ou a échoué.',
            'unavailable' => 'La connexion avec :provider est indisponible pour le moment.',
        ],
        'passkey_errors' => [
            'challenge_expired' => 'Le défi de connexion a expiré. Réessayez.',
            'not_registered' => 'Cette clé d’accès n’est pas enregistrée.',
            'cloned' => 'Cette clé d’accès a peut-être été clonée et a été refusée.',
            'unverified' => 'Cette clé d’accès n’a pas pu être vérifiée.',
            'failed' => 'Une erreur s’est produite lors de la connexion.',
        ],
    ],

    'signup' => [
        'title' => 'Commencer',
        'heading' => [
            'creates_idp' => 'Créez votre espace de travail',
            'for_app' => 'Créez votre compte',
            'default' => 'Créez votre organisation',
        ],
        'lead' => [
            'creates_idp' => 'Un espace de travail pour votre entreprise et votre propre fournisseur d’identité hébergé — SSO, utilisateurs et connexion entièrement sous votre contrôle, opérationnels en une minute.',
            'join' => 'Inscrivez-vous à :name. Vous serez le propriétaire de votre équipe et pourrez inviter des personnes une fois votre compte créé.',
            'default' => 'Configurez Cbox ID pour votre équipe en moins d’une minute.',
        ],
        'organization_label' => [
            'creates_idp' => 'Nom de l’espace de travail',
            'for_app' => 'Nom de l’équipe ou de l’entreprise',
            'default' => 'Nom de l’organisation',
        ],
        'organization_placeholder' => 'Acme Inc.',
        'name_label' => 'Votre nom',
        'name_placeholder' => 'Camille Martin',
        'email_label' => 'Adresse e-mail professionnelle',
        'breach_note' => 'Vérifié par rapport aux fuites de données connues.',
        'submit' => [
            'creates_idp' => 'Créer l’espace de travail',
            'for_app' => 'Créer le compte et continuer',
            'default' => 'Créer l’organisation',
        ],
        'have_account' => 'Vous avez déjà un compte ?',
        'complete_verification' => 'Veuillez effectuer la vérification ci-dessous, puis renvoyer le formulaire.',
        'sso_required' => 'Votre organisation exige une connexion via SSO.',
        'account_exists' => 'Un compte associé à cette adresse e-mail existe déjà.',
        'closed' => [
            'tenant' => 'Une invitation est nécessaire pour rejoindre l’équipe. Demandez-en une à la personne qui gère votre équipe.',
            'invite_only' => 'Les inscriptions se font uniquement sur invitation. Demandez une invitation à un administrateur.',
            'closed' => 'Les inscriptions sont actuellement fermées.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Réinitialiser le mot de passe',
        'heading' => 'Réinitialisez votre mot de passe',
        'lead' => 'Saisissez votre adresse e-mail et nous vous enverrons un lien de réinitialisation.',
        'sent_to' => 'Si un compte existe pour :email, un lien de réinitialisation y a été envoyé.',
        'submit' => 'Envoyer le lien',
        'remembered' => 'Vous vous en souvenez ?',
        'throttled' => 'Trop de tentatives. Veuillez patienter quelques minutes, puis réessayer.',
    ],

    'reset_password' => [
        'title' => 'Choisissez un nouveau mot de passe',
        'lead' => 'Choisissez un mot de passe robuste d’au moins :count caractères.',
        'confirm_placeholder' => 'Saisissez à nouveau votre nouveau mot de passe',
        'submit' => 'Réinitialiser le mot de passe',
        'invalid_link' => 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.',
        'done' => 'Votre mot de passe a été réinitialisé — connectez-vous avec votre nouveau mot de passe.',
    ],

    'change_password' => [
        'title' => 'Choisissez un nouveau mot de passe',
        'lead' => 'Le mot de passe que vous avez utilisé pour vous connecter a été attribué par un administrateur. Avant de continuer, choisissez-en un que personne d’autre ne connaît.',
        'submit' => 'Mettre à jour le mot de passe',
        'mismatch' => 'Les mots de passe ne correspondent pas.',
    ],

    'mfa' => [
        'title' => 'Vérification à deux facteurs',
        'code' => [
            'lead' => 'Saisissez le code à 6 chiffres de votre application d’authentification.',
            'label' => 'Code d’authentification',
            'switch' => 'Utiliser plutôt un code de récupération',
            'sms_switch' => 'Recevoir plutôt un code par SMS',
        ],
        'recovery' => [
            'lead' => 'Saisissez l’un des codes de récupération que vous avez enregistrés lors de l’activation de l’authentification à deux facteurs.',
            'label' => 'Code de récupération',
            'submit' => 'Vérifier le code',
            'switch' => 'Utiliser plutôt votre application d’authentification',
            'invalid' => 'Ce code de récupération est invalide ou a déjà été utilisé.',
            'back' => 'Utiliser une autre méthode',
        ],
        'sms' => [
            'lead' => 'Nous enverrons un code par SMS au numéro de téléphone de votre compte.',
            'send' => 'M’envoyer un code par SMS',
            'sent' => 'Nous avons envoyé un code au :number. Il expire dans quelques minutes.',
            'resend' => 'Envoyer un nouveau code',
            'label' => 'Code reçu par SMS',
            'switch' => 'Utiliser plutôt votre application d’authentification',
            'wait' => 'Un code vient d’être envoyé. Patientez un instant avant d’en demander un autre.',
            'failed' => 'Nous n’avons pas pu envoyer le SMS. Réessayez dans un instant ou utilisez un code de récupération.',
            'unavailable' => 'Les codes par SMS ne sont pas disponibles pour ce compte.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Vérification supplémentaire',
        'lead' => 'Cette connexion semblait inhabituelle, nous avons donc envoyé un code à usage unique à :email. Saisissez-le pour continuer.',
        'signup_title' => 'Confirmez votre adresse e-mail',
        'signup_lead' => 'Pour terminer la création de votre compte, saisissez le code à usage unique envoyé à :email.',
        'code_label' => 'Code de vérification',
        'resend' => 'Vous ne l’avez pas reçu ? Renvoyer le code',
        'resent' => 'Nous avons envoyé un nouveau code à :email.',
        'too_many_codes' => 'Trop de codes demandés. Veuillez patienter un instant, puis réessayer.',
    ],

    'accounts' => [
        'title' => 'Changer d’utilisateur',
        'lead' => 'Toutes les personnes connectées sur cet appareil. Choisissez-en une, ou connectez-vous avec un autre compte.',
        'active' => 'Actif',
        'add' => 'Se connecter avec un autre compte',
    ],

    'accept_invite' => [
        'title' => 'Accepter l’invitation',
        'heading' => 'Acceptez votre invitation',
        'lead_from' => ':inviter vous invite à aider à gérer :organization en tant que :role. Vous vous connecterez avec l’adresse :email.',
        'lead' => 'Définissez un mot de passe pour aider à gérer :organization en tant que :role. Vous vous connecterez avec l’adresse :email.',
        'organization_fallback' => 'l’organisation',
        'password_label' => 'Choisissez un mot de passe',
        'breach_note' => 'Vérifié par rapport aux fuites de données connues.',
        'submit' => 'Accepter et se connecter',
        'no_longer_valid' => 'Cette invitation n’est plus valide. Essayez de vous connecter.',
        'invalid' => 'Cette invitation est invalide ou a expiré.',
        'not_completed' => 'Cette invitation n’a pas pu être finalisée.',
    ],

    'confirm_email' => [
        'title' => 'Confirmez votre e-mail',
        'heading' => 'Confirmez votre adresse e-mail',
        'lead' => 'Vous avez ouvert le lien de confirmation que nous vous avons envoyé. Confirmez pour terminer la vérification de cette adresse.',
        'action' => 'Confirmer l’adresse e-mail',
        'invalid' => 'Ce lien de vérification est invalide ou a expiré.',
        'verified_sign_in' => 'Adresse e-mail vérifiée — connectez-vous pour ouvrir votre environnement.',
        'verified' => 'Votre adresse e-mail est vérifiée — vous pouvez vous connecter.',
    ],

    'confirm_sign_in' => [
        'title' => 'Connexion',
        'heading' => 'Finalisez votre connexion',
        'lead' => 'Vous avez ouvert un lien de connexion. Continuez pour vous connecter sur cet appareil.',
        'action' => 'Se connecter',
        'note' => 'Le lien ne fonctionne qu’une seule fois. Si vous n’avez pas demandé à vous connecter, fermez cette page — rien ne se passe tant que vous n’appuyez pas sur le bouton.',
        'invalid' => 'Ce lien de connexion est invalide ou a expiré.',
    ],

    'first_run' => [
        'title' => 'Configurer Cbox ID',
        'lead' => 'Ce déploiement est vide. Revendiquez-le une seule fois, depuis la machine qui l’exécute.',
        'unmigrated' => [
            'title' => 'La base de données de ce déploiement n’a pas encore de schéma.',
            'body' => 'Exécutez :migrate sur le serveur (ou :install, qui effectue la migration et l’installation en une seule étape), puis rechargez cette page.',
        ],
        'misconfigured' => [
            'title' => 'Ce déploiement est configuré en mode multilocataire, mais n’a pas d’hôte de compte.',
            'body' => 'Définissez :console_host (l’emplacement de la console), ou :single_host pour une installation à hôte unique, puis rechargez cette page. Vous pouvez aussi exécuter :install, qui vous demande ces deux valeurs et les écrit pour vous.',
        ],
        'token_notice' => [
            'title' => 'Où se trouve le jeton de configuration ?',
            'body' => 'Exécutez :command sur le serveur — sur n’importe quelle instance de ce déploiement — et collez ce qu’elle affiche. Chaque exécution affiche un nouveau jeton, valable une heure. Il n’est jamais affiché sur cette page.',
        ],
        'cli_hint' => 'Vous préférez la ligne de commande ? :install fait la même chose, et c’est la seule méthode qui permet aussi de choisir et d’enregistrer la topologie du déploiement.',
        'token_label' => 'Jeton de configuration',
        'token_placeholder' => 'Collez le jeton fourni par le serveur',
        'name_label' => 'Votre nom',
        'name_placeholder' => 'Administrateur principal',
        'email_label' => 'Votre adresse e-mail',
        'environment_label' => 'Nommez votre premier environnement',
        'environment_hint' => 'Un environnement est la frontière d’isolation stricte — avec ses propres utilisateurs, clés et émetteur.',
        'environment_default' => 'Production',
        'organization_label' => 'Nom de l’organisation',
        'organization_hint' => 'Ce déploiement est configuré en mode multilocataire ; l’installation crée donc aussi le premier espace de travail — l’organisation propriétaire des environnements et de la facturation.',
        'organization_placeholder' => 'Votre entreprise',
        'submit' => 'Installer ce déploiement',
        'token_mismatch' => 'Ce jeton de configuration ne correspond pas à celui de ce déploiement, ou il a expiré. Affichez-en un nouveau avec php artisan cbox-id:setup-token.',
    ],

    'join_organization' => [
        'title' => 'Rejoindre :organization',
        'heading' => 'Rejoindre :organization ?',
        'lead' => 'Vous avez reçu une invitation à rejoindre cette organisation. Acceptez pour en devenir membre et vous connecter.',
        'lead_app' => 'Vous avez reçu une invitation à rejoindre cette organisation. Acceptez pour en devenir membre, et nous vous redirigerons vers :app.',
        'action' => 'Accepter l’invitation',
        'note' => 'Vous ne vous y attendiez pas ? Fermez cette page — rien ne se passe si vous n’acceptez pas.',
        'facts' => [
            'organization' => 'Organisation',
            'invited_by' => 'Invitation de',
            'built_in_role' => 'Rôle intégré',
            'custom_roles' => 'Rôles personnalisés',
            'app_roles' => 'Rôles dans :app',
            'email' => 'Votre adresse e-mail',
            'app' => 'Application',
        ],
        'invalid' => 'Cette invitation est invalide ou a expiré.',
    ],

    'link_confirm' => [
        'title' => 'Associer votre compte',
        'heading' => 'Associer :provider ?',
        'lead' => 'Quelqu’un vient de se connecter avec :provider — une adresse qui appartient déjà à votre compte.',
        'lead_email' => 'Quelqu’un vient de se connecter avec :provider en tant que :email — une adresse qui appartient déjà à votre compte.',
        'was_you' => ':emphasis, associez-le et vous pourrez désormais vous connecter avec :provider ou avec votre mot de passe.',
        'was_you_emphasis' => 'Si c’était vous',
        'was_not_you' => ':emphasis, refusez. Quelqu’un d’autre a tenté de se connecter avec votre adresse e-mail. Rien ne sera ajouté à votre compte, et votre mot de passe fonctionne toujours comme avant.',
        'was_not_you_emphasis' => 'Si ce n’était pas vous',
        'decline' => 'Non, ce n’était pas moi',
        'connect' => 'Oui, associer :provider',
        'disconnect_hint' => 'Vous pouvez dissocier :provider à tout moment depuis les paramètres de sécurité de votre compte.',
    ],

    'open_portal_setup' => [
        'title' => 'Configuration administrateur',
        'heading' => 'Configurez la connexion pour votre organisation',
        'lead' => 'Vous avez reçu un lien de configuration pour votre organisation — SSO, synchronisation d’annuaire, domaines, flux de journaux ou renouvellement d’un certificat. Continuez pour ouvrir l’écran de configuration.',
        'action' => 'Ouvrir la configuration',
        'note' => 'Le lien ne fonctionne qu’une seule fois, et la session de configuration qu’il ouvre expire. Ouvrez-le au moment où vous pourrez terminer.',
    ],
];
