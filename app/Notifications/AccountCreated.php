<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new account's welcome email, to the address it was made with: who made
 * it and as what, and a link to set their own password — the "new_users"
 * password broker's token, good for a week and once (config/auth.php,
 * Auth\SetPasswordController). Sent when HR Manager or Admin adds someone,
 * and again whenever they send a fresh link (Admin\UserController).
 */
class AccountCreated extends Notification
{
    use Queueable;

    /**
     * How long the link works, in days — the "new_users" broker's expiry.
     */
    public const LINK_DAYS = 7;

    public function __construct(
        public string $token,
        public User $createdBy,
    ) {}

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
        $company = Setting::companyName();
        $roles = $notifiable->roles->pluck('name')->join(', ', ' and ');

        return (new MailMessage)
            ->subject("Your {$company} account is ready")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->createdBy->name} has created an account for you".($roles !== '' ? " as {$roles}" : '').'.')
            ->line('Set your password to sign in:')
            ->action('Set your password', $this->url($notifiable))
            ->line('The link works for '.self::LINK_DAYS.' days, and only once. After that, ask HR for a new one.')
            ->line("You'll sign in with this email address: {$notifiable->email}")
            ->salutation("— {$company}");
    }

    /**
     * Where the link goes: the set-password page, for this token and address.
     */
    public function url(object $notifiable): string
    {
        return route('password.set', ['token' => $this->token, 'email' => $notifiable->email]);
    }
}
