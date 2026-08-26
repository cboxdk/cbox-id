<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A person changing their own password.
 *
 * THE POLICY IS THE TENANT'S, resolved for the acting subject rather than hard-coded here
 * — an organization that requires 16 characters requires 16 of its own people too.
 *
 * The CURRENT password is required by the controller rather than by a rule, because
 * whether there is one to verify depends on the account: somebody who signed up with a
 * social account or a passkey is SETTING a first password, and demanding the old one would
 * be demanding something that does not exist.
 */
final class ChangeOwnPasswordRequest extends FormRequest
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
            'currentPassword' => ['nullable', 'string'],
            'newPassword' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for(app(CurrentUser::class)->id())],
            'newPasswordConfirmation' => ['required', 'string'],
        ];
    }

    public function currentPassword(): string
    {
        return (string) $this->string('currentPassword');
    }

    public function newPassword(): string
    {
        return (string) $this->string('newPassword');
    }

    public function newPasswordConfirmation(): string
    {
        return (string) $this->string('newPasswordConfirmation');
    }
}
