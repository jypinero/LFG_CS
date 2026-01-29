<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionUpgradedNotification extends Notification
{
    use Queueable;

    protected $oldSubscription;
    protected $newSubscription;

    /**
     * Create a new notification instance.
     */
    public function __construct($oldSubscription, $newSubscription)
    {
        $this->oldSubscription = $oldSubscription;
        $this->newSubscription = $newSubscription;
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
        $oldPlan = config("subscriptions.{$this->oldSubscription->plan}", []);
        $newPlan = config("subscriptions.{$this->newSubscription->plan}", []);
        
        $oldPlanName = $oldPlan['name'] ?? ucfirst($this->oldSubscription->plan) . ' Plan';
        $newPlanName = $newPlan['name'] ?? ucfirst($this->newSubscription->plan) . ' Plan';

        return (new MailMessage)
            ->subject('Subscription Upgraded')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your subscription has been successfully upgraded!')
            ->line('Previous Plan: ' . $oldPlanName)
            ->line('New Plan: ' . $newPlanName)
            ->line('Upgrade Date: ' . now()->format('M d, Y'))
            ->line('New Subscription Period: ' . $this->newSubscription->starts_at->format('M d, Y') . ' to ' . $this->newSubscription->ends_at->format('M d, Y'))
            ->line('You now have access to all premium features!')
            ->action('Manage Subscription', url('/subscription/manage'))
            ->line('Thank you for upgrading!');
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
