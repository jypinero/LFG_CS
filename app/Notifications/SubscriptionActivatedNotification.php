<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionActivatedNotification extends Notification
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
        return (new MailMessage)
            ->subject('Subscription Activated')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your subscription has been successfully activated.')
            ->line('Plan: ' . $this->subscription->plan)
            ->line('Amount: PHP ' . number_format($this->subscription->amount / 100, 2))
            ->line('Subscription period: ' . $this->subscription->starts_at->format('M d, Y') . ' to ' . $this->subscription->ends_at->format('M d, Y'))
            ->action('Go to Dashboard', url('/dashboard'))
            ->line('Thank you for choosing our service!');
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
