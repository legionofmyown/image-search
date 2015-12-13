<?php
namespace Katran;

class AsyncProcessorService
{
    /** @var GoogleImageSearchService */
    private $_searcher;
    private $_tmpDir;

    public function __construct(GoogleImageSearchService $searcher, $tmpDir)
    {
        $this->_searcher = $searcher;
        $this->_tmpDir = $tmpDir;
    }

    public function process()
    {
        $dir = dir($this->_tmpDir);
        if (!$dir) {
            return;
        }

        while (false !== ($entry = $dir->read())) {
            if (preg_match('/^([\-0-9]+)_([\-0-9]+)_(.+)\.([^\.]+)$/', $entry, $parts)) {
                $minX = $parts[1];
                $minY = $parts[2];
                $name = $parts[3];
                $ext = $parts[4];
                $file = $this->_tmpDir . '/' . $entry;

                switch ($ext) {
                    case 'gif':
                    case 'jpg':
                    case 'png':
                        $this->_searcher->find(true, $entry, $name, $minX, $minY);
                        @unlink($file);
                        break;
                    case 'url':
                        $url = trim(file_get_contents($file));
                        $this->_searcher->find(true, $url, $name, $minX, $minY);
                        @unlink($file);
                        break;
                }
            }
        }

        $dir->close();
    }
}