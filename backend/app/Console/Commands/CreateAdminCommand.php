<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {--name= : Admin display name}
        {--email= : Admin email address}
        {--password= : Admin password (min 8 chars). Prompted hidden if omitted in interactive mode.}';

    protected $description = 'Create an administrator account. Run on the server — never expose this via HTTP.';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Admin name');
        $email = $this->option('email') ?: $this->ask('Admin email');
        $password = $this->option('password') ?: $this->secret('Admin password (min 8 chars)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            $this->error('Cannot create admin:');
            foreach ($validator->errors()->all() as $message) {
                $this->line("  • {$message}");
            }
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->info("Admin created: #{$user->id} <{$user->email}>");

        return self::SUCCESS;
    }
}
