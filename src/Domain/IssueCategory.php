<?php

namespace Bunce\LaravelDoctor\Domain;

enum IssueCategory: string
{
    case SECURITY = 'security';
    case PERFORMANCE = 'performance';
    case CORRECTNESS = 'correctness';
    case ARCHITECTURE = 'architecture';
}
