<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Registra uma ação. Em $metadata vão apenas nomes de campos e
     * identificadores, nunca valores de dados pessoais.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function log(AuditAction $action, ?Model $subject = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
        ]);
    }
}
