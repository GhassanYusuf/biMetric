<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\TokenController;
use App\Models\User;
use App\Support\IntegrationAbilities;
use Illuminate\Console\Command;

/**
 * Mints a scoped API token for a user, from the shell.
 *
 * The way to give unattended software its credential: create a dedicated service user
 * (least-privileged role), then run this for that account. The plain-text token is
 * printed once and cannot be recovered — only its hash is stored.
 */
class IssueIntegrationToken extends Command
{
    protected $signature = 'integration:token
        {email : The user the token acts as}
        {name : A label, e.g. "payroll-sync"}
        {--ability=* : Ability to grant (repeatable). Default: all of them}
        {--days= : Expire after this many days. Default: never}';

    protected $description = 'Issue a scoped integration API token (see docs/integration-api.md)';

    public function handle(): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || ! $user->is_active) {
            $this->error('No active user with that email.');

            return self::FAILURE;
        }

        $abilities = $this->option('ability') ?: IntegrationAbilities::names();
        $unknown = array_diff($abilities, IntegrationAbilities::names());

        if ($unknown !== []) {
            $this->error('Unknown ability: '.implode(', ', $unknown).'. Valid: '.implode(', ', IntegrationAbilities::names()));

            return self::FAILURE;
        }

        $token = $user->createToken(
            TokenController::PREFIX.$this->argument('name'),
            array_values($abilities),
            $this->option('days') ? now()->addDays((int) $this->option('days')) : null,
        );

        $this->info('Token for '.$user->email.' ('.implode(', ', $abilities).'):');
        $this->line($token->plainTextToken);
        $this->warn('Store it now — it cannot be shown again.');

        return self::SUCCESS;
    }
}
