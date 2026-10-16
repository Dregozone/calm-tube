<p>Hello,</p>

@if ($attached)
    <p>This is the latest database backup for {{ config('app.name') }}: {{ $backupFilename }} ({{ $size }}).</p>

    <p>Generated at {{ now()->format('d M Y, H:i') }} UTC.</p>

    <p>Please store this backup securely and delete this email once it has been archived.</p>
@else
    <p>The latest database backup for {{ config('app.name') }} was made, but at {{ $size }} it is too big to attach: Resend allows {{ $limit }} once it is encoded.</p>

    <p>It is on the server at <code>{{ $backupPath }}</code> until the next deploy. Download it from there, or change how the backup is delivered.</p>

    <p>Generated at {{ now()->format('d M Y, H:i') }} UTC.</p>
@endif
