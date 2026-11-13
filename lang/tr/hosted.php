<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Tüm uygulamalarınız için tek bir kimlik katmanı.',
        'secured_by' => 'Güvenliği sağlayan: :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Açık ve koyu tema arasında geçiş yap',
        'about' => 'Bu ürün hakkında',
        'hero_body' => 'Kurumsal SSO, SCIM dizin eşitlemesi, MFA ve geçiş anahtarları, RBAC ve değişikliklerin tespit edilebildiği bir denetim günlüğü — kendi altyapınızda barındırılabilir, sizin kontrolünüzde.',
        'features' => [
            'sso' => 'SAML ve OIDC ile tek oturum açma',
            'scim' => 'SCIM 2.0 ile dizin hesabı sağlama',
            'mfa' => 'Geçiş anahtarları, TOTP ve tek kullanımlık giriş bağlantıları',
            'audit' => 'Değişikliklerin tespit edilebildiği, karma zincirli denetim günlüğü',
        ],
    ],
    'language' => [
        'label' => 'Dil',
    ],

    'theme' => [
        'system' => 'Sistem',
        'light' => 'Açık',
        'dark' => 'Koyu',
    ],
    'skip_to_content' => 'İçeriğe geç',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Cbox ID uygulamasını açın',
        'lead' => 'Cbox ID uygulaması bu cihazda açılmadı.',
        'other_phone' => 'Uygulama başka bir telefonda mı? İsteği orada onaylayın, sonra oturum açma sayfasına dönün. Kendiliğinden devam eder.',
        'open' => 'Uygulamada aç',
        'install' => 'Uygulamayı edinin',
        'back' => 'Ya da geri dönüp başka bir onay yöntemi seçin.',
    ],
];
