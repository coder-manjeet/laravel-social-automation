<?php

namespace CoderManjeet\SocialAutomation\Enums;

enum SocialPostStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
