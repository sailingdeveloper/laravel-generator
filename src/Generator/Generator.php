<?php

namespace SailingDeveloper\LaravelGenerator\Generator;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;

/**
 * @author Thijs de Maa <maa.thijsde@gmail.com>
 *
 * @since 20230504
 */
abstract class Generator
{
    public function indent(int $number): string
    {
        return str_repeat(' ', $number * 4);
    }

    public function writeNamespaceToFile(string $fileName, PhpNamespace $namespace): void
    {
        @mkdir(app_path() . DIRECTORY_SEPARATOR . dirname($fileName), 0777, true);

        $printer = new PsrPrinter();
        file_put_contents(
            app_path() . DIRECTORY_SEPARATOR . $fileName,
            '<?php' . PHP_EOL . PHP_EOL . $printer->printNamespace($namespace),
        );
    }

    protected function simplifyType(PhpNamespace $namespace, string $type): string
    {
        if (str_contains($type, '\\')) {
            return $namespace->simplifyType($type) . '::class';
        } else {
            return "'{$type}'";
        }
    }

    protected function simplifyPhpDocType(PhpNamespace $namespace, string $type): string
    {
        if (str_contains($type, '\\')) {
            return $namespace->simplifyType($type);
        } else {
            return $type;
        }
    }

    protected function addClassHeader(ClassType $class): void
    {
        $class->addComment(sprintf('@author %s <%s>', exec('git config user.name'), exec('git config user.email')));
        $class->addComment('');
        $class->addComment(sprintf('@since %s', date('Ymd')));
    }

    protected function addClassHeaderGenerator(ClassType $class): void
    {
        $class->addComment('@author Generator');
        $class->addComment('');
        $class->addComment(sprintf('@since %s', date('Ymd')));
    }
}
