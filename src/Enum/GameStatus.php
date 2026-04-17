<?php

namespace App\Enum;

enum GameStatus: string
{
    case Unplayed  = 'unplayed';
    case Playing   = 'playing';
    case Completed = 'completed';
    case Dropped   = 'dropped';
    case OnHold    = 'on_hold';

    public function label(): string
    {
        return match($this) {
            self::Unplayed  => 'Unplayed',
            self::Playing   => 'Playing',
            self::Completed => 'Completed',
            self::Dropped   => 'Dropped',
            self::OnHold    => 'On Hold',
        };
    }
}
