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
 * BruteForceConfigException: Thrown when brute force configuration is missing or invalid.
 *
 * Covers: missing bruteforce config node, missing context key, invalid threshold
 * values (non-positive limits, zero interval, etc.).
 *
 * Notes:
 * - This is a configuration error, not a runtime input error.
 *   Fix by correcting the bruteforce node in CFG_HTTP / citomni_http_cfg.php.
 */
final class BruteForceConfigException extends BruteForceException {
}
