<?php

namespace App\Notifications;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubmissionFinalizedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly FormSubmission $submission)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.submission_notification_subject'))
            ->line(__('messages.submission_notification_line', ['form' => $this->submission->publication->form->name]))
            ->action(__('messages.view'), route('admin.submissions.show', $this->submission));
    }
}
