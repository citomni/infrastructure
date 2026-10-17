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
 * ValueDefinitionException: Exception for an invalid rule that code passes to valueToSql or valueFromSql.
 *
 * Marks an error in the calling code, such as reversed bounds, a scale out of range, a
 * negative maximum length, an invalid list of allowed values or an unsupported output format,
 * as opposed to invalid input from a user. It is not field feedback; let it reach the global
 * error handler.
 */
class ValueDefinitionException extends \LogicException {
}
