<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The forced change's new password.
 *
 * The policy rule is bound to THIS subject, which is what lets it check reuse against
 * their own history — the reset-from-a-link flow cannot, because it never resolves who
 * the token belongs to.
 */
final class ChangePasswordRequest extends FormRequest
{
    /**
     * WHETHER this person owes a change is checked in the controller, immediately before
     * the write and against the same id the write uses, so the two cannot disagree about
     * who this is. It is not checked here: `CurrentUser` is populated by middleware that
     * has run by then either way, and splitting one guard across two files is how the two
     * halves come to disagree.
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
            'password' => [
                'required',
                'string',
                'max:200',
                'confirmed',
                PasswordMeetsPolicy::for(app(CurrentUser::class)->id()),
            ],
            'password_confirmation' => ['required'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The passwords do not match.',
        ];
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
