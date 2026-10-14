<?php

namespace App\Enums;

/** Lifecycle shared by a remittance and its extraction runs. Values match the cache-era strings. */
enum ExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
