<?php

namespace App\RuntimeRegistry\AdapterConfiguration;

use InvalidArgumentException;

final class AdapterConfigurationDescriptorCollection
{
    /**
     * @param  list<AdapterConfigurationFieldDescriptor>  $fields
     */
    public function __construct(private readonly array $fields)
    {
        $keys = [];
        $orders = [];
        foreach ($this->fields as $field) {
            $keys[] = $field->key;
            $orders[] = $field->order;
        }

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Adapter configuration field keys must be unique.');
        }
        if (count($orders) !== count(array_unique($orders))) {
            throw new InvalidArgumentException('Adapter configuration field order values must be unique.');
        }
    }

    /**
     * @return list<AdapterConfigurationFieldDescriptor>
     */
    public function fields(): array
    {
        $fields = $this->fields;
        usort($fields, fn (AdapterConfigurationFieldDescriptor $left, AdapterConfigurationFieldDescriptor $right): int => $left->order <=> $right->order);

        return $fields;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'fields' => array_map(
                fn (AdapterConfigurationFieldDescriptor $field): array => $field->toArray(),
                $this->fields(),
            ),
        ];
    }
}
