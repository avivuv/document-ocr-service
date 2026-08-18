<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class OcrTokenCommand extends Command
{
    protected $signature = 'ocr:token {name=app-intranet : nama consumer}';

    protected $description = 'Bangkitkan token API baru untuk ditempel ke OCR_API_TOKENS';

    public function handle(): int
    {
        $name  = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->argument('name')) ?: 'consumer';
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->info('Tambahkan baris berikut ke .env (pisahkan beberapa token dengan koma):');
        $this->newLine();
        $this->line('OCR_API_TOKENS='.$name.':'.$token);
        $this->newLine();
        $this->comment('Token tidak disimpan di mana pun oleh service — salin sekarang.');

        return self::SUCCESS;
    }
}
