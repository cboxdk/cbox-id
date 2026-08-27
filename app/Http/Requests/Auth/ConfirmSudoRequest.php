<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-entering a password to raise a step-up.
 *
 * No policy rule here, deliberately: this is somebody proving they know the password they
 * already have, and refusing it for being too short would lock them out of every gated
 * page over a rule that applies when a password is SET.
 */
final class ConfirmSudoRequest extends FormRequest
{
    /**
     * WHO may raise a step-up is decided by the route's plane and, on the environment
     * plane, by a membership check in the controller — where the same reader that
     * verifies the password lives, so the two cannot disagree about who this is.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
