<?php

declare(strict_types=1);

namespace App\Enums;

enum Phase: string
{
    case Concept = 'concept';
    case Implement = 'implement';
    case Push = 'push';
    case Respond = 'respond';
    case Diff = 'diff';
    case CommitMessage = 'commit-message';

    public function label(): string
    {
        return match ($this) {
            self::Concept => __('enums.phases.concept'),
            self::Implement => __('enums.phases.implement'),
            self::Push => __('enums.phases.push'),
            self::Respond => __('enums.phases.respond'),
            self::Diff => __('enums.phases.diff'),
            self::CommitMessage => __('enums.phases.commit_message'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Concept => 'info',
            self::Implement => 'warning',
            self::Push => 'primary',
            self::Respond => 'success',
            self::Diff, self::CommitMessage => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Concept => 'heroicon-m-light-bulb',
            self::Implement => 'heroicon-m-code-bracket',
            self::Push => 'heroicon-m-arrow-up-tray',
            self::Respond => 'heroicon-m-chat-bubble-left-right',
            self::Diff => 'heroicon-m-document-text',
            self::CommitMessage => 'heroicon-m-chat-bubble-bottom-center-text',
        };
    }
}
