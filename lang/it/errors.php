<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'default_title' => 'Errore',
        'default_heading' => 'Qualcosa è andato storto',
        'default_message' => 'Si è verificato un errore inatteso. Riprovare.',
        'home' => 'Torna al cruscotto',
        'reload' => 'Ricarica',
        'share_trace' => 'Condividi questo con il supporto per aiutarci a rintracciare ciò che è successo.',
        'copy_trace' => 'Copia l\'ID traccia',
        'trace_id' => 'Trace ID',
        'copied' => 'Copiato',
    ],
    '403' => [
        'title' => 'Accesso negato',
        'message' => 'Se pensi che questo sia un errore, contatta il tuo amministratore.',
    ],
    '404' => [
        'title' => 'Pagina non trovata',
        'message' => 'Non siamo riusciti a trovare la pagina che stavate cercando. Potrebbe essersi spostata o non esiste più.',
    ],
    '419' => [
        'title' => 'La tua sessione è scaduta',
        'message' => 'Per la tua sicurezza sei stato espulso dopo un periodo di inattività. Ricarica la pagina per continuare.',
    ],
    '429' => [
        'title' => 'Troppe richieste',
        'message' => 'Hai fatto un sacco di richieste in breve tempo. Aspetta un attimo, poi riprova.',
    ],
    '500' => [
        'title' => 'Qualcosa è andato storto',
        'message' => 'Un errore inatteso si è verificato dalla nostra parte. Il team è stato avvisato che il ricarico di solito aiuta.',
    ],
    '503' => [
        'title' => 'Giù per la manutenzione',
        'message' => ':brand non è disponibile durante la manutenzione. Riprovare tra un attimo.',
    ],
];
