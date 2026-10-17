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
 * CurlConfigException: Exception for a request or service option that the curl service rejects.
 *
 * Marks a failure before any transfer starts, such as an unknown or missing request key, a
 * value of the wrong type, contradictory options or a cookie path that cannot be prepared.
 * Correcting the request belongs to the caller.
 */
class CurlConfigException extends CurlException {
}
