<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('var')
    ->in(__DIR__)
;

return PhpCsFixer\Config::create()
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        'concat_space' => [ 'spacing' => 'one' ],
    ])
    ->setFinder($finder)
;
