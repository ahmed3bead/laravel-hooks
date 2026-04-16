<?php

namespace Ahmed3bead\LaravelHooks\Contracts;

interface WrappedResponseInterface
{
    public function getData(): mixed;

    public function getStatusCode(): int;

    public function getMessage(): string;
}
