<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * Creates the account that signs in, or resets its password.
 *
 * There is no registration or password reset on the site, so this is the only
 * way in. Leave the password off to be prompted for it, which keeps it out of
 * the server's shell history. The rules are the profile page's own.
 */
class MakeLoginCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'make:login
        {email : The address you will sign in with}
        {password? : Prompted for, hidden, when left off}
        {--name= : Defaults to the part of the email before the @}';

    protected $description = 'Create the login account, or reset its password if it already exists';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->firstOrNew(['email' => $email]);

        [$password, $confirmation] = $this->password();

        $validator = Validator::make([
            'name' => $this->option('name') ?: ($user->name ?? Str::before($email, '@')),
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($user->id),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existed = $user->exists;

        $user->forceFill([
            'name' => $validator->validated()['name'],
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info($existed
            ? "Password updated for {$email}."
            : "Created a login for {$email}.");

        return self::SUCCESS;
    }

    /**
     * A password typed on the command line is taken as meant; one typed at the
     * hidden prompt is asked for twice, because a typo there cannot be seen.
     *
     * @return array{string, string}
     */
    private function password(): array
    {
        $password = $this->argument('password');

        if ($password !== null) {
            return [(string) $password, (string) $password];
        }

        return [
            password('Password', required: true),
            password('Confirm password', required: true),
        ];
    }
}
