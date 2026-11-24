<?php

namespace App\Notifications;

use App\Models\Rfq;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email to whoever created an RFQ — Business Development — once the
 * General Manager has approved it all (Rfq::approveByGm(); since 2026-11-25,
 * at the user's request — before then, once it was closed): it's ready to
 * share with the customer. The wording is the business's own (2026-11-16).
 * Mail only, so it's not on the RFQ's timeline.
 */
class RfqReadyToShare extends Notification
{
    use Queueable;

    public function __construct(public Rfq $rfq) {}

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
        $readyToShare = self::readyToShare($this->rfq);

        return (new MailMessage)
            ->subject($readyToShare)
            ->greeting("Dear {$notifiable->name},")
            ->line("{$readyToShare}. Please do the needful to share the final file with them at earliest possible.")
            ->salutation('— '.Setting::companyName());
    }

    /**
     * "Your RFQ1838 is ready to share with customer (WC0191)" — the subject,
     * and how the body begins. An RFQ without a WC number leaves the
     * brackets out.
     */
    public static function readyToShare(Rfq $rfq): string
    {
        return "Your {$rfq->rfq_number} is ready to share with customer".($rfq->wc_number ? " ({$rfq->wc_number})" : '');
    }
}
