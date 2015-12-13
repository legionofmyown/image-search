<?php
namespace Katran;

class RestService
{
    /** @var GoogleImageSearchService */
    private $_searcher;
    private $_tmpDir;

    public function __construct(GoogleImageSearchService $searcher, $tmpDir)
    {
        $this->_searcher = $searcher;
        $this->_tmpDir = $tmpDir;
    }

    private function guessExtension($file)
    {
        $filename = $this->_tmpDir . '/guess_' . md5(microtime());
        file_put_contents($filename, $file);
        if (@imagecreatefromjpeg($filename) !== false) {
            @unlink($filename);
            return 'jpg';
        }
        if (@imagecreatefrompng($filename) !== false) {
            @unlink($filename);
            return 'png';
        }
        if (@imagecreatefromgif($filename) !== false) {
            @unlink($filename);
            return 'gif';
        }

        throw new RestException('Unknown image format');
    }

    public function handle($asynctoken, $url, $file, $mode, $minWidth, $minHeight)
    {
        try {
            $token = md5(microtime());
            if ($file) {
                $ext = $this->guessExtension($file);
                $filename = $this->_tmpDir . '/' . $minWidth . '_' . $minHeight . '_' . $token . '.' . $ext;
                file_put_contents($filename, $file);
                $request = 'file://' . $filename;
            } elseif($url) {
                $request = $url;
                if($mode === false) {
                    $filename = $this->_tmpDir . '/' . $minWidth . '_' . $minHeight . '_' . $token . '.url';
                    file_put_contents($filename, $url);
                }
            } else {
                $request = null;
            }
            if($mode === false && !$asynctoken) {
                $asynctoken = $token;
            }
            $res = $this->_searcher->find($mode, $request, $asynctoken, $minWidth, $minHeight);
        } catch (\Exception $e) {
            $res = ['error' => $e->getMessage()];
        }

        header('Content-Type: application/json');
        echo(json_encode($res));
    }
}