<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting a new password from a reset link.
 *
 * THE TENANT'S POLICY APPLIES HERE, including its breach screen. This is the flow an
 * attacker with a stolen reset token uses, and the one where a person is most likely to
 * reach for a password they have used somewhere before.
 *
 * What the rule cannot check is REUSE against this subject's own history: the subject is
 * identified by the token, and this form never resolves it — doing so would turn the page
 * into an account-existence oracle. The reset itself checks that, and the controller
 * turns its refusal into a field error.
 */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * The token is the authorization, and it is verified where it is consumed. A check
     * here could only compare it to itself.
     */
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'max:200', 'confirmed', PasswordMeetsPolicy::for()],
            'password_confirmation' => ['required'],
        ];
    }

    public function token(): string
    {
        return (string) $this->string('token');
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
