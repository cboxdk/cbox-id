<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'footer' => '© :year :brand · Tämä on automaattinen viesti identiteettialustaltasi.',
    ],
    'common' => [
        'paste_link' => 'Tai liitä tämä linkki selaimeesi:',
    ],
    'roles' => [
        'owner' => 'Omistaja',
        'admin' => 'Järjestelmänvalvoja',
        'developer' => 'Kehittäjä',
        'member' => 'Jäsen',
        'viewer' => 'Näyttö',
    ],
    'magic_link' => [
        'subject' => 'Sinun :brand kirjautumislinkkisi',
        'heading' => 'Kirjautuminen numeroon :brand',
        'body' => 'Klikkaa alla olevaa painiketta kirjautuaksesi sisään. Tämä linkki on kertakäyttöinen ja päättyy 15 minuutin kuluttua. Jos et pyytänyt sitä, voit turvallisesti jättää tämän sähköpostin huomiotta.',
        'button' => 'Kirjautuminen numeroon :brand',
    ],
    'password_reset' => [
        'subject' => 'Nollaa :brand-salasanasi',
        'heading' => 'Palauta salasanasi',
        'body' => 'Saimme pyynnön nollata :brand-salasanasi. Klikkaa alla olevaa painiketta valitaksesi uuden. Tämä linkki on kertakäyttöinen ja päättyy 60 minuutissa. Jos et pyytänyt sitä, voit turvallisesti jättää tämän sähköpostin huomiotta – salasanasi ei muutu.',
        'button' => 'Palauta salasana',
    ],
    'email_verification' => [
        'subject' => 'Vahvista :brand sähköpostiosoite',
        'heading' => 'Vahvista sähköpostiosoitteesi',
        'body' => 'Tervetuloa osoitteeseen :brand. Vahvista, että tämä on sähköpostiosoitteesi tilin sulkemiseksi. Tämä linkki on kertakäyttöinen ja päättyy 24 tunnin kuluttua.',
        'button' => 'Vahvista sähköpostiosoite',
        'not_you' => 'Jos et luonut :brand-tiliä, voit jättää tämän sähköpostin huomiotta – mitään ei tapahdu ilman linkkiä.',
    ],
    'admin_assigned_password' => [
        'subject' => ':brand-salasanasi on nollattu',
        'heading' => 'Salasanasi on nollattu',
        'body' => 'Hallintamies asetti tilillesi uuden salasanan. Kirjaudu sisään alla olevalla kirjautumisella.',
        'temporary' => 'Sinua pyydetään valitsemaan oma salasanasi saman tien.',
        'expires' => 'Tämä salasana lakkaa toimimasta :date:llä, joten kirjaudu sisään ennen sitä.',
        'not_expected' => 'Jos et odottanut tätä, ota yhteyttä ylläpitäjääsi – joku, jolla on pääsy organisaatiosi konsoliin, teki tämän muutoksen, ja se kirjataan kirjausketjuun.',
    ],
    'invitation' => [
        'subject' => ':inviter kutsui sinut mukaan :organization',
        'heading' => 'Liity :organization-ryhmään',
        'invited' => ':inviter kutsui sinut mukaan :organization.',
        'invited_as' => ':inviter kutsui sinut mukaan :organization:ksi :role:ksi.',
        'accept_app' => 'Hyväksy kirjautuminen :app:een :organization tililläsi.',
        'accept' => 'Hyväksy tilin perustaminen ja kirjautuminen sisään.',
        'button' => 'Uudelleentarkastelupyyntö',
        'note' => 'Linkki avaa sivun, jossa voit vahvistaa – mitään ei tapahdu ennen kuin teet sen. Se päättyy 7 päivässä. Jos et odottanut tätä, voit jättää sen huomiotta.',
    ],
    'organization_invite' => [
        'subject' => ':inviter pyysi sinua hallinnoimaan :organization:ta :brand:lla',
        'heading' => 'Apua :organization päälle :brand',
        'invited' => ':inviter kutsui sinut hallinnoimaan :organization -ohjelmaa :brand, joka on sen identiteettipalvelujen konsoli: ympäristöt, jäsenet ja laskutus. Hyväksy salasanan asettaminen ja kirjautuminen sisään.',
        'invited_as' => ':inviter pyysi sinua hallinnoimaan :organization:ta :role:na :brand:nä, joka on konsoli sen identiteettitoimittajille: ympäristöille, jäsenille ja laskutukselle. Hyväksy salasanan asettaminen ja kirjautuminen sisään.',
        'button' => 'Hyväksy kutsu',
        'note' => 'Jos et odottanut kutsua, voit jättää sen huomiotta – mitään ei tapahdu ennen kuin suostut.',
    ],
    'portal_link' => [
        'subject' => 'Perustetaan :organization numeroon :brand',
        'heading' => 'Perustetaan :organization',
        'lead' => 'Sinua on pyydetty asettamaan :organization:lle :brand. Et tarvitse tiliä – alla oleva painike on kaikki, mitä se vaatii.',
        'intents' => [
            'sso' => 'Yksittäinen kirjautuminen identiteettisi tarjoajan kanssa',
            'dsync' => 'Hakemiston synkronointi (SCIM)',
            'domain_verification' => 'Sähköpostiverkkotunnuksien tarkistaminen',
            'log_streams' => 'Striimataan tilintarkastusloki SIEM-järjestelmään',
            'certificate_renewal' => 'SAML-allekirjoitussertifikaatin uusiminen',
            'audit_logs' => 'Auditointilokien tarkastelu (vain luku)',
        ],
        'button' => 'Käynnistä asetukset',
        'expires' => 'Linkki toimii kerran, :date asti. Jos se päättyy, pyydä uutta.',
        'not_expected' => 'Jos et odottanut tätä, voit jättää sen huomioimatta – mitään ei pystytetä, ennen kuin joku käyttää linkkiä.',
    ],
    'certificate_expiring' => [
        'subject' => 'Yksittäinen kirjautuminen läpi :connection lopettaa työskentelyn :count päivässä|Yksittäinen kirjautuminen läpi :connection lopettaa työskentelyn :count päivässä',
        'subject_expired' => 'Yksittäinen kirjautuminen läpi :connection:n on lakannut toimimasta',
        'heading' => 'SAML-sertifikaattisi päättyy pian',
        'heading_expired' => 'SAML-sertifikaattisi on vanhentunut',
        'lead' => 'Allekirjoitustodistus :connection, yksittäinen kirjautumisyhteys :organization, päättyy :date. Sen jälkeen kukaan ei voi kirjautua sen läpi.',
        'lead_expired' => 'Allekirjoitustodistus :connection, yksi kirjautumisyhteys :organization, päättyi :date. Kukaan ei voi kirjautua sen läpi, ennen kuin se on uusittu.',
        'what_to_do' => 'Kysy henkilöllisyytesi toimittajalta sen uutta allekirjoitussertifikaattia varten, lataa se sitten admin-konsoliisi – tai pyydä ylläpitäjältäsi admin-portaalin linkkiä sen lataamiseksi.',
        'why' => 'Saat tämän, koska olet tämän organisaation omistaja tai ylläpitäjä numerossa :brand.',
    ],
    'one_time_code' => [
        'label' => 'Koodisi',
        'expires' => 'Se päättyy :count minuutissa ja toimii vain kerran.|Se päättyy :count minuutissa ja toimii vain kerran.',
        'never_share' => 'Älä jaa sitä kenenkään kanssa – :brand ei koskaan pyydä sitä puhelimella, chatilla tai sähköpostilla.',
        'not_you_title' => 'Etkö pyytänyt tätä?',
        'sign_in' => [
            'subject' => ':brand kirjautumiskoodisi',
            'heading' => 'Vahvista henkilöllisyytesi',
            'lead' => 'Syötä tämä koodi loppuun kirjautumalla numeroon :brand.',
            'not_you' => 'Älä syötä sitä minnekään, ja voit jättää tämän sähköpostin huomiotta. Jos sinulla on :brand-tili, vaihda sen salasana – näin pitkälle päässyt henkilö saattaa tietää sen.',
        ],
        'device' => [
            'subject' => ':brand-koodisi laitteen hyväksymiseksi',
            'heading' => 'Hyväksy kirjautuminen toiseen laitteeseen',
            'lead' => 'Syötä tämä koodi hyväksyäksesi kirjautumisen palveluun :brand toisella laitteella, kuten televisiolla tai komentorivityökalulla.',
            'not_you' => 'Älä syötä sitä minnekään. Joku voi yrittää saada sinut hyväksymään laitteensa – näin tällainen hyökkäys alkaa. Älä välitä tästä sähköpostista, eikä mitään hyväksytä.',
        ],
        'link' => [
            'subject' => ':brand-koodisi linkittääksesi sisäänkirjautumismenetelmän',
            'heading' => 'Liitä sisäänkirjautumismenetelmä',
            'lead' => 'Syötä tämä koodi linkittääksesi uuden tavan kirjautua :brand-tilillesi.',
            'not_you' => 'Älä syötä sitä minnekään. Joku saattaa yrittää liittää kirjautumispyyntönsä tilillesi. Älä välitä tästä sähköpostista, eikä mitään ole linkitetty.',
        ],
        'verify' => [
            'subject' => ':brand-varmennuskoodisi',
            'heading' => 'Varmennuskoodisi',
            'lead' => 'Syötä tämä koodi numeroon :brand vahvistaaksesi, että tämä sähköpostiosoite on sinun.',
            'not_you' => 'Voit jättää tämän sähköpostin huomiotta – mitään ei tapahdu ilman koodia.',
        ],
    ],
];
