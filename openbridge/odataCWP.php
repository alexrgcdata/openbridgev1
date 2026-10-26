<?php

declare(strict_types=1);

/** Stable direct include for the FileMaker OData v4 odataCWP class. */
$classPath = is_file(__DIR__ . '/api/odataCWP.php')
    ? __DIR__ . '/api/odataCWP.php'
    : dirname(__DIR__) . '/restCWP/odataCWP.php';
require_once $classPath;
