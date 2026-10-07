<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\RepoPath;

/**
 * The V7 `language`: an open set of highlighting language names, chosen by file name, case-insensitively. V9 uses it to
 * pick a {@see SymbolExtractor}.
 */
final class FileLanguage
{
    private const array BY_FILE_NAME = [
        'dockerfile' => 'docker',
        'makefile' => 'make',
    ];

    private const array BY_EXTENSION = [
        'php' => 'php',
        'twig' => 'twig',
        'js' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'jsx' => 'jsx',
        'ts' => 'typescript',
        'mts' => 'typescript',
        'cts' => 'typescript',
        'tsx' => 'tsx',
        'json' => 'json',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'xml' => 'xml',
        'html' => 'html',
        'htm' => 'html',
        'css' => 'css',
        'scss' => 'scss',
        'sql' => 'sql',
        'sh' => 'shellscript',
        'bash' => 'shellscript',
        'py' => 'python',
        'rb' => 'ruby',
        'go' => 'go',
        'rs' => 'rust',
        'java' => 'java',
        'kt' => 'kotlin',
        'c' => 'c',
        'h' => 'c',
        'cpp' => 'cpp',
        'hpp' => 'cpp',
        'cs' => 'csharp',
        'toml' => 'toml',
        'ini' => 'ini',
        'vue' => 'vue',
        'graphql' => 'graphql',
        'diff' => 'diff',
    ];

    public static function of(RepoPath $path): ?string
    {
        $fileName = strtolower(basename($path->toString()));
        $dot = strrpos($fileName, '.');

        return self::BY_FILE_NAME[$fileName] ?? (false === $dot ? null : self::BY_EXTENSION[substr($fileName, $dot + 1)] ?? null);
    }
}
