<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Reader;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Reader\PhpFileReader;
use LogicException;

it('includes PHP configuration in an isolated static scope', function (): void {
    $file = sys_get_temp_dir()
        . '/componenta_config_reader_'
        . bin2hex(random_bytes(6))
        . '.php';

    file_put_contents(
        $file,
        <<<'PHP'
<?php

return [
    'this' => isset($this),
    'reader_file_local' => isset($file),
    'reader_path_local' => isset($path),
    'reader_configuration_file_local' => isset($configurationFile),
];
PHP,
    );

    try {
        expect((new PhpFileReader())->readFile($file))->toBe([
            'this' => false,
            'reader_file_local' => false,
            'reader_path_local' => false,
            'reader_configuration_file_local' => false,
        ]);
    } finally {
        unlink($file);
    }
});

it('wraps a PHP source failure and preserves it as previous', function (): void {
    $file = sys_get_temp_dir()
        . '/componenta_config_reader_failure_'
        . bin2hex(random_bytes(6))
        . '.php';
    file_put_contents($file, '<?php throw new \\LogicException("source failure");');

    try {
        (new PhpFileReader())->readFile($file);
        throw new \RuntimeException('The reader should wrap the source exception.');
    } catch (ConfigException $exception) {
        expect($exception->getPrevious())
            ->toBeInstanceOf(LogicException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('source failure');
    } finally {
        unlink($file);
    }
});
