<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = \App\Models\User::firstOrNew([
    'username' => 'sysadmin'
]);

$admin->name = $admin->name ?: 'QRPass System Administrator';
$admin->email = $admin->email ?: 'sysadmin@qrpass.local';
$admin->role = 'sysadmin';
$admin->status = 'approved';

$admin->password = \Illuminate\Support\Facades\Hash::make(
    'QRPassAdmin@2026!'
);

$admin->saveOrFail();

echo "ADMIN ACCOUNT READY!" . PHP_EOL;
