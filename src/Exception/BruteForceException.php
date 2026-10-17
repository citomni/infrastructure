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
 * BruteForceException: Base exception for failures of the brute force protection.
 *
 * Lets a caller catch every failure of the protection's own cfg and storage setup in one
 * catch. Invalid method input stays \InvalidArgumentException, and a database failure stays
 * the exception of the Db service; neither is wrapped in this family.
 */
class BruteForceException extends \RuntimeException {
}
