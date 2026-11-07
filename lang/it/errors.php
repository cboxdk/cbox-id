<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Le impostazioni o l’account con cui hai effettuato l’accesso sono cambiati. Ricarica la pagina e seleziona di nuovo la lingua.',
    'layout' => [
        'default_title' => 'Errore',
        'default_heading' => 'Si è verificato un errore',
        'default_message' => 'Si è verificato un errore imprevisto. Riprova.',
        'home' => 'Torna alla dashboard',
        'reload' => 'Aggiorna',
        'share_trace' => 'Comunica questo identificativo all’assistenza per aiutarci a capire cosa è successo.',
        'copy_trace' => 'Copia ID di tracciamento',
        'trace_id' => 'ID di tracciamento',
        'copied' => 'Copiato',
    ],
    403 => [
        'title' => 'Accesso negato',
        'message' => 'Non hai il permesso di visualizzare questa pagina. Se pensi che sia un errore, contatta il tuo amministratore.',
    ],
    404 => [
        'title' => 'Pagina non trovata',
        'message' => 'Non troviamo la pagina che cerchi. Potrebbe essere stata spostata o non esistere più.',
    ],
    419 => [
        'title' => 'La tua sessione è scaduta',
        'message' => 'Per la tua sicurezza, la sessione è stata chiusa dopo un periodo di inattività. Ricarica la pagina per continuare.',
    ],
    429 => [
        'title' => 'Troppe richieste',
        'message' => 'Hai inviato molte richieste in poco tempo. Attendi un momento e riprova.',
    ],
    500 => [
        'title' => 'Si è verificato un errore',
        'message' => 'Si è verificato un errore imprevisto nel nostro sistema. Il team è stato avvisato. Di solito basta ricaricare la pagina.',
    ],
    503 => [
        'title' => 'In manutenzione',
        'message' => ':brand è temporaneamente non disponibile per manutenzione. Riprova tra poco.',
    ],
];
