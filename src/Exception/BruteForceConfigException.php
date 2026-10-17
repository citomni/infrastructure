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
 * BruteForceConfigException: Exception for missing or invalid security.bruteforce cfg.
 *
 * Marks a configuration error in the app, such as a context that is not configured or a
 * limit below 1, not invalid input: Let it reach the global error handler. Fixing the value
 * belongs to the app's cfg files.
 */
class BruteForceConfigException extends BruteForceException {
}
