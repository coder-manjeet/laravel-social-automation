<?php

namespace CoderManjeet\SocialAutomation\Enums;

enum PostSource: string
{
    case Manual = 'manual';
    case Claude = 'claude';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
