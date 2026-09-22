<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2: manual Super Admin creation. There is no public registration or
 * UI route for superadmin — this artisan command is the only way to create
 * the first one. Idempotent: re-running with the same email updates the
 * password (after confirmation) instead of creating a duplicate.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'superadmin:create
        {--email= : Super Admin email (asked interactively if omitted)}
        {--password= : Super Admin password, min 12 chars (asked interactively if omitted)}';

    protected $description = 'Create (or update) a Super Admin account. Interactive and idempotent.';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Super Admin email');

        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $password = $this->option('password');

        if (!$password) {
            $password = $this->secret('Super Admin password (min 12 characters)');
            $confirm = $this->secret('Confirm password');

            if ($password !== $confirm) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        if (!is_string($password) || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            if (!$existing->isSuperAdmin()) {
                $this->error("A non-superadmin account already uses {$email}. Refusing to escalate it.");

                return self::FAILURE;
            }

            // Non-interactive re-runs (scripts, tests with --no-interaction)
            // proceed — the operator explicitly invoked the command.
            $noInteraction = (bool) $this->input->getOption('no-interaction');
            if (!$noInteraction && !$this->confirm("Super Admin {$email} already exists. Update its password?", false)) {
                $this->info('No changes made.');

                return self::SUCCESS;
            }

            $existing->forceFill(['password' => Hash::make($password)])->save();
            $existing->tokens()->delete();

            AuditLogger::log(null, 'superadmin.password_updated', User::class, $existing->id, ['email' => $email]);

            $this->info("Super Admin {$email} password updated; existing tokens revoked.");

            return self::SUCCESS;
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        AuditLogger::log(null, 'superadmin.created', User::class, $user->id, ['email' => $email]);

        $this->info("Super Admin {$email} created (id {$user->id}).");

        return self::SUCCESS;
    }
}
