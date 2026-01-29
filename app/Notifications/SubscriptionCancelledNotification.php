<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelledNotification extends Notification
{
    use Queueable;

    protected $subscription;

    /**
     * Create a new notification instance.
     */
    public function __construct($subscription)
    {
        $this->subscription = $subscription;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $plan = config("subscriptions.{$this->subscription->plan}", []);
        $planName = $plan['name'] ?? ucfirst($this->subscription->plan) . ' Plan';

        return (new MailMessage)
            ->subject('Subscription Cancelled')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your subscription has been cancelled as requested.')
            ->line('Plan: ' . $planName)
            ->line('Cancellation date: ' . $this->subscription->cancelled_at->format('M d, Y'))
            ->line('Your subscription will remain active until: ' . $this->subscription->ends_at->format('M d, Y'))
            ->line('You will continue to have access to all features until the expiration date.')
            ->action('Resubscribe', url('/subscription'))
            ->line('Thank you for being with us!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
