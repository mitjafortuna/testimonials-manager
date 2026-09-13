<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/database', __DIR__ . '/public']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
