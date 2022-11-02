<?php

declare( strict_types=1 );

use Rector\CodeQuality\Rector\Class_;
use Rector\CodeQuality\Rector\FuncCall;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function ( RectorConfig $rectorConfig ) : void {
    $rectorConfig->paths( [
        __DIR__,
        __DIR__ . DIRECTORY_SEPARATOR . 'classes',
        __DIR__ . DIRECTORY_SEPARATOR . 'dust',
        __DIR__ . DIRECTORY_SEPARATOR . 'helpers',
        __DIR__ . DIRECTORY_SEPARATOR . 'models',
    ] );

    $rectorConfig->skip([
        __DIR__ . DIRECTORY_SEPARATOR . 'vendor'
    ]);

    // register a single rule
    $rectorConfig->rule( Class_\InlineConstructorDefaultToPropertyRector::class );
    $rectorConfig->rule( FuncCall\BoolvalToTypeCastRector::class );
    $rectorConfig->rule( FuncCall\IntvalToTypeCastRector::class );
    $rectorConfig->rule( FuncCall\CallUserFuncWithArrowFunctionToInlineRector::class );

    // define sets of rules
    $rectorConfig->sets( [
        LevelSetList::UP_TO_PHP_74,
    ] );
};
