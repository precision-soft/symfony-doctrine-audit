<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Exception;

use RuntimeException;

/** The test harness's own failure: the fixtures are not the library, so they do not throw the library's exception. */
class FixtureException extends RuntimeException {}
