<?php

namespace App\Service;

class InteractionQueue
{
    private array $items = [];

    public function push(array $payload): void
    {
        $this->items[] = $payload;
    }

    public function flush(): array
    {
        $items = $this->items;
        $this->items = [];

        return $items;
    }
}
