<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'footer' => '© :year :brand · Questo è un messaggio automatizzato dalla vostra piattaforma di identità.',
    ],
    'common' => [
        'paste_link' => 'Oppure incolla questo link nel tuo browser:',
    ],
    'roles' => [
        'owner' => 'Proprietario',
        'admin' => 'Ammin',
        'developer' => 'Sviluppatore',
        'member' => 'Stato',
        'viewer' => 'Visualizzatore',
    ],
    'magic_link' => [
        'subject' => 'Il tuo collegamento di accesso :brand',
        'heading' => 'Accedi a :brand',
        'body' => 'Fai clic sul pulsante qui sotto per accedere. Questo link è monouso e scade in 15 minuti. Se non lo hai richiesto, puoi tranquillamente ignorare questa email.',
        'button' => 'Accedi a :brand',
    ],
    'password_reset' => [
        'subject' => 'Reimposta la tua password :brand',
        'heading' => 'Reimposta la tua password',
        'body' => 'Abbiamo ricevuto una richiesta per reimpostare la password :brand. Fare clic sul pulsante qui sotto per scegliere una nuova. Questo link è monouso e scade in 60 minuti. Se non hai richiesto, puoi ignorare in modo sicuro questa email. La tua password non cambierà.',
        'button' => 'Reimposta la password',
    ],
    'email_verification' => [
        'subject' => 'Conferma il tuo indirizzo email :brand',
        'heading' => 'Conferma la tua email',
        'body' => 'Benvenuti a :brand. Confermate questo è il vostro indirizzo e-mail per finire di proteggere il vostro account. Questo link è monouso e scade in 24 ore.',
        'button' => 'Conferma l\'indirizzo email',
        'not_you' => 'Se non hai creato un account :brand, puoi ignorare questa email senza il link.',
    ],
    'admin_assigned_password' => [
        'subject' => 'La tua password :brand è stata resettata',
        'heading' => 'La password è stata resettata',
        'body' => 'Un amministratore imposta una nuova password sul tuo account. Accedi con esso qui sotto.',
        'temporary' => 'Ti verrà chiesto di scegliere subito la tua password.',
        'expires' => 'Questa password smette di lavorare su :date, quindi per favore accedi prima di allora.',
        'not_expected' => 'Se non vi aspettavate questo, contattate il vostro amministratore e contattate qualcuno con accesso alla console della vostra organizzazione che ha fatto questo cambiamento, ed è registrato sulla pista di audit.',
    ],
    'invitation' => [
        'subject' => ':inviter vi ha invitato a unirvi a :organization',
        'heading' => 'Iscriviti a :organization',
        'invited' => ':inviter ti ha invitato a unirti a :organization.',
        'invited_as' => ':inviter vi ha invitato a unirvi a :organization come :role.',
        'accept_app' => 'Accetta l\'accesso a :app con il tuo account :organization.',
        'accept' => 'Accetta di configurare il tuo account e accedi.',
        'button' => 'Rivedere l\'invito',
        'note' => 'Il link apre una pagina in cui si conferma che non succede nulla fino a quando non si fa. Essa scade in 7 giorni. Se non vi aspettavate questo, è possibile ignorarlo.',
    ],
    'organization_invite' => [
        'subject' => ':inviter l\'ha invitata a somministrare :organization il :brand',
        'heading' => 'Aiuto per l\'esecuzione :organization su :brand',
        'invited' => ':inviter ti ha invitato ad amministrare :organization su :brand και la console per i suoi fornitori di identità: ambienti, membri e fatturazione. Accetta di impostare una password e accedi.',
        'invited_as' => ':inviter ti ha invitato ad amministrare :organization come :role su :brand και la console per i suoi fornitori di identità: ambienti, membri e fatturazione. Accetta di impostare una password e accedi.',
        'button' => 'Accetta l\'invito',
        'note' => 'Se non ti aspettavi questo invito, puoi ignorarlo, non succede niente finché non accetti.',
    ],
    'portal_link' => [
        'subject' => 'Allestimento :organization su :brand',
        'heading' => 'Allestimento :organization',
        'lead' => 'Ti è stato chiesto di impostare il seguente per :organization su :brand. Non hai bisogno di un account Hoppenstedt il pulsante qui sotto è tutto ciò che serve.',
        'intents' => [
            'sso' => 'Single sign-on con il vostro provider di identità',
            'dsync' => 'Sincronizzazione directory (SCIM)',
            'domain_verification' => 'Verificare i domini di posta elettronica',
            'log_streams' => 'Streaming del log di audit al tuo SIEM',
            'certificate_renewal' => 'Rinnovare il certificato di firma SAML',
            'audit_logs' => 'Revisione dei registri di audit (solo in lettura)',
        ],
        'button' => 'Avvia configurazione',
        'expires' => 'Il collegamento funziona una volta, fino a :date. Se scade, chiederne uno nuovo.',
        'not_expected' => 'Se non ti aspettavi questo, si può ignorare ... non è impostato nulla fino a quando qualcuno utilizza il link.',
    ],
    'certificate_expiring' => [
        'subject' => 'Singolo segno-on attraverso :connection smette di lavorare in :count giorno|Singolo segno-on attraverso :connection smette di lavorare in :count giorni',
        'subject_expired' => 'L\'accesso singolo al :connection ha smesso di funzionare',
        'heading' => 'Il tuo certificato SAML scade presto',
        'heading_expired' => 'Il tuo certificato SAML è scaduto',
        'lead' => 'Il certificato di firma del :connection, il collegamento single sign-on per :organization, scade il :date. Dopo di che, nessuno può accedere attraverso di esso.',
        'lead_expired' => 'Il certificato di firma del :connection, il collegamento single sign-on per :organization, scaduto il :date. Nessuno può accedere attraverso di esso fino a quando non viene rinnovato.',
        'what_to_do' => 'Chiedete a chi gestisce il vostro provider di identità per il suo nuovo certificato di firma, quindi caricarlo nella vostra console di amministrazione o chiedere all\'amministratore di un collegamento Portale di amministrazione per caricarlo.',
        'why' => 'Stai ricevendo questo perché sei un proprietario o amministratore di questa organizzazione su :brand.',
    ],
    'one_time_code' => [
        'label' => 'Il tuo codice',
        'expires' => 'Essa scade in :count minuti e funziona solo una volta.|Essa scade in :count minuti e funziona una sola volta.',
        'never_share' => 'Non condividerlo con nessuno :brand non te lo chiederà mai per telefono, chat o e-mail.',
        'not_you_title' => 'Non te l\'ha chiesto?',
        'sign_in' => [
            'subject' => 'Il tuo codice di accesso :brand',
            'heading' => 'Conferma la tua identità',
            'lead' => 'Inserire questo codice per terminare l\'accesso a :brand.',
            'not_you' => 'Non entrare da nessuna parte, e si può ignorare questa e-mail. Se si dispone di un account :brand, cambiare la sua password qualcuno che ha ottenuto così lontano può sapere.',
        ],
        'device' => [
            'subject' => 'Il codice :brand per approvare un dispositivo',
            'heading' => 'Approvare un\'iscrizione su un altro dispositivo',
            'lead' => 'Inserire questo codice per approvare l\'accesso a :brand su un altro dispositivo, come un televisore o uno strumento a riga di comando.',
            'not_you' => 'Non entrare da nessuna parte. Qualcuno potrebbe essere cercando di farti approvare il loro dispositivo . Questo è il modo in cui inizia questo tipo di attacco. Ignora questa e-mail e nulla è approvato.',
        ],
        'link' => [
            'subject' => 'Il tuo codice :brand per collegare un metodo di accesso',
            'heading' => 'Collegare un metodo di accesso',
            'lead' => 'Inserisci questo codice per collegare un nuovo modo di accedere al tuo account :brand.',
            'not_you' => 'Non entrare da nessuna parte. Qualcuno potrebbe cercare di allegare il loro accesso al tuo account. Ignora questa email e niente è collegato.',
        ],
        'verify' => [
            'subject' => 'Il codice di verifica :brand',
            'heading' => 'Codice di verifica',
            'lead' => 'Inserisci questo codice su :brand per confermare che questo indirizzo email è tuo.',
            'not_you' => 'Puoi ignorare questa email non succede niente senza il codice.',
        ],
    ],
];
