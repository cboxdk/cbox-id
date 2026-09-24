<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /v1/organizations/{id}/transfer-ownership` — who becomes the owner. */
final class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:64'],
        ];
    }

    public function userId(): string
    {
        return $this->string('user_id')->toString();
    }
}
