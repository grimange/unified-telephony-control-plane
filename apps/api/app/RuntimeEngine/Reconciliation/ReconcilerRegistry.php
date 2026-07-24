<?php

namespace App\RuntimeEngine\Reconciliation;

final class ReconcilerRegistry
{
    /** @var array<string, Reconciler> */
    private array $reconcilers = [];

    /**
     * @param  iterable<Reconciler>  $reconcilers
     */
    public function __construct(iterable $reconcilers = [])
    {
        foreach ($reconcilers as $reconciler) {
            $this->register($reconciler);
        }
    }

    public function register(Reconciler $reconciler): void
    {
        $this->reconcilers[$reconciler->targetType()] = $reconciler;
    }

    public function find(string $targetType): ?Reconciler
    {
        return $this->reconcilers[$targetType] ?? null;
    }

    /**
     * @return list<string>
     */
    public function targetTypes(): array
    {
        $types = array_keys($this->reconcilers);
        sort($types);

        return $types;
    }
}
