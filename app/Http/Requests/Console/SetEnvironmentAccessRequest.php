<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/** Which environments a scoped member reaches. */
final class SetEnvironmentAccessRequest extends FormRequest
{
    /**
     * WHOSE access is not decided here: the membership id is in the URL and the
     * controller re-resolves it against the acting organization before writing.
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
            'all' => ['required', 'boolean'],
            'environmentIds' => ['array'],
            'environmentIds.*' => ['string'],
        ];
    }

    /**
     * Every environment the organization owns, including ones added later.
     *
     * `allEnvironments()`, not `all()`: `Request::all()` already means "every input on
     * this request", and overriding it with a boolean is a signature clash the framework
     * refuses outright.
     */
    public function allEnvironments(): bool
    {
        return $this->boolean('all');
    }

    /**
     * The chosen subset.
     *
     * NOT validated against the organization's own environments here, because the write
     * does not need it to be: `setEnvironmentAccess()` is called with the organization id
     * the scope resolved, and a grant naming an environment outside it reaches nothing.
     * Refusing here would only turn a no-op into a validation error.
     *
     * @return list<string>
     */
    public function environmentIds(): array
    {
        /** @var list<string> */
        return array_values(array_filter(
            (array) $this->input('environmentIds', []),
            'is_string',
        ));
    }
}
