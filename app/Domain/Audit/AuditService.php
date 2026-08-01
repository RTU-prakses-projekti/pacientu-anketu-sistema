<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    private const SENSITIVE_KEYS = ['password', 'token', 'access_code', 'answer', 'answers', 'consent_text'];

    public function record(string $action, ?Model $subject = null, ?int $organisationId = null, array $metadata = []): AuditLog
    {
        foreach (self::SENSITIVE_KEYS as $key) unset($metadata[$key]);
        $request = request();

        return AuditLog::create([
            'organisation_id' => $organisationId,
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'request_id' => $request?->header('X-Request-ID', (string) Str::uuid()),
            'ip_hash' => $request?->ip() ? hash('sha256', $request->ip().'|'.config('app.key')) : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
