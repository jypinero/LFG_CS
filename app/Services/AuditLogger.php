<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
	public function logAction(?int $actorId, string $actorType, string $action, ?Model $entity = null, array $metadata = [], ?Request $request = null): AuditLog
	{
		return AuditLog::create([
			'actor_id' => $actorId,
			'actor_type' => $actorType,
			'action' => $action,
			'entity_type' => $entity ? get_class($entity) : null,
			'entity_id' => $entity?->getKey(),
			'metadata' => $metadata,
			'ip' => $request?->ip(),
			'user_agent' => $request?->userAgent(),
		]);
	}
}


