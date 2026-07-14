<?php

namespace App\ControlPlane\Idempotency;

use DomainException;

final class IdempotencyConflict extends DomainException {}
