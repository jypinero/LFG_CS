<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\User;
use App\Models\Venue;
use App\Models\Event;
use App\Models\SupportTicket;
use App\Services\AuditLogger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
		// Minimal observers for audit logging
		$logger = app(AuditLogger::class);

		User::created(function (User $user) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'user.created', $user);
		});
		User::updated(function (User $user) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'user.updated', $user);
		});

		Venue::created(function (Venue $venue) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'venue.created', $venue);
		});
		Venue::updated(function (Venue $venue) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'venue.updated', $venue);
		});

		Event::created(function (Event $event) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'event.created', $event);
		});
		Event::updated(function (Event $event) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'event.updated', $event);
		});

		SupportTicket::created(function (SupportTicket $t) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'ticket.created', $t);
		});
		SupportTicket::updated(function (SupportTicket $t) use ($logger) {
			$logger->logAction(auth()->id(), auth()->check() ? 'admin' : 'system', 'ticket.updated', $t);
		});
    }
}
