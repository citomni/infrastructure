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
 * Thrown when the Txt service encounters invalid or missing runtime configuration.
 *
 * This exception is reserved for true service misconfiguration, such as a missing
 * or malformed locale.language value. Operational content issues in language
 * files are intentionally not represented by this exception, because those cases
 * must preserve legacy fallback behavior.
 */
final class TxtConfigException extends \RuntimeException {}
