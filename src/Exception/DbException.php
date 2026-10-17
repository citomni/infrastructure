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
 * DbException: Base exception for failures of the db service.
 *
 * Lets a caller catch every database failure in one catch when it does not need to tell a
 * connection that cannot be used from a statement that fails.
 */
class DbException extends \RuntimeException {

}
