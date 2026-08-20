<?php

declare(strict_types=1);

use Componenta\Config\Loader\EnvLoader;

it('does not load sample or backup dotenv files unless explicitly requested', function (): void {
    $root = sys_get_temp_dir() . '/componenta_env_security_' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);

    file_put_contents($root . '/.env.example', "SAMPLE_ONLY=sample\n");
    file_put_contents($root . '/.env.backup', "BACKUP_ONLY=backup\n");

    unset($_ENV['SAMPLE_ONLY'], $_SERVER['SAMPLE_ONLY'], $_ENV['BACKUP_ONLY'], $_SERVER['BACKUP_ONLY']);

    try {
        expect((new EnvLoader($root))->read())->toBeNull()
            ->and((new EnvLoader($root, filenames: ['.env.example']))->read())
            ->toBe(['SAMPLE_ONLY' => 'sample']);
    } finally {
        @unlink($root . '/.env.example');
        @unlink($root . '/.env.backup');
        @rmdir($root);
        unset($_ENV['SAMPLE_ONLY'], $_SERVER['SAMPLE_ONLY'], $_ENV['BACKUP_ONLY'], $_SERVER['BACKUP_ONLY']);
    }
});
