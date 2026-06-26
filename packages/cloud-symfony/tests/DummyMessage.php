<?php

namespace Laravel\Cloud\Tests;

final class DummyMessage
{
    public function __construct(
        public readonly string $text,
    ) {
    }
}
