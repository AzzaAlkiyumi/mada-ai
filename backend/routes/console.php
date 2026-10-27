<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/*
 * The app has one owner and no public sign-up. These commands create that
 * account and change its password; passwords are typed at the prompt and
 * never stored in code or .env.
 */

$askPassword = function ($command): ?string {
    $password = (string) $command->secret('Password (at least 12 characters)');

    if ($password !== (string) $command->secret('Repeat the password')) {
        $command->error('The passwords do not match.');

        return null;
    }

    $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:12', 'max:255']]);

    if ($validator->fails()) {
        $command->error($validator->errors()->first());

        return null;
    }

    return $password;
};

Artisan::command('user:create', function () use ($askPassword) {
    if (User::exists()) {
        $this->error('A user already exists. Use `php artisan user:password` to change its password.');

        return 1;
    }

    $name = (string) $this->ask('Name');
    $email = (string) $this->ask('Email');

    if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
        $this->error('Enter a valid email address.');

        return 1;
    }

    if (($password = $askPassword($this)) === null) {
        return 1;
    }

    User::create(['name' => $name !== '' ? $name : 'Owner', 'email' => strtolower($email), 'password' => $password]);
    $this->info('User created. You can now sign in.');

    return 0;
})->purpose('Create the single owner account');

Artisan::command('user:password', function () use ($askPassword) {
    $user = User::first();

    if (! $user) {
        $this->error('No user exists yet. Run `php artisan user:create` first.');

        return 1;
    }

    if (($password = $askPassword($this)) === null) {
        return 1;
    }

    $user->forceFill(['password' => $password, 'remember_token' => null])->save();
    $this->info("Password changed for {$user->email}.");

    return 0;
})->purpose('Change the owner account password');
