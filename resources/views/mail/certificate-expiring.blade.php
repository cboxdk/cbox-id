<x-mail.layout :brand="$brand" :theme="$theme">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#14161c">
        {{ $expired
            ? __('mail.certificate_expiring.heading_expired', ['connection' => $connectionName])
            : __('mail.certificate_expiring.heading', ['connection' => $connectionName]) }}
    </h1>
    <p style="margin:0 0 12px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ \App\Mail\MailText::html(
            $expired ? 'mail.certificate_expiring.lead_expired' : 'mail.certificate_expiring.lead',
            ['connection' => $connectionName, 'organization' => $organization, 'date' => $expiresOn],
        ) }}
    </p>
    <p style="margin:0 0 12px;color:#3f4450;font-size:15px;line-height:1.6">
        {{ __('mail.certificate_expiring.what_to_do') }}
    </p>
    <p style="margin:0;color:#5b616e;font-size:13px;line-height:1.6">
        {{ __('mail.certificate_expiring.why', ['brand' => $brand]) }}
    </p>
</x-mail.layout>
