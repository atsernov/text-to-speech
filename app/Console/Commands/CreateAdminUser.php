<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--email= : Admin email (skips interactive prompt)}
                            {--name= : Admin name (skips interactive prompt)}
                            {--password= : Admin password (skips interactive prompt)}';

    protected $description = 'Create a new admin user or promote an existing user to admin';

    public function handle(): int
    {
        // Support non-interactive mode (for Docker entrypoint / CI)
        $email = $this->option('email') ?? $this->ask('Email');

        $existing = User::where('email', $email)->first();

        if ($existing) {
            $existing->update(['is_admin' => true]);
            $this->info("User [{$email}] has been promoted to admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?? $this->ask('Name');
        $password = $this->option('password') ?? $this->secret('Password');

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Admin user [{$email}] created successfully.");

        return self::SUCCESS;
    }
}
