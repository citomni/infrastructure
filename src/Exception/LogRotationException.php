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
 * LogRotationException: Exception for a full log file that cannot be rotated.
 *
 * Marks a failure to move the active file aside. The entry that triggered the rotation may
 * already have been written.
 */
class LogRotationException extends LogFileException {}
