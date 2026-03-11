<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Infrastructure\Exception;

/**
 * BruteForceException: Base exception for brute force protection failures.
 *
 * Allows callers to catch all brute-force-specific exceptions with a single
 * catch block when appropriate. Concrete subclasses provide specificity.
 *
 * Notes:
 * - Input validation errors (empty context, invalid IP, no subjects) use
 *   \InvalidArgumentException directly and do NOT extend this class.
 * - Db failures propagate as DbQueryException / DbConnectException and are
 *   not wrapped here.
 */
class BruteForceException extends \RuntimeException {
}
