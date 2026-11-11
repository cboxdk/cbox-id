<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\WebhookIdFormat;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\Erasure\PayloadScrubber;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;
use Cbox\Id\Webhooks\Erasure\WebhookDeliveriesErasureStep;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * Stored deliveries to endpoints that receive public ids, which name the person `usr_01j…`.
 *
 * The framework's {@see WebhookDeliveriesErasureStep} replaces the person's name only in a
 * payload that is about them, and knows that by the BARE id (or the email). A delivery to a
 * `prefixed` endpoint that names the person by id and name but carries no email was never
 * seen as theirs, and kept the name. This step asks the same scrubber the same question with
 * the id in the form those payloads carry it.
 */
final class PrefixedWebhookDeliveriesErasureStep implements ErasureStep
{
    public function name(): string
    {
        return 'webhooks.deliveries.public_ids';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $public = new ErasureRequest(
            PublicId::encode('user', $request->subjectId),
            $request->email,
            $request->name,
            $request->pseudonym,
            $request->actor,
        );

        $candidates = PayloadScrubber::mentioningInPayload(WebhookDelivery::query(), $public)
            ->whereIn('endpoint_id', WebhookEndpoint::query()->select('id')->where('id_format', WebhookIdFormat::PREFIXED));

        $scrubbed = 0;

        foreach ($candidates->cursor() as $delivery) {
            $payload = PayloadScrubber::scrub($delivery->payload, $public);

            if ($payload !== null) {
                $delivery->payload = $payload;
                $delivery->save();
                $scrubbed++;
            }
        }

        return ErasureStepResult::of($this->name(), ['webhook_deliveries' => $scrubbed]);
    }
}
