<?php

namespace App\RuntimeEngine\Commands;

interface RuntimeOperationHandler
{
    public function operationType(): string;

    public function payloadVersion(): int;

    public function requiredRuntimeCapability(): ?string;

    /**
     * @param  array<string, mixed>  $operation
     * @return array{status:string,event_type?:string,event_payload?:array<string,mixed>,failure_class?:string,failure_code?:string,failure_message?:string}
     */
    public function execute(array $operation, ?RuntimeAdapter $adapter): array;
}
