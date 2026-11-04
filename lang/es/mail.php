<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'footer' => '© :year :brand · Este es un mensaje automatizado de su plataforma de identidad.',
    ],
    'common' => [
        'paste_link' => 'O pega este enlace en tu navegador:',
    ],
    'roles' => [
        'owner' => 'Propietario',
        'admin' => 'Administración',
        'developer' => 'Desarrollador',
        'member' => 'Miembro',
        'viewer' => 'Visor',
    ],
    'magic_link' => [
        'subject' => 'Su enlace de inicio de sesión :brand',
        'heading' => 'Inicie sesión en :brand',
        'body' => 'Haga clic en el botón de abajo para iniciar sesión. Este enlace es de un solo uso y expira en 15 minutos. Si no lo solicitó, puede ignorar con seguridad este correo electrónico.',
        'button' => 'Inicie sesión en :brand',
    ],
    'password_reset' => [
        'subject' => 'Reiniciar su contraseña :brand',
        'heading' => 'Restablece tu contraseña',
        'body' => 'Recibimos una solicitud para restablecer su contraseña :brand. Haga clic en el botón de abajo para elegir uno nuevo. Este enlace es de un solo uso y expira en 60 minutos. Si no lo solicitó, puede ignorar con seguridad este correo electrónico, su contraseña no cambiará.',
        'button' => 'Restablecer contraseña',
    ],
    'email_verification' => [
        'subject' => 'Confirme su dirección de correo electrónico :brand',
        'heading' => 'Confirma tu correo electrónico',
        'body' => 'Bienvenido a :brand. Confirme que esta es su dirección de correo electrónico para terminar de asegurar su cuenta. Este enlace es de un solo uso y expira en 24 horas.',
        'button' => 'Confirmar dirección de correo electrónico',
        'not_you' => 'Si no creaste una cuenta :brand, puedes ignorar este correo electrónico; no pasa nada sin el enlace.',
    ],
    'admin_assigned_password' => [
        'subject' => 'Se ha restablecido su contraseña :brand',
        'heading' => 'Su contraseña ha sido restablecida',
        'body' => 'Un administrador establece una nueva contraseña en su cuenta. Inicie sesión con ella a continuación.',
        'temporary' => 'Se le pedirá que elija su propia contraseña de inmediato.',
        'expires' => 'Esta contraseña deja de funcionar en :date, así que por favor inicie sesión antes de entonces.',
        'not_expected' => 'Si no esperaba esto, comuníquese con su administrador: alguien con acceso a la consola de su organización hizo este cambio y se registra en la pista de auditoría.',
    ],
    'invitation' => [
        'subject' => ':inviter le invitó a unirse a :organization',
        'heading' => 'Únase a :organization',
        'invited' => ':inviter le invitó a unirse a :organization.',
        'invited_as' => ':inviter le invitó a unirse a :organization como :role.',
        'accept_app' => 'Acepta iniciar sesión en :app con tu cuenta :organization.',
        'accept' => 'Acepta configurar tu cuenta e iniciar sesión.',
        'button' => 'Invitación de revisión',
        'note' => 'El enlace abre una página en la que confirmas que no pasa nada hasta que lo haces. Vence en 7 días. Si no esperabas esto, puedes ignorarlo.',
    ],
    'organization_invite' => [
        'subject' => ':inviter le invitó a administrar :organization en :brand',
        'heading' => 'Ayuda a ejecutar :organization en :brand',
        'invited' => ':inviter le invitó a administrar :organization en :brand — la consola para sus proveedores de identidad: entornos, miembros y facturación. Aceptar para establecer una contraseña e iniciar sesión.',
        'invited_as' => ':inviter le invitó a administrar :organization como :role en :brand — la consola para sus proveedores de identidad: entornos, miembros y facturación. Aceptar para establecer una contraseña e iniciar sesión.',
        'button' => 'Aceptar invitación',
        'note' => 'Si no esperabas esta invitación, puedes ignorarla; no pasa nada hasta que no la aceptes.',
    ],
    'portal_link' => [
        'subject' => 'Instalar :organization en :brand',
        'heading' => 'Instalar :organization',
        'lead' => 'Se le ha pedido que establezca lo siguiente para :organization en :brand. Usted no necesita una cuenta — el botón de abajo es todo lo que se necesita.',
        'intents' => [
            'sso' => 'Inicio de sesión único con su proveedor de identidad',
            'dsync' => 'Sincronización de directorios (SCIM)',
            'domain_verification' => 'Verificar sus dominios de correo electrónico',
            'log_streams' => 'Transmitiendo su registro de auditoría a su SIEM',
            'certificate_renewal' => 'Renovación del certificado de firma SAML',
            'audit_logs' => 'Revisión de sus registros de auditoría (sólo lectura)',
        ],
        'button' => 'Inicio de la configuración',
        'expires' => 'El enlace funciona una vez, hasta :date. Si expira, pida uno nuevo.',
        'not_expected' => 'Si no esperabas esto, puedes ignorarlo; nada se establece hasta que alguien use el enlace.',
    ],
    'certificate_expiring' => [
        'subject' => 'El inicio de sesión único a través de :connection deja de funcionar en :count día|El inicio de sesión único a través de :connection deja de funcionar en :count días',
        'subject_expired' => 'El inicio de sesión único a través de :connection ha dejado de funcionar',
        'heading' => 'Su certificado SAML expira pronto',
        'heading_expired' => 'Su certificado SAML ha expirado',
        'lead' => 'El certificado de firma de :connection, la conexión única de inicio de sesión para :organization, expira en :date. Después de eso, nadie puede firmar a través de él.',
        'lead_expired' => 'El certificado de firma de :connection, la conexión única de inicio de sesión para :organization, expiró en :date. Nadie puede firmar a través de él hasta que se renueva.',
        'what_to_do' => 'Pregúntele a quien ejecute su proveedor de identidad por su nuevo certificado de firma, luego cárguelo en su consola de administración, o pídale a su administrador un enlace al portal de administración que lo cargue.',
        'why' => 'Usted está recibiendo esto porque usted es un propietario o administrador de esta organización en :brand.',
    ],
    'one_time_code' => [
        'label' => 'Su código',
        'expires' => 'Expira en :count minuto y funciona sólo una vez.|Expira en :count minutos y funciona sólo una vez.',
        'never_share' => 'No lo compartas con nadie: :brand nunca te lo pedirá por teléfono, chat o correo electrónico.',
        'not_you_title' => '¿No me lo pediste?',
        'sign_in' => [
            'subject' => 'Su código de inicio de sesión :brand',
            'heading' => 'Confirma tu identidad',
            'lead' => 'Introduzca este código para terminar de firmar en :brand.',
            'not_you' => 'No lo introduzcas en ningún lugar, y puedes ignorar este correo electrónico. Si tienes una cuenta :brand, cambia su contraseña: alguien que llegó hasta aquí puede que lo sepa.',
        ],
        'device' => [
            'subject' => 'Su código :brand para aprobar un dispositivo',
            'heading' => 'Aprobar un inicio de sesión en otro dispositivo',
            'lead' => 'Introduzca este código para aprobar el inicio de sesión en :brand en otro dispositivo, como un televisor o una herramienta de línea de comandos.',
            'not_you' => 'No lo introduzcas en ninguna parte. Alguien puede estar intentando que apruebes su dispositivo, así es como comienza este tipo de ataque. Ignora este correo electrónico y nada está aprobado.',
        ],
        'link' => [
            'subject' => 'Su código :brand para vincular un método de inicio de sesión',
            'heading' => 'Vincular un método de inicio de sesión',
            'lead' => 'Introduzca este código para vincular una nueva forma de iniciar sesión a su cuenta :brand.',
            'not_you' => 'No lo introduzcas en ninguna parte. Alguien puede estar intentando adjuntar su inicio de sesión a tu cuenta. Ignora este correo electrónico y nada está vinculado.',
        ],
        'verify' => [
            'subject' => 'Su código de verificación :brand',
            'heading' => 'Su código de verificación',
            'lead' => 'Introduzca este código en :brand para confirmar que esta dirección de correo electrónico es suya.',
            'not_you' => 'Usted puede ignorar este correo electrónico - nada sucede sin el código.',
        ],
    ],
];
