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
 * LogFileException: Exception for a log file that cannot be used.
 *
 * Marks a failure of one log file, such as an invalid file name or a lock or log file that
 * cannot be opened or locked. Its subclasses narrow it to a failed write and a failed
 * rotation.
 */
class LogFileException extends LogException {}
