<?php

namespace App\RuntimeRegistry\AdapterConfiguration;

use InvalidArgumentException;

final class AdapterConfigurationFieldDescriptor
{
    public const INPUT_TEXT = 'text';

    public const INPUT_INTEGER = 'integer';

    public const INPUT_JSON = 'json';

    /**
     * @param  array<string, int>  $validation
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $help,
        public readonly string $inputType,
        public readonly bool $required,
        public readonly bool $readOnly,
        public readonly bool $writeOnly,
        public readonly mixed $default,
        public readonly int $order,
        public readonly array $validation = [],
    ) {
        $this->validate();
    }

    /**
     * @param  array<string, int>  $validation
     */
    public static function text(
        string $key,
        string $label,
        string $help,
        bool $required,
        mixed $default,
        int $order,
        array $validation = [],
        bool $readOnly = false,
        bool $writeOnly = false,
    ): self {
        return new self($key, $label, $help, self::INPUT_TEXT, $required, $readOnly, $writeOnly, $default, $order, $validation);
    }

    /**
     * @param  array<string, int>  $validation
     */
    public static function integer(
        string $key,
        string $label,
        string $help,
        bool $required,
        mixed $default,
        int $order,
        array $validation = [],
        bool $readOnly = false,
        bool $writeOnly = false,
    ): self {
        return new self($key, $label, $help, self::INPUT_INTEGER, $required, $readOnly, $writeOnly, $default, $order, $validation);
    }

    public static function json(
        string $key,
        string $label,
        string $help,
        bool $required,
        mixed $default,
        int $order,
        bool $readOnly = false,
        bool $writeOnly = false,
    ): self {
        return new self($key, $label, $help, self::INPUT_JSON, $required, $readOnly, $writeOnly, $default, $order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $descriptor = [
            'key' => $this->key,
            'label' => $this->label,
            'help' => $this->help,
            'input_type' => $this->inputType,
            'required' => $this->required,
            'read_only' => $this->readOnly,
            'write_only' => $this->writeOnly,
            'default' => $this->default,
            'order' => $this->order,
        ];

        if ($this->validation !== []) {
            $descriptor['validation'] = $this->validation;
        }

        return $descriptor;
    }

    private function validate(): void
    {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException('Adapter configuration field key must not be empty.');
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException('Adapter configuration field label must not be empty.');
        }
        if (! in_array($this->inputType, [self::INPUT_TEXT, self::INPUT_INTEGER, self::INPUT_JSON], true)) {
            throw new InvalidArgumentException('Unsupported adapter configuration field input type.');
        }
        if ($this->order < 1) {
            throw new InvalidArgumentException('Adapter configuration field order must be positive.');
        }
        if ($this->readOnly && $this->writeOnly) {
            throw new InvalidArgumentException('Adapter configuration field cannot be both read-only and write-only.');
        }
        if ($this->writeOnly && $this->default !== null) {
            throw new InvalidArgumentException('Write-only adapter configuration fields must not publish defaults.');
        }

        $this->validateHints();
    }

    private function validateHints(): void
    {
        $allowed = match ($this->inputType) {
            self::INPUT_TEXT => ['min_length', 'max_length'],
            self::INPUT_INTEGER => ['min', 'max', 'step'],
            self::INPUT_JSON => [],
            default => [],
        };

        foreach ($this->validation as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Adapter configuration validation hint is incompatible with the field input type.');
            }
            if (! is_int($value)) {
                throw new InvalidArgumentException('Adapter configuration validation hints must be integers.');
            }
        }

        if (isset($this->validation['min'], $this->validation['max']) && $this->validation['min'] > $this->validation['max']) {
            throw new InvalidArgumentException('Adapter configuration numeric validation range is invalid.');
        }
        if (isset($this->validation['min_length'], $this->validation['max_length']) && $this->validation['min_length'] > $this->validation['max_length']) {
            throw new InvalidArgumentException('Adapter configuration text validation range is invalid.');
        }
        if (isset($this->validation['step']) && $this->validation['step'] < 1) {
            throw new InvalidArgumentException('Adapter configuration numeric validation step is invalid.');
        }
    }
}
