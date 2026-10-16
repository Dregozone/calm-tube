<?php

namespace App\Console\Commands;

use App\Mail\DatabaseBackupMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Dumps and zips the database with spatie/laravel-backup, then emails the zip.
 *
 * The same command as Evoluna and Whenish, so Forge schedules it the same way:
 * a daily job running `php artisan backup:email-db`. Archived images and
 * throwaway tables are left out (`backup.mail.excluded_tables`): the images
 * can be downloaded again, and leaving them out keeps the zip small enough to
 * attach. A zip still too big for Resend is not attached; the email says so,
 * so a backup never fails silently.
 */
class EmailDatabaseBackup extends Command
{
    protected $signature = 'backup:email-db';

    protected $description = 'Run a database backup and email it via Resend';

    public function handle(): int
    {
        $recipient = config('backup.mail.recipient');

        if (blank($recipient)) {
            $this->error('No backup recipient is configured. Set BACKUP_RECIPIENT_EMAIL.');

            return self::FAILURE;
        }

        $this->excludeTablesFromDump();

        if ($this->call('backup:run', ['--only-db' => true]) !== self::SUCCESS) {
            $this->error('Database backup command failed.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $directory = trim((string) config('backup.backup.name', config('app.name')), '/');
        $files = array_values(array_filter(
            $disk->files($directory),
            fn (string $file): bool => str_ends_with($file, '.zip'),
        ));

        if ($files === []) {
            $this->error('No backup files were found in ['.$disk->path($directory).'].');

            return self::FAILURE;
        }

        usort(
            $files,
            fn (string $first, string $second): int => $disk->lastModified($second) <=> $disk->lastModified($first),
        );

        $latest = $files[0];
        $filename = basename($latest);
        $size = $disk->size($latest);
        $attach = $this->encodedSize($size) <= (int) config('backup.mail.attachment_limit_bytes');

        // Sent synchronously: the attachment is read from local disk, which a queued job may not share.
        Mail::to((string) $recipient)->send(new DatabaseBackupMail($disk->path($latest), $filename, $size, $attach));

        if (! $attach) {
            $this->warn("{$filename} is ".Number::fileSize($size).', too big to attach. Emailed without it.');

            return self::SUCCESS;
        }

        $this->info("Database backup emailed to {$recipient} with attachment {$filename}.");

        return self::SUCCESS;
    }

    /**
     * Hands the excluded tables to spatie's dumper, which reads them from each
     * backed-up connection's `dump` config, for MySQL and SQLite alike.
     */
    private function excludeTablesFromDump(): void
    {
        $tables = (array) config('backup.mail.excluded_tables', []);

        foreach ((array) config('backup.backup.source.databases', []) as $connection) {
            config()->set("database.connections.{$connection}.dump.exclude_tables", $tables);
        }
    }

    /**
     * Resend counts an attachment at its base64 size, a third larger than the file.
     */
    private function encodedSize(int $bytes): int
    {
        return (int) ceil($bytes / 3) * 4;
    }
}
