<?php

namespace App\Services\Pdts\Templates;

use RuntimeException;
use ZipArchive;

/**
 * Reads a Word file into memory and writes a fresh one.
 *
 * Templates are never edited in place: on Windows, antivirus or the indexer
 * can briefly lock a file that was just copied, and the zip library then
 * fails to save it ("Renaming temporary file failed"). Building a new file at
 * a path that does not exist yet avoids that, and also avoids reading back an
 * entry changed in the same session, which the zip library cannot do.
 */
class DocxPackage
{
    /**
     * @return array<string, string> part name => contents, in the file's order
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This file could not be read as a Word document.');
        }

        try {
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new RuntimeException('This file could not be read as a Word document.');
                }
                $files[$name] = $contents;
            }
        } finally {
            $zip->close();
        }

        if (! isset($files['word/document.xml'])) {
            throw new RuntimeException('This file could not be read as a Word document.');
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function write(array $files): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdts-docx-'.bin2hex(random_bytes(8));
        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare the Word file.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'document.docx';

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Could not prepare the Word file.');
            }

            // [Content_Types].xml first, as Word itself writes it.
            if (isset($files['[Content_Types].xml'])) {
                $files = ['[Content_Types].xml' => $files['[Content_Types].xml']] + $files;
            }
            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not prepare the Word file.');
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
