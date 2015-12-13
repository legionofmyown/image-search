<?php
namespace Katran;

class GoogleImageSearchService
{
    const MAX_LOAD_IMAGES = 5;
    const MAX_RETRIES = 3;

    private $_tmpDir;
    private $_cookieFile;
    /** @var DbService */
    private $_db;

    public function __construct(DbService $db, $tmpDir)
    {
        $this->_db = $db;
        $this->_tmpDir = $tmpDir;
        $this->_cookieFile = $this->_tmpDir . '/cookie.txt';
    }

    public function __destruct()
    {
        @unlink($this->_cookieFile);
    }

    /**
     * @param string $url
     * @param bool $withHeaders
     * @param int $retries
     * @return string
     * @throws SearchException
     */
    private function curl($url, $postData = [], $withHeaders = false, $retries = 1)
    {
        $curl = curl_init();

        if ($withHeaders) {
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_VERBOSE, true);
        }

        if (count($postData) > 0) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->_cookieFile);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->_cookieFile);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'accept-encoding: deflate',
            'accept-language:en-US,en;q=0.8,fr-FR;q=0.6,fr;q=0.4,ru;q=0.2,ro;q=0.2,uk;q=0.2',
            'cache-control:no-cache',
            'pragma:no-cache',
            'upgrade-insecure-requests:1',
            'user-agent:Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.80 Safari/537.36',
        ]);

        $res = curl_exec($curl);

        if ($res === false) {
            if ($retries >= static::MAX_RETRIES) {
                //var_dump(curl_getinfo($curl));
                throw new SearchException('Error accessing Google');
            } else {
                $res = $this->curl($url, $postData, $withHeaders, $retries + 1);
            }
        }

        usleep(500000);

        return $res;
    }

    /**
     * @param string $url
     * @return array
     * @throws SearchException
     */
    private function search($url)
    {
        $res = $this->curl($url);
        preg_match('/<a href="([^"]+)">Visually similar images<\/a>/Usmi', $res, $m);
        if (!isset($m[1])) {
            throw new SearchException('Results page not found');
        }
        $murl = str_replace('&amp;', '&', trim($m[1]));
        $imageResultsUrl = 'https://www.google.com' . $murl;

        $res = $this->curl($imageResultsUrl);
        preg_match_all('/<a href="\/imgres\?imgurl=(.+)&amp;imgrefurl/Usmi', $res, $m);
        if (!isset($m[1])) {
            throw new SearchException('Images page not found');
        }

        return $m[1];
    }

    /**
     * @param array $files
     * @return array
     */
    private function fetchFiles(array $files)
    {
        $results = [];
        foreach ($files as $fileUrl) {
            $ext = $this->getExtensionFromFilename($fileUrl);
            if ($ext !== null) {
                $name = $this->_tmpDir . '/' . md5(microtime()) . '.' . $ext;
                $file = @file_get_contents($fileUrl);
                if ($file !== false) {
                    if (file_put_contents($name, $file) !== false) {
                        $results[] = [
                            'local' => $name,
                            'remote' => $fileUrl
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param string $url
     * @return null|string
     */
    private function getExtensionFromFilename($url)
    {
        preg_match('/\.([^\.]+)$/', $url, $m);
        if (!isset($m[1])) {
            return null;
        }
        $ext = strtolower($m[1]);

        switch ($ext) {
            case 'gif':
            case 'png':
            case 'jpg':
                return $ext;
            case 'jpeg':
                return 'jpg';
            default:
                return null;
        }
    }

    /**
     * @param string $filename
     * @return resource
     * @throws SearchException
     */
    private function loadGDImage($filename)
    {
        $ext = $this->getExtensionFromFilename($filename);
        switch ($ext) {
            case 'jpg':
                $image = imagecreatefromjpeg($filename);
                break;
            case 'gif':
                $image = imagecreatefromgif($filename);
                break;
            case 'png':
                $image = imagecreatefrompng($filename);
                break;
            default:
                throw new SearchException('Unknown file format');
        }

        if ($image === false) {
            throw new SearchException('Image not readable');
        }

        return $image;
    }

    /**
     * @param resource $searchImage
     * @param array $imageUrls
     * @param int $minW
     * @param int $minH
     * @return array
     */
    private function process($searchImage, array $imageUrls, $minW, $minH)
    {
        //get minimums from search file
        if ($minW === -1) {
            $minW = imagesx($searchImage);
        }
        if ($minH === -1) {
            $minH = imagesy($searchImage);
        }

        //download images
        $images = $this->fetchFiles($imageUrls);

        $results = [];
        foreach ($images as $k => $image) {
            $imageFile = $image['local'];
            $size = @getimagesize($imageFile);

            if ($size !== false) {
                $w = $size[0];
                $h = $size[1];

                if ($w >= $minW && $h >= $minH) {

                    //check if file is the same
                    $flag = true;
                    try {
                        $remoteImage = $this->loadGDImage($image['remote']);
                        list($difference, $similarity) = $this->compareImages($searchImage, $remoteImage);
                        if ($difference > 10) {
                            $flag = false;
                        }
                        if ($similarity > 95) {
                            $flag = false;
                        }
                    } catch (SearchException $e) {
                        $flag = false;
                    }

                    if ($flag) {
                        $results[] = [
                            'url' => $image['remote'],
                            'width' => $w,
                            'height' => $h,
//                            'difference' => $difference . '%',
//                            'similarity' => $similarity . '%'
                        ];
                    }
                }
            }

            @unlink($imageFile);
        }

        return $results;
    }

    /**
     * @param resource $image1
     * @param resource $image2
     * @return array
     */
    private function compareImages($image1, $image2)
    {
        $difference = $this->getImageDifference($image1, $image2);
        $similarity = 100 - $this->getImageDifference($image2, $image1);

        return [$difference, $similarity];
    }

    /**
     * @param resource $image1
     * @param resource $image2
     * @return int
     */
    private function getImageDifference($image1, $image2)
    {
        $w = imagesx($image1);
        $h = imagesy($image1);

        $copy = imagecreatetruecolor($w, $h);
        imagecopy($copy, $image1, 0, 0, 0, 0, $w, $h);

        $resized = imagecreatetruecolor($w, $h);
        imagecopyresampled($resized, $image2, 0, 0, 0, 0, $w, $h, imagesx($image2), imagesy($image2));

        $totalDiff = 0;
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgb = imagecolorat($copy, $x, $y);
                $r1 = ($rgb >> 16) & 0xFF;
                $g1 = ($rgb >> 8) & 0xFF;
                $b1 = $rgb & 0xFF;

                $rgb = imagecolorat($resized, $x, $y);
                $r2 = ($rgb >> 16) & 0xFF;
                $g2 = ($rgb >> 8) & 0xFF;
                $b2 = $rgb & 0xFF;

                $r = abs($r1 - $r2);
                $g = abs($g1 - $g2);
                $b = abs($b1 - $b2);

                $pixelDiff = ($r + $g + $b) / 2.55;
                if ($pixelDiff > 15) {
                    $totalDiff++;
                }
            }
        }

        $difference = round(100 * $totalDiff / $w / $h);

        return $difference;
    }


    public function find($sync, $filename = null, $token = null, $minW = -1, $minH = -1)
    {
        //check cache
        $cacheKey = $token === null ? json_encode(md5($filename)) : $token;
        $cached = $this->_db->get($cacheKey);

        if ($cached !== null) {
            return json_decode($cached);
        }

        if($sync === false || $filename === false) {
            return ['token' => $cacheKey];
        }

        $image = $this->loadGDImage($filename);

        //perform web search
        $parts = explode('://', $filename);
        $isFile = (count($parts) === 1 || $parts[0] === 'file');
        if ($isFile) {
            $post = [
                'image_url' => '',
                'encoded_image' => new \CURLFile(str_replace('file://', '', $filename)),
                'image_content' => '',
                'filename' => '',
                'hl' => 'en'
            ];

            $res = $this->curl('https://images.google.com/searchbyimage/upload', $post, true);
            preg_match('/^Location: (.+)\n/Usmi', $res, $m);
            $resultsUrl = trim($m[1]);
        } else {
            $res = $this->curl('https://images.google.com/searchbyimage?image_url=' . urlencode($filename) . '&encoded_image=&image_content=&filename=&hl=en', [], true);
            preg_match('/^Location: (.+)\n/Usmi', $res, $m);
            $resultsUrl = trim($m[1]);
        }

        $imageUrls = array_slice($this->search($resultsUrl), 0, static::MAX_LOAD_IMAGES);

        //check found files
        $result = $this->process($image, $imageUrls, $minW, $minH);

        $this->_db->set($cacheKey, json_encode($result));

        return $result;
    }

}
