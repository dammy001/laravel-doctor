<?php

namespace Bunce\LaravelDoctor\Domain;

enum IssueSeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}
