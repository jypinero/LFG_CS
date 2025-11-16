<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminAction
{
	protected AuditLogger $auditLogger;

	public function __construct(AuditLogger $auditLogger)
	{
		$this->auditLogger = $auditLogger;
	}

	public function handle(Request $request, Closure $next): Response
	{
		$response = $next($request);
		// Only log write actions
		if (in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
			$user = $request->user();
			$action = sprintf('%s %s', strtoupper($request->method()), $request->path());
			$this->auditLogger->logAction(
				$user?->id,
				$user ? 'admin' : 'system',
				$action,
				null,
				['body' => $request->except(['password', 'token'])],
				$request
			);
		}
		return $response;
	}
}


