<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use RuntimeException;

/**
 * Raised only when the server cannot be reached. Nothing else may become a skip: a malformed DSN or a missing driver is a broken test setup and has to fail loudly.
 *
 * @internal
 */
final class SkipIntegrationException extends RuntimeException {}
