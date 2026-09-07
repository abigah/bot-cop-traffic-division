<?php

namespace Abigah\BotCopTrafficDivision\Exceptions;

use RuntimeException;

/**
 * A Forge failure whose message is meant for the user — most often a token
 * without the scopes an endpoint requires. The Forge panel renders the message
 * as-is, so write it as guidance rather than as a stack-trace line.
 */
class ForgeAccessException extends RuntimeException {}
