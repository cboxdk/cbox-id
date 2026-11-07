<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Došlo ke změně nastavení nebo přihlášeného účtu. Načtěte stránku znovu a vyberte jazyk.',
    'layout' => [
        'default_title' => 'Chyba',
        'default_heading' => 'Došlo k chybě',
        'default_message' => 'Došlo k neočekávané chybě. Zkuste to znovu.',
        'home' => 'Zpět na přehled',
        'reload' => 'Obnovit',
        'share_trace' => 'Předejte tento identifikátor podpoře, abychom mohli zjistit, co se stalo.',
        'copy_trace' => 'Kopírovat ID sledování',
        'trace_id' => 'ID sledování',
        'copied' => 'Zkopírováno',
    ],
    403 => [
        'title' => 'Přístup zamítnut',
        'message' => 'Nemáte oprávnění zobrazit tuto stránku. Pokud se domníváte, že jde o chybu, obraťte se na správce.',
    ],
    404 => [
        'title' => 'Stránka nebyla nalezena',
        'message' => 'Hledanou stránku jsme nenašli. Mohla být přesunuta nebo už neexistuje.',
    ],
    419 => [
        'title' => 'Vaše relace vypršela',
        'message' => 'Z bezpečnostních důvodů jste byli po určité době nečinnosti odhlášeni. Pokračujte obnovením stránky.',
    ],
    429 => [
        'title' => 'Příliš mnoho požadavků',
        'message' => 'Za krátkou dobu jste odeslali mnoho požadavků. Chvíli počkejte a zkuste to znovu.',
    ],
    500 => [
        'title' => 'Došlo k chybě',
        'message' => 'V našem systému došlo k neočekávané chybě. Tým byl informován. Obnovení stránky obvykle pomůže.',
    ],
    503 => [
        'title' => 'Probíhá údržba',
        'message' => 'Služba :brand je kvůli údržbě dočasně nedostupná. Zkuste to za chvíli znovu.',
    ],
];
