<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

final class MutationRuntime
{
    /** @return list<string> */
    public static function command(): array
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if ($ini === false) {
            $command[] = '-n';
        } else {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-d', 'extension_dir=' . ini_get('extension_dir'));
        if ($ini === false && extension_loaded('mbstring')) {
            array_push($command, '-d', 'extension=mbstring');
        }
        array_push($command, '-d', 'opcache.enable_cli=0');

        return $command;
    }
}
