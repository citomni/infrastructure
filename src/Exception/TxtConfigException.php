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
 * TxtConfigException: Exception for missing or invalid cfg of the txt service.
 *
 * Marks a configuration error in the app, such as a missing or malformed locale.language,
 * not a content problem in a language file: Let it reach the global error handler. Fixing
 * the value belongs to the app's cfg files.
 *
 * @see \CitOmni\Infrastructure\Service\Txt::get()  How content problems are answered instead.
 */
class TxtConfigException extends \RuntimeException {}
