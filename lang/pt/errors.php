<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'As suas definições ou a conta com sessão iniciada foram alteradas. Recarregue a página e selecione novamente o idioma.',
    'layout' => [
        'default_title' => 'Erro',
        'default_heading' => 'Ocorreu um erro',
        'default_message' => 'Ocorreu um erro inesperado. Tente novamente.',
        'home' => 'Voltar ao painel',
        'reload' => 'Atualizar',
        'share_trace' => 'Partilhe este identificador com a equipa de apoio para nos ajudar a perceber o que aconteceu.',
        'copy_trace' => 'Copiar ID de rastreio',
        'trace_id' => 'ID de rastreio',
        'copied' => 'Copiado',
    ],
    403 => [
        'title' => 'Acesso negado',
        'message' => 'Não tem permissão para ver esta página. Se considera que se trata de um erro, contacte o seu administrador.',
    ],
    404 => [
        'title' => 'Página não encontrada',
        'message' => 'Não encontrámos a página que procura. Pode ter sido movida ou já não existir.',
    ],
    419 => [
        'title' => 'A sua sessão expirou',
        'message' => 'Por motivos de segurança, a sua sessão foi terminada após um período de inatividade. Recarregue a página para continuar.',
    ],
    429 => [
        'title' => 'Demasiados pedidos',
        'message' => 'Enviou muitos pedidos num curto espaço de tempo. Aguarde um momento e tente novamente.',
    ],
    500 => [
        'title' => 'Ocorreu um erro',
        'message' => 'Ocorreu um erro inesperado no nosso sistema. A equipa foi avisada. Recarregar a página costuma resolver o problema.',
    ],
    503 => [
        'title' => 'Em manutenção',
        'message' => ':brand está temporariamente indisponível devido a manutenção. Tente novamente dentro de momentos.',
    ],
];
