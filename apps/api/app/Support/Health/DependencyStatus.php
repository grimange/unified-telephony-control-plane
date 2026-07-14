<?php

namespace App\Support\Health;

enum DependencyStatus: string
{
    case Ok = 'ok';
    case Unavailable = 'unavailable';
}
