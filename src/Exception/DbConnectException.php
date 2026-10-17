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
 * DbConnectException: Exception for a database connection that cannot be set up.
 *
 * Marks a failure before any statement of the caller runs: invalid db cfg, a missing password
 * secret, or a connect, charset or session setting that the server refuses. A caller that
 * reports an outage or retries later can tell it from a statement that fails.
 */
class DbConnectException extends DbException {

}
