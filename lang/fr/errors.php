<?php

declare(strict_types=1);

// French: the error pages. `:brand` is the deployment's configured product name.
return [
    'layout' => [
        'default_title' => 'Erreur',
        'default_heading' => 'Une erreur s’est produite',
        'default_message' => 'Une erreur inattendue s’est produite. Veuillez réessayer.',
        'home' => 'Retour au tableau de bord',
        'reload' => 'Recharger',
        'share_trace' => 'Communiquez cet identifiant au support pour nous aider à retracer ce qui s’est passé.',
        'copy_trace' => 'Copier l’ID de trace',
        'trace_id' => 'ID de trace',
        'copied' => 'Copié',
    ],

    '403' => [
        'title' => 'Accès refusé',
        'message' => 'Vous n’avez pas l’autorisation d’afficher cette page. Si vous pensez qu’il s’agit d’une erreur, contactez votre administrateur.',
    ],

    '404' => [
        'title' => 'Page introuvable',
        'message' => 'Nous n’avons pas trouvé la page que vous recherchez. Elle a peut-être été déplacée ou n’existe plus.',
    ],

    '419' => [
        'title' => 'Votre session a expiré',
        'message' => 'Par mesure de sécurité, votre session a été fermée après une période d’inactivité. Rechargez la page pour continuer.',
    ],

    '429' => [
        'title' => 'Trop de requêtes',
        'message' => 'Vous avez effectué de nombreuses requêtes en peu de temps. Patientez un instant, puis réessayez.',
    ],

    '500' => [
        'title' => 'Une erreur s’est produite',
        'message' => 'Une erreur inattendue s’est produite de notre côté. L’équipe a été prévenue — recharger la page suffit généralement.',
    ],

    '503' => [
        'title' => 'Maintenance en cours',
        'message' => ':brand est momentanément indisponible pour cause de maintenance. Veuillez réessayer dans un instant.',
    ],
];
