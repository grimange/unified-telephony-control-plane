<?php

namespace App\RuntimeEngine\Reconciliation;

interface Reconciler
{
    public function targetType(): string;

    public function evaluate(object $target): ReconciliationResult;
}
