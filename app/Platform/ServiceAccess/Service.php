<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

final readonly class Service
{
    /** @param list<array{key: string, label: string, required: bool}> $questions */
    public function __construct(public int $id, public string $key, public string $name, public ?string $description, public string $mode, public array $questions, public string $publicUrl) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value) || ! is_int($value['id'] ?? null) || ! is_string($value['key'] ?? null)
            || ! is_string($value['name'] ?? null) || ! is_string($value['mode'] ?? null)
            || ! in_array($value['mode'], ['waitlist', 'paused', 'invite_only', 'open'], true)
            || ! is_array($value['questions'] ?? null) || ! is_string($value['public_url'] ?? null)) {
            throw new ServiceAccessUnavailable('Malformed service directory response.');
        }
        $questions = [];
        foreach ($value['questions'] as $question) {
            if (! is_array($question) || ! is_string($question['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $question['key']) !== 1
                || ! is_string($question['label'] ?? null) || ! is_bool($question['required'] ?? null)) {
                throw new ServiceAccessUnavailable('Malformed waitlist question.');
            }
            $questions[] = ['key' => $question['key'], 'label' => $question['label'], 'required' => $question['required']];
        }
        $url = parse_url($value['public_url']);
        if (! is_array($url) || ! isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || (($url['scheme'] ?? '') !== 'https' && ! (app()->environment('local', 'testing') && ($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost', '127.0.0.1'], true)))) {
            throw new ServiceAccessUnavailable('Invalid public waitlist address.');
        }

        return new self($value['id'], $value['key'], $value['name'], is_string($value['description'] ?? null) ? $value['description'] : null, $value['mode'], $questions, $value['public_url']);
    }
}
