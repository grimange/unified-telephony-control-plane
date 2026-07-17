<?php

namespace App\RuntimeEngine\Reconciliation;

final class RuntimeNodeReconciler implements Reconciler
{
    /**
     * @param  iterable<Reconciler>  $reconcilers
     */
    public function __construct(private readonly iterable $reconcilers) {}

    public function targetType(): string
    {
        return 'runtime_node';
    }

    public function evaluate(object $target): ReconciliationResult
    {
        foreach ($this->reconcilers as $reconciler) {
            if ($reconciler === $this || $reconciler->targetType() !== 'runtime_node') {
                continue;
            }
            if (method_exists($reconciler, 'supports') && ! $reconciler->supports($target)) {
                continue;
            }

            return $reconciler->evaluate($target);
        }

        return ReconciliationResult::unsupported('runtime_node_adapter_reconciler_not_registered');
    }
}
