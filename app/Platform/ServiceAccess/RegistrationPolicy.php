<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use Cbox\Billing\Client\Http\WireId;

final readonly class RegistrationPolicy
{
    /**
     * @param  string  $id  the program's id at Billing: `acp_…` from Billing v2, a decimal string before it
     * @param  list<Question>  $questions
     * @param  array<string, string>  $countries
     */
    public function __construct(public string $id, public string $mode, public bool $loginRequired, public string $countryCollection, public array $countries, public array $questions) {}

    public static function from(mixed $value): self
    {
        $id = is_array($value) ? WireId::from($value['id'] ?? null) : null;
        if (! is_array($value) || $id === null
            || ! in_array($value['mode'] ?? null, ['open', 'waitlist', 'invite_only', 'paused'], true)
            || ! is_bool($value['login_required'] ?? null)
            || ! in_array($value['country_collection'] ?? null, ['hidden', 'optional', 'required'], true)
            || ! is_array($value['countries'] ?? null) || ! is_array($value['questions'] ?? null)) {
            throw new ServiceAccessUnavailable;
        }
        $countries = [];
        foreach ($value['countries'] as $code => $name) {
            if (! is_string($code) || preg_match('/^[A-Z]{2}$/', $code) !== 1 || ! is_string($name)) {
                throw new ServiceAccessUnavailable;
            }
            $countries[$code] = $name;
        }

        return new self($id, $value['mode'], $value['login_required'], $value['country_collection'], $countries, array_values(array_map(Question::from(...), $value['questions'])));
    }
}
