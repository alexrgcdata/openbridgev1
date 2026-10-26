<?php

declare(strict_types=1);

/** Stable direct include for the FileMaker Data API restCWP class. */
$classPath = is_file(__DIR__ . '/api/restCWP.php')
    ? __DIR__ . '/api/restCWP.php'
    : dirname(__DIR__) . '/restCWP/restCWP.php';
require_once $classPath;
