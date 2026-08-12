<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageCode: string
{
    case EN = 'EN';
    case ID = 'ID';
    case ZH = 'ZH';

    public function label(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::ID => 'Indonesian',
            self::ZH => 'Mandarin',
        };
    }

    /**
     * Config key holding the minimum meaningful text for this language.
     *
     * The value itself is resolved through SettingsService so an administrator
     * can change it without a deploy.
     */
    public function thresholdSettingKey(): string
    {
        return match ($this) {
            self::EN => 'min_chars_en',
            self::ID => 'min_chars_id',
            self::ZH => 'min_chars_zh',
        };
    }

    /**
     * Whether coverage for this language is measured in characters rather
     * than words. Chinese has no whitespace word boundaries, so a word count
     * is meaningless for it (CLAUDE.md 8.6).
     */
    public function isCharacterCounted(): bool
    {
        return $this === self::ZH;
    }

    /**
     * The order translations are expected to appear in a compliant document.
     *
     * Not enforced in Phase 1 - the WRONG_LANGUAGE_ORDER check lands in
     * Phase 5 - but the canonical order lives here so there is one source
     * of truth when it does.
     *
     * @return array<int, self>
     */
    public static function requiredOrder(): array
    {
        return [self::EN, self::ID, self::ZH];
    }
}
