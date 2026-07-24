<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class RuntimeReconciliationDetailResource extends RuntimeReconciliationListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
