<?php

namespace App\Support\Build;

final readonly class BuildInfo
{
    /**
     * @return array{service: string, version: string, commit: string, built_at: string}
     */
    public function toArray(): array
    {
        return [
            'service' => (string) config('utcp.service.name', 'utcp-api'),
            'version' => (string) config('utcp.build.version', '0.1.0-dev'),
            'commit' => (string) config('utcp.build.commit', 'unknown'),
            'built_at' => (string) config('utcp.build.built_at', 'unknown'),
        ];
    }
}
