<?php

use App\Mail\DatabaseBackupMail;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * These run the real `backup:run`, against a throwaway SQLite file, so the zip
 * is the one spatie/laravel-backup makes. That needs the `sqlite3` binary,
 * which GitHub's Ubuntu runners have.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');

    $this->database = storage_path('framework/testing/backup-source.sqlite');
    File::ensureDirectoryExists(dirname($this->database));
    File::put($this->database, '');

    config()->set('database.connections.backup_source', [
        'driver' => 'sqlite',
        'database' => $this->database,
        'prefix' => '',
    ]);
    DB::connection('backup_source')->statement('create table channels (id integer primary key, title text)');
    DB::connection('backup_source')->table('channels')->insert(['title' => 'Practical Engineering']);

    config()->set('backup.backup.name', 'calm-tube-backups');
    config()->set('backup.backup.source.databases', ['backup_source']);
    config()->set('backup.mail.recipient', 'backups@example.com');
});

afterEach(function (): void {
    DB::purge('backup_source');
    File::delete($this->database);
});

function latestBackupZip(): string
{
    $zips = Storage::disk('local')->files('calm-tube-backups');

    expect($zips)->toHaveCount(1)
        ->and($zips[0])->toEndWith('.zip');

    return $zips[0];
}

it('dumps the database into a zip', function (): void {
    $this->artisan('backup:email-db')->assertSuccessful();

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path(latestBackupZip())))->toBeTrue();

    $dump = $zip->getFromIndex(0);
    $zip->close();

    expect($dump)->toContain('Practical Engineering');
});

it('leaves archived images and throwaway tables out of the dump', function (): void {
    DB::connection('backup_source')->statement('create table archived_images (id integer primary key, contents text)');
    DB::connection('backup_source')->table('archived_images')->insert(['contents' => 'base64-thumbnail-bytes']);
    DB::connection('backup_source')->statement('create table sessions (id text primary key, payload text)');
    DB::connection('backup_source')->table('sessions')->insert(['id' => 'abc', 'payload' => 'session-payload']);

    $this->artisan('backup:email-db')->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(latestBackupZip()));
    $dump = $zip->getFromIndex(0);
    $zip->close();

    expect($dump)->toContain('Practical Engineering')
        ->not->toContain('base64-thumbnail-bytes')
        ->not->toContain('session-payload');
});

it('emails the zip as an attachment', function (): void {
    $this->artisan('backup:email-db')
        ->expectsOutputToContain('Database backup emailed to backups@example.com with attachment')
        ->assertSuccessful();

    $path = latestBackupZip();

    Mail::assertSent(DatabaseBackupMail::class, function (DatabaseBackupMail $mail) use ($path): bool {
        $attachments = $mail->attachments();

        return $mail->hasTo('backups@example.com')
            && count($attachments) === 1
            && $attachments[0]->isEquivalent(
                Attachment::fromPath(Storage::disk('local')->path($path))
                    ->as(basename($path))
                    ->withMime('application/zip')
            );
    });
});

it('says so in the email when the zip is too big for Resend', function (): void {
    config()->set('backup.mail.attachment_limit_bytes', 10);

    $this->artisan('backup:email-db')
        ->expectsOutputToContain('too big to attach')
        ->assertSuccessful();

    Mail::assertSent(DatabaseBackupMail::class, function (DatabaseBackupMail $mail): bool {
        $mail->assertSeeInHtml('too big to attach');
        $mail->assertSeeInHtml(basename(latestBackupZip()), escape: false);

        return $mail->hasTo('backups@example.com')
            && $mail->attachments() === []
            && str_contains((string) $mail->envelope()->subject, 'too big to attach');
    });
});

it('fails with a clear message when no recipient is set', function (): void {
    config()->set('backup.mail.recipient');

    $this->artisan('backup:email-db')
        ->expectsOutputToContain('No backup recipient is configured. Set BACKUP_RECIPIENT_EMAIL.')
        ->assertFailed();

    expect(Storage::disk('local')->files('calm-tube-backups'))->toBe([]);
    Mail::assertNothingSent();
});

it('fails when the backup itself fails', function (): void {
    config()->set('backup.backup.source.databases', ['missing_connection']);

    $this->artisan('backup:email-db')
        ->expectsOutputToContain('Database backup command failed.')
        ->assertFailed();

    Mail::assertNothingSent();
});
