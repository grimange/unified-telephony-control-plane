<?php

namespace App\RuntimeRegistry;

final class RuntimeNodeFailureDomainEvaluator
{
    public function eligible(object $node, ?object $observation): bool
    {
        $region = $node->placement_region !== null ? (string) $node->placement_region : null;
        $zone = $node->placement_zone !== null ? (string) $node->placement_zone : null;
        if ($region === null && $zone === null) {
            return true;
        }
        if ($observation === null) {
            return false;
        }

        $status = (string) ($observation->status ?? '');
        if (! in_array($status, ['placed', 'ambiguous_multiple_nodes_observed'], true)) {
            return false;
        }

        return ($region === null || ($observation->observed_region !== null && (string) $observation->observed_region === $region))
            && ($zone === null || ($observation->observed_zone !== null && (string) $observation->observed_zone === $zone));
    }
}
