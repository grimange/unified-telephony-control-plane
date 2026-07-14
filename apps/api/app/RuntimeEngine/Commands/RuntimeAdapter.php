<?php

namespace App\RuntimeEngine\Commands;

interface RuntimeAdapter
{
    public function adapterKey(): string;

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function execute(array $operation): array;
}
