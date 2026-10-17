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
 * MailerException: Base exception for failures of the mailer service.
 *
 * Lets a caller catch every mailer failure in one catch without catching unrelated runtime
 * failures. Thrown as is for a message that the mail library cannot build from valid
 * arguments, which is neither a configuration error nor a refused send.
 *
 * Rules:
 * - Never use it, or a subclass, for invalid method input; that is \InvalidArgumentException.
 */
class MailerException extends \RuntimeException {
}
