<?php
namespace App\Services\Comm\Contracts;

interface ChannelSender {
    public function send(array $message): array;
}
