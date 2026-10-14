<?php

namespace App\Enums;

enum ExtractionEngine: string
{
    case Groq = 'groq';
    case Claude = 'claude';
}
