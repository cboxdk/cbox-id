<?php

declare(strict_types=1);

namespace App\Platform\Actions\Input;

/**
 * Everything an action takes: its {@see Field}s, as validation rules and as one JSON
 * Schema object.
 */
final readonly class InputSchema
{
    /**
     * @param  list<Field>  $fields
     */
    private function __construct(public array $fields) {}

    /**
     * @param  list<Field>  $fields
     */
    public static function of(array $fields): self
    {
        return new self($fields);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rules = [...$rules, ...$field->rules()];
        }

        return $rules;
    }

    /**
     * An `object` schema with every field as a property and the required ones listed.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->fields as $field) {
            $properties[$field->name] = $field->schema();

            if ($field->isRequired()) {
                $required[] = $field->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The names taken from the URL on REST.
     *
     * @return list<string>
     */
    public function pathFields(): array
    {
        return array_values(array_map(
            static fn (Field $field): string => $field->name,
            array_filter($this->fields, static fn (Field $field): bool => $field->isInPath()),
        ));
    }
}
