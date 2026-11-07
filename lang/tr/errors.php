<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Ayarlarınız veya oturum açtığınız hesap değişti. Sayfayı yenileyip dili yeniden seçin.',
    'layout' => [
        'default_title' => 'Hata',
        'default_heading' => 'Bir hata oluştu',
        'default_message' => 'Beklenmeyen bir hata oluştu. Tekrar deneyin.',
        'home' => 'Kontrol paneline dön',
        'reload' => 'Yenile',
        'share_trace' => 'Ne olduğunu tespit edebilmemiz için bu kimliği destek ekibiyle paylaşın.',
        'copy_trace' => 'İzleme kimliğini kopyala',
        'trace_id' => 'İzleme kimliği',
        'copied' => 'Kopyalandı',
    ],
    403 => [
        'title' => 'Erişim reddedildi',
        'message' => 'Bu sayfayı görüntüleme izniniz yok. Bunun bir hata olduğunu düşünüyorsanız yöneticinizle iletişime geçin.',
    ],
    404 => [
        'title' => 'Sayfa bulunamadı',
        'message' => 'Aradığınız sayfayı bulamadık. Sayfa taşınmış veya kaldırılmış olabilir.',
    ],
    419 => [
        'title' => 'Oturumunuzun süresi doldu',
        'message' => 'Güvenliğiniz için bir süre işlem yapılmadığından oturumunuz kapatıldı. Devam etmek için sayfayı yenileyin.',
    ],
    429 => [
        'title' => 'Çok fazla istek',
        'message' => 'Kısa sürede çok sayıda istek gönderdiniz. Biraz bekleyip tekrar deneyin.',
    ],
    500 => [
        'title' => 'Bir hata oluştu',
        'message' => 'Sistemimizde beklenmeyen bir hata oluştu. Ekibe bilgi verildi. Sayfayı yenilemek genellikle yardımcı olur.',
    ],
    503 => [
        'title' => 'Bakım yapılıyor',
        'message' => ':brand bakım nedeniyle geçici olarak kullanılamıyor. Biraz sonra tekrar deneyin.',
    ],
];
