<?php

namespace App\Infrastructure\RuntimeFencing;

interface RuntimeInfrastructureFenceAdapter
{
    public function adapterKey(): string;

    /**
     * @param  array<string, mixed>  $authorityContext
     * @return array{status:string,reason?:string,details?:array<string,mixed>}
     */
    public function fence(object $formerRuntimeNode, array $authorityContext): array;
}
