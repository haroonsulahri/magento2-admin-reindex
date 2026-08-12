<?php

declare(strict_types=1);

$target = $argv[1] ?? dirname(__DIR__);
$root = realpath($target);
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Release-check target does not exist.\n");
    exit(2);
}

$excludedDirectories = ['.git', '.idea', '.vscode', 'dist', 'vendor'];
$allowedEmailDomains = ['example.com', 'example.net', 'example.org'];
$contentPatterns = [
    'Windows absolute path' => '~(?<![A-Za-z0-9])[A-Za-z]:[\\\\/](?:Users|Documents|Projects|Sites)[\\\\/]~i',
    'Unix home path' => '~/(?:home|Users)/[A-Za-z0-9._-]+/~',
    'local-only URL' => '~https?://(?:localhost|[^/\s]+\.local)(?=[:/\s]|$)~i',
    'private key' => '~-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----~',
    'credential assignment' => '~(?:api[_-]?key|access[_-]?token|client[_-]?secret|password)\s*[=:>]\s*["\'][^"\'\s]{8,}["\']~i',
];

$errors = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($excludedDirectories): bool {
            return !$file->isDir() || !in_array($file->getFilename(), $excludedDirectories, true);
        }
    )
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if ($contents === false || str_contains($contents, "\0")) {
        continue;
    }

    $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    foreach ($contentPatterns as $label => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $errors[] = sprintf('%s: %s', $relativePath, $label);
        }
    }

    if (preg_match_all('~[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})~i', $contents, $emailMatches)) {
        foreach ($emailMatches[1] as $domain) {
            if (!in_array(strtolower($domain), $allowedEmailDomains, true)) {
                $errors[] = sprintf('%s: non-example email address', $relativePath);
            }
        }
    }

    if (strtolower($file->getExtension()) === 'xml') {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        if (!$document->loadXML($contents)) {
            $errors[] = sprintf('%s: invalid XML', $relativePath);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

$errors = array_values(array_unique($errors));
if ($errors !== []) {
    fwrite(STDERR, "Release check failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Release check passed: no private paths, non-example emails, common secrets or invalid XML found.\n");
