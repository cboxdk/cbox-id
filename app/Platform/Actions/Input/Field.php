<?php

declare(strict_types=1);

namespace App\Platform\Actions\Input;

/**
 * One input an action takes, described once and read twice: as Laravel validation rules
 * (so every door validates the same way and a refusal is the same `validation_failed`),
 * and as JSON Schema (so an MCP client and the API reference are told the same shape).
 *
 * Deliberately small. It covers what the management API's inputs are — strings, integers,
 * booleans, lists and objects of those — and nothing a validator rule could express that
 * a schema could not.
 */
final class Field
{
    private bool $required = false;

    private bool $nullable = false;

    private bool $distinct = false;

    private bool $inPath = false;

    private ?int $max = null;

    private ?int $min = null;

    private ?string $format = null;

    private ?string $description = null;

    /** @var list<string>|null */
    private ?array $enum = null;

    /**
     * @param  'string'|'integer'|'boolean'|'array'|'object'  $type
     * @param  list<Field>  $properties  An object's fields.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        private readonly ?Field $items = null,
        private readonly array $properties = [],
    ) {}

    public static function string(string $name): self
    {
        return new self($name, 'string');
    }

    public static function integer(string $name): self
    {
        return new self($name, 'integer');
    }

    public static function boolean(string $name): self
    {
        return new self($name, 'boolean');
    }

    /** A list whose items are $items (its name is ignored). */
    public static function list(string $name, Field $items): self
    {
        return new self($name, 'array', $items);
    }

    /**
     * @param  list<Field>  $properties
     */
    public static function object(string $name, array $properties): self
    {
        return new self($name, 'object', properties: $properties);
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    /** Within a list of objects: no two items may share this field's value. */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /** Taken from the URL on REST (`/apis/{id}`), an ordinary argument everywhere else. */
    public function inPath(): self
    {
        $this->inPath = true;
        $this->required = true;

        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * @param  list<string>  $values
     */
    public function oneOf(array $values): self
    {
        $this->enum = $values;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isInPath(): bool
    {
        return $this->inPath;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Laravel validation rules for this field at $path, its children included.
     *
     * @return array<string, list<string>>
     */
    public function rules(?string $path = null): array
    {
        $path ??= $this->name;

        $rules = [$this->required ? 'required' : 'sometimes'];

        if ($this->nullable) {
            $rules[] = 'nullable';
        }

        if ($this->distinct) {
            $rules[] = 'distinct';
        }

        $rules = [...$rules, ...match ($this->type) {
            'string' => ['string'],
            'integer' => ['integer'],
            'boolean' => ['boolean'],
            'array' => ['array'],
            'object' => ['array:'.implode(',', array_map(static fn (Field $field): string => $field->name, $this->properties))],
        }];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        if ($this->enum !== null) {
            $rules[] = 'in:'.implode(',', $this->enum);
        }

        $all = [$path => $rules];

        if ($this->items !== null) {
            $all = [...$all, ...$this->items->rules($path.'.*')];
        }

        foreach ($this->properties as $property) {
            $all = [...$all, ...$property->rules($path.'.'.$property->name)];
        }

        return $all;
    }

    /**
     * The JSON Schema of this field's value.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $schema = ['type' => $this->nullable ? [$this->type, 'null'] : $this->type];

        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }

        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }

        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        }

        $bound = $this->type === 'string' ? 'Length' : ($this->type === 'array' ? 'Items' : '');

        if ($this->min !== null) {
            $schema[$bound === '' ? 'minimum' : 'min'.$bound] = $this->min;
        }

        if ($this->max !== null) {
            $schema[$bound === '' ? 'maximum' : 'max'.$bound] = $this->max;
        }

        if ($this->items !== null) {
            $schema['items'] = $this->items->schema();
        }

        if ($this->type === 'object') {
            $schema = [...$schema, ...InputSchema::of($this->properties)->jsonSchema()];
        }

        return $schema;
    }
}
