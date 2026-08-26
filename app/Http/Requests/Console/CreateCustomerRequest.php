<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A whole customer, stood up by an operator: the organization, its owner, its first
 * product and that product's first environment.
 *
 * NO PASSWORD IS ASKED FOR, and that is the shape of this form. An operator who typed a
 * password for somebody else would be an operator who knows a customer's credential —
 * precisely the authority the platform is not supposed to have over its customers'
 * identities. The owner is emailed a link and picks their own.
 */
final class CreateCustomerRequest extends FormRequest
{
    /** The plan allowances an operator may hand out from this form. */
    public const LIMITS = [1, 2, 3, 5, 10, 25];

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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            /*
             * NOT UNIQUE: one person can own several customers, and the provisioner reuses
             * the subject that address already has rather than minting a second identity.
             *
             * `email` rather than `email:rfc,dns`, matching signup. The `dns` rule is a live
             * MX lookup on the request path, and refusing an address because its domain has
             * no mail record YET is wrong on exactly this form — an operator onboards a
             * customer before their DNS is cut over more often than after.
             */
            'ownerEmail' => ['required', 'email', 'max:255'],
            'ownerName' => ['required', 'string', 'min:2', 'max:120'],
            'environmentLimit' => ['required', 'integer', Rule::in(self::LIMITS)],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * Normalised ONCE and reused for the mail, rather than read back off the created
     * subject: `Subject::$email` is nullable, and the honest way to satisfy that is to send
     * to the address that was actually provisioned.
     */
    public function ownerEmail(): string
    {
        return mb_strtolower(trim((string) $this->string('ownerEmail')));
    }

    public function ownerName(): string
    {
        return trim((string) $this->string('ownerName'));
    }

    public function environmentLimit(): int
    {
        return $this->integer('environmentLimit');
    }
}
