<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UgcTermsRequiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Review and accept the current published terms before posting.');
    }
}
