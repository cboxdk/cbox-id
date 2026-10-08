<?php

declare(strict_types=1);

use App\Platform\Sso\CertificateExpiryAlerts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which SAML certificate expiry alerts have gone out — one row per connection, certificate
 * and threshold — so the daily scan says "30 days left" once and "7 days left" once, rather
 * than every morning for a month ({@see CertificateExpiryAlerts}).
 *
 * Keyed by the certificate's fingerprint as well as the connection: a renewal is a new
 * certificate with a clock of its own, and its alerts are owed in their turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_certificate_alerts', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('organization_id', 26)->nullable()->index();
            $table->string('connection_id', 26);
            $table->string('fingerprint', 128);
            $table->unsignedSmallInteger('threshold_days');
            $table->timestamp('not_after');
            $table->timestamp('notified_at');
            $table->timestamps();

            $table->unique(['connection_id', 'fingerprint', 'threshold_days'], 'sso_certificate_alerts_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_certificate_alerts');
    }
};
