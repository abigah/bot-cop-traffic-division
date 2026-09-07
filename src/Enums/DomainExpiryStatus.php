<?php

namespace Abigah\BotCopTrafficDivision\Enums;

enum DomainExpiryStatus: string
{
    case NOT_YET_CHECKED = 'not yet checked';
    case VALID = 'valid';
    case INVALID = 'invalid';
    case EXPIRED = 'expired';
}
