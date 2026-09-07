<?php

namespace Abigah\BotCopTrafficDivision\Enums;

enum CertificateStatus: string
{
    case NOT_YET_CHECKED = 'not yet checked';
    case VALID = 'valid';
    case INVALID = 'invalid';
}
