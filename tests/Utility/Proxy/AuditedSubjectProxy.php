<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

/**
 * The namespace is the point of this file: `resolveEntityClass()` splits on the `Proxies\__CG__\` marker, and declaring a proxy by hand is the only way to reach that branch without a proxy factory.
 */

namespace Proxies\__CG__\PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject as RealAuditedSubject;

/** @internal */
final class AuditedSubject extends RealAuditedSubject {}
