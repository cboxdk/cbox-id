<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Megváltoztak a beállításaid vagy a bejelentkezéshez használt fiókod. Töltsd be újra az oldalt, és válaszd ki ismét a nyelvet.',
    'layout' => [
        'default_title' => 'Hiba',
        'default_heading' => 'Hiba történt',
        'default_message' => 'Váratlan hiba történt. Próbáld újra.',
        'home' => 'Vissza az irányítópultra',
        'reload' => 'Frissítés',
        'share_trace' => 'Oszd meg ezt az azonosítót az ügyfélszolgálattal, hogy ki tudjuk deríteni, mi történt.',
        'copy_trace' => 'Nyomkövetési azonosító másolása',
        'trace_id' => 'Nyomkövetési azonosító',
        'copied' => 'Másolva',
    ],
    403 => [
        'title' => 'Hozzáférés megtagadva',
        'message' => 'Nincs jogosultságod az oldal megtekintéséhez. Ha szerinted ez tévedés, fordulj a rendszergazdádhoz.',
    ],
    404 => [
        'title' => 'Nem található oldal',
        'message' => 'Nem találjuk a keresett oldalt. Lehet, hogy máshová került vagy már nem létezik.',
    ],
    419 => [
        'title' => 'A munkameneted lejárt',
        'message' => 'Biztonsági okból kijelentkeztettünk, mert egy ideje nem voltál aktív. A folytatáshoz töltsd újra az oldalt.',
    ],
    429 => [
        'title' => 'Túl sok kérés',
        'message' => 'Rövid idő alatt sok kérést küldtél. Várj egy kicsit, majd próbáld újra.',
    ],
    500 => [
        'title' => 'Hiba történt',
        'message' => 'Váratlan hiba történt nálunk. Értesítettük a csapatot. Az oldal újratöltése általában segít.',
    ],
    503 => [
        'title' => 'Karbantartás folyamatban',
        'message' => ':brand karbantartás miatt rövid ideig nem érhető el. Próbáld újra egy kicsit később.',
    ],
];
