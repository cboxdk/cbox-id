<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

final readonly class Service
{
    /**
     * @param  list<array{key: string, label: string, required: bool, type: string, options: list<string>, validation: string, min_length: int, max_length: int}>  $questions
     * @param  array<string, string>  $countries
     */
    public function __construct(public int $id, public string $key, public string $name, public ?string $description, public string $mode, public array $questions, public ?string $publicUrl, public string $kind = 'service', public string $scope = 'organization', public ?string $serviceName = null, public ?string $featureKey = null, public bool $loginRequired = false, public string $countryCollection = 'optional', public array $countries = [], public ?string $acceptanceUrl = null) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value) || ! is_int($value['id'] ?? null) || ! is_string($value['key'] ?? null)
            || ! is_string($value['name'] ?? null) || ! is_string($value['mode'] ?? null)
            || ! in_array($value['mode'], ['waitlist', 'paused', 'invite_only', 'open'], true)
            || ! is_array($value['questions'] ?? null)
            || ! in_array($value['kind'] ?? 'service', ['service', 'feature'], true)
            || ! in_array($value['scope'] ?? 'organization', ['organization', 'person'], true)
            || (($value['kind'] ?? 'service') === 'service' && ! is_string($value['public_url'] ?? null))) {
            throw new ServiceAccessUnavailable('Malformed service directory response.');
        }
        $questions = [];
        foreach ($value['questions'] as $question) {
            if (! is_array($question) || ! is_string($question['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $question['key']) !== 1
                || ! is_string($question['label'] ?? null) || ! is_bool($question['required'] ?? null)) {
                throw new ServiceAccessUnavailable('Malformed waitlist question.');
            }
            $type = $question['type'] ?? 'textarea';
            $validation = $question['validation'] ?? 'none';
            $options = $question['options'] ?? [];
            $min = $question['min_length'] ?? 0;
            $max = $question['max_length'] ?? 2000;
            if (! in_array($type, ['string', 'textarea', 'select', 'multiselect'], true)
                || ! in_array($validation, ['none', 'email', 'url'], true)
                || ! is_array($options) || ! array_is_list($options) || count($options) > 50
                || ! is_int($min) || ! is_int($max) || $min < 0 || $max < 1 || $max > 2000 || $min > $max) {
                throw new ServiceAccessUnavailable('Malformed waitlist question rules.');
            }
            $choices = [];
            foreach ($options as $option) {
                if (! is_string($option) || $option === '' || mb_strlen($option) > 200) {
                    throw new ServiceAccessUnavailable('Malformed waitlist choices.');
                }
                $choices[] = $option;
            }
            $questions[] = ['key' => $question['key'], 'label' => $question['label'], 'required' => $question['required'], 'type' => $type, 'options' => $choices, 'validation' => $validation, 'min_length' => $min, 'max_length' => $max];
        }
        $publicUrl = is_string($value['public_url'] ?? null) ? $value['public_url'] : null;
        $url = $publicUrl === null ? null : parse_url($publicUrl);
        if ($publicUrl !== null && (! is_array($url) || ! isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || (($url['scheme'] ?? '') !== 'https' && ! (app()->environment('local', 'testing') && ($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost', '127.0.0.1'], true))))) {
            throw new ServiceAccessUnavailable('Invalid public waitlist address.');
        }

        $acceptanceUrl = is_string($value['acceptance_url'] ?? null) ? $value['acceptance_url'] : null;
        if ($acceptanceUrl !== null) {
            $target = parse_url($acceptanceUrl);
            if (! is_array($target) || ($target['scheme'] ?? '') !== 'https' || empty($target['host']) || isset($target['user']) || isset($target['pass'])) {
                throw new ServiceAccessUnavailable('Invalid service signup address.');
            }
        }
        $countryCollection = $value['country_collection'] ?? 'optional';
        if (! in_array($countryCollection, ['hidden', 'optional', 'required'], true) || ! is_bool($value['login_required'] ?? false)) {
            throw new ServiceAccessUnavailable('Malformed waitlist registration policy.');
        }
        $countries = [];
        foreach (is_array($value['countries'] ?? null) ? $value['countries'] : [] as $code => $name) {
            if (! is_string($code) || preg_match('/^[A-Z]{2}$/', $code) !== 1 || ! is_string($name)) {
                throw new ServiceAccessUnavailable('Malformed country choices.');
            }
            $countries[$code] = $name;
        }

        return new self($value['id'], $value['key'], $value['name'], is_string($value['description'] ?? null) ? $value['description'] : null, $value['mode'], $questions, $publicUrl, $value['kind'] ?? 'service', $value['scope'] ?? 'organization', is_string($value['service_name'] ?? null) ? $value['service_name'] : null, is_string($value['feature_key'] ?? null) ? $value['feature_key'] : null, $value['login_required'] ?? false, $countryCollection, $countries, $acceptanceUrl);
    }
}
