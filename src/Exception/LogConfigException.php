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
 * LogConfigException: Exception for a log cfg value or setter argument that the log service rejects.
 *
 * Marks a configuration error, not a failure to write: Let it reach the global error
 * handler. Fixing the value belongs to the app's cfg files or to the calling code.
 */
class LogConfigException extends LogException {}
