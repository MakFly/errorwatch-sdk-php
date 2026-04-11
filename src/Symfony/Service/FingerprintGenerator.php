<?php

namespace ErrorWatch\Symfony\Service;

final class FingerprintGenerator
{
    public function generate(string $message, string $file, int $line): string
    {
        return sha1($message.'|'.$file.'|'.$line);
    }
}
