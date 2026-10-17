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
 * LogWriteException: Exception for a log entry that is not written completely.
 *
 * Marks a write that failed or wrote fewer bytes than the entry has, so the log file may end
 * with a partial line.
 */
class LogWriteException extends LogFileException {}
