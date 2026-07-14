<?php

namespace App\Support\Health;

interface ReadinessChecker
{
    /**
     * @param  list<string>  $requiredDependencies
     */
    public function check(array $requiredDependencies): ReadinessReport;
}
