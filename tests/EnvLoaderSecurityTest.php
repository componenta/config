<?php

declare(strict_types=1);

use Componenta\Config\Exception\EnvLoaderException;
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

it('never includes dotenv values in parse diagnostics', function (): void {
    $root = sys_get_temp_dir() . '/componenta_env_secret_' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    $secret = 'super-secret-value';
    file_put_contents($root . '/.env', "INVALID-NAME={$secret}\n");

    try {
        try {
            (new EnvLoader($root))->read();
            throw new RuntimeException('Expected dotenv parse failure.');
        } catch (EnvLoaderException $e) {
            expect($e->getMessage())->toContain('invalid variable name')
                ->not->toContain($secret);
        }
    } finally {
        @unlink($root . '/.env');
        @rmdir($root);
    }
});
