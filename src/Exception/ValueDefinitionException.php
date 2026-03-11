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
 * Exception for invalid normalization rule definitions or invalid caller arguments.
 *
 * Used when the caller passes an invalid method contract such as:
 * - reversed min/max bounds
 * - invalid decimal scale
 * - invalid maxLen
 * - invalid enum allowed list
 *
 * Notes:
 * - This is a developer-side error, not a user input validation error.
 * - Higher layers should normally not convert this into field-level user feedback.
 */
final class ValueDefinitionException extends \LogicException {
}
