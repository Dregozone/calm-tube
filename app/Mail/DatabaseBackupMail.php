<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Number;

class DatabaseBackupMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $backupPath,
        public readonly string $backupFilename,
        public readonly int $backupSize,
        public readonly bool $attached = true,
    ) {}

    public function envelope(): Envelope
    {
        $subject = config('app.name').' — Database Backup '.now()->format('Y-m-d H:i');

        return new Envelope(
            subject: $this->attached ? $subject : $subject.' (too big to attach)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.database-backup',
            with: [
                'size' => Number::fileSize($this->backupSize, precision: 1),
                'limit' => Number::fileSize((int) config('backup.mail.attachment_limit_bytes')),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attached) {
            return [];
        }

        return [
            Attachment::fromPath($this->backupPath)
                ->as($this->backupFilename)
                ->withMime('application/zip'),
        ];
    }
}
