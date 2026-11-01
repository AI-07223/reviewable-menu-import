<?php

// Optional live call: sends the supplied image to configured providers and may incur charges.
require dirname(__DIR__).'/tests/bootstrap.php';
use App\Services\MenuScanner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;

$path = $argv[1] ?? '';
if (! is_file($path)) {
    fwrite(STDERR, "Usage: php bin/scan.php image.png\n");
    exit(2);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    fwrite(STDERR, "Use PNG, JPEG or WebP.\n");
    exit(2);
}
if (filesize($path) > 10 * 1024 * 1024) {
    fwrite(STDERR, "Maximum size: 10 MiB.\n");
    exit(2);
}
$c = new Container;
Container::setInstance($c);
$c->instance('config', new Repository(['app' => ['url' => 'http://localhost'], 'services' => [
    'openrouter' => ['key' => getenv('OPENROUTER_API_KEY') ?: null, 'base_url' => 'https://openrouter.ai/api/v1', 'vision_model' => getenv('OPENROUTER_VISION_MODEL') ?: 'qwen/qwen3-vl-32b-instruct'],
    'mistral' => ['key' => getenv('MISTRAL_API_KEY') ?: null, 'vision_model' => getenv('MISTRAL_VISION_MODEL') ?: 'pixtral-12b-2409'],
]]));
$c->instance(Factory::class, new Factory);
$c->instance('log', new NullLogger);
Facade::setFacadeApplication($c);
try {
    $result = (new MenuScanner)->scan(['data:'.$mime.';base64,'.base64_encode(file_get_contents($path))]);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
