<?php

namespace CoderManjeet\SocialAutomation;

class SocialAutomation
{
    /**
     * Resolve a host application model class from the package config.
     */
    public static function model(string $key): string
    {
        return (string) config("content-automation.models.{$key}");
    }
}
