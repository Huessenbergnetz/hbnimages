<?php
// SPDX-FileCopyrightText: 2024-2025 Matthias Fehring <https://www.huessenbergnetz.de>
//
// SPDX-License-Identifier: LGPL-3.0-or-later

declare(strict_types=1);

namespace HBN\Images;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Image\Image;
use Joomla\CMS\Log\Log;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

class HbnImages
{
    public const ORIENTATION_LANDSCAPE = 0;
    public const ORIENTATION_PORTRAIT = 1;
    public const ORIENTATION_SQUARE = 2;

    private $options = null;

    private $http = null;

    private $imaginary_url = null;

    private $imagick = null;

    /**
     * Constructs a new %HbnImages object with the given @a $options.
     *
     * @par Currently supported options:
     * @code{.php}
     * $options = [
     *     // relative cache directory
     *     "cacheDir" => "images/hbnimages",
     *     // image converter to use, currentyl supported: joomla, imagick and imginary
     *     "converter" => "joomla",
     *     // should metadata be stripped from the resized images? No if 0, otherwise yes.
     *     "stripmetadata" => 0,
     *     // host of the imaginary server
     *     "imaginary_host" => null,
     *     // port the imaginary server listens on
     *     "imaginary_port" => null,
     *     // path of the imaginary server api
     *     "imaginary_path" => null
     * ];
     * @endcode
     */
    function __construct(array $options = array()) {

        $defOptions = [
            'cacheDir' => 'images/hbnimages',
            'converter' => 'joomla',
            'stripmetadata' => 0
        ];

        $this->options = array_merge($defOptions, $options);
    }

    /**
     * @brief Destructs the %HbnImages object and frees resources.
     */
    function __destruct() {
        if ($this->imagick !== null) {
            $this->imagick->clear();
        }
    }

    /**
     * @brief Resizes the image at the @a $src URL to and returns the path to the created cache file on success.
     *
     * On success, the path to the resized image file relative to JPATH_ROOT will be returned. If something failed,
     * an empty string will be returned.
     *
     * @note Only if the image is resized and not loaded from cache of already resized images, the width and height of
     * the resized image will be written to @a $&width and @a $& height. Make sure to check on your consuming function,
     * that both values are greater then @c 0, if you need actual resized image dimension.
     *
     * @param $src[in]  Joomla\CMS\Uri\Uri  Url for the source image to resize.
     * @param $&width[in,out]   int         Target width for the image.
     * @param $&height[in,out]  int         Target height for the image.
     * @param $type             string      Target image type. Supported types are 'webp', 'avif', 'jpeg' and 'png'.
     * @param $quality          int         Target image quality.
     */
    public function resizeImage(Uri $src, int &$width, int &$height = 0, string $type = 'webp', int $quality = 80) : string {
        $origFilePath = JPATH_ROOT . '/' . urldecode($src->getPath());
        if (!file_exists($origFilePath)) {
            return '';
        }

        $cacheFile = $this->getCacheFileName($src, $width, $height, $type);
        if (empty($cacheFile)) {
            return '';
        }

        if (!$this->createCacheDir(urldecode($cacheFile))) {
            return '';
        }

        $cacheFilePath = JPATH_ROOT . '/' . urldecode($cacheFile);

        if (file_exists($cacheFilePath)) {
            $origMTime = filemtime($origFilePath);
            $cacheMTime = filemtime($cacheFilePath);
            $this->log("Get Resized Image: Found cache file at {$cacheFilePath}");
            if ($cacheMTime >= $origMTime) {
                $this->log("Get Resized Image: Cache file is newer ({$cacheMTime} >= {$origMTime})");
                // TODO: faster solution to get image dimensions
                // try {
                //     $img = new Image($cacheFilePath);
                //     $width = $img->getWidth();
                //     $height = $img->getHeight();
                // } catch (\Exception $ex) {
                //     $this->log("JImage: Failed to load cached image {$cacheFilePath} to get sizes: {$ex->getMessage()}", Log::WARNING);
                // }
                return $cacheFile;
            }
        }

        $converter = $this->options['converter'] ?? 'joomla';

        if ($converter === 'imaginary') {
            $srcUrl = Uri::root() . $src->getPath();
            if (!$this->resizeImageWithImaginary($cacheFilePath, $srcUrl, $width, $height, $type, $quality)) {
                if (!$this->resizeImageWithImagick($cacheFilePath, $origFilePath, $width, $type, $height, $quality)) {
                    if (!$this->resizeImageWithJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                        return '';
                    }
                }
            }
        } else if ($converter === 'imagick') {
            if (!$this->resizeImageWithImagick($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                if (!$this->resizeImageWithJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                    return '';
                }
            }
        } else {
            if (!$this->resizeImageWithJoomla($cacheFilePath, $origFilePath, $width, $height, $type, $quality)) {
                return '';
            }
        }

        return $cacheFile;
    }

    /**
     * @brief Resizes an image with Imaginary and returns @c true on success, otherwise @c false.
     *
     * This will resize the image at @a $srcUrl to the given @a $width and @a $size and writes it to
     * @a $caceFilePath. If @a $width &gt; @c 0, the image will be resized to @a $width preserving aspect ratio. If
     * @a $height &gt; @c 0, the image will be resized to @a $height preservinge aspect ratio. On success, the
     * target width and height will be written back to @a $width and @a $height.
     *
     * @param $cacheFilePath[in]    string Absolute path for the resized cache file to create.
     * @param $origFilePath[in]     string Absolute path to the image file to resize.
     * @param $&widht[in,out]       int    Target width for the image.
     * @param $&height[in,out]      int    Target height for the image.
     * @param $type[in]             string Target type. Supported types are 'webp', 'avif', 'jpeg' and 'png'.
     * @param $quality[in]          int    Target quality.
     */
    public function resizeImageWithImaginary(string $cacheFilePath, string $srcUrl, int &$width, int &$height, string $type = 'webp', int $quality = 80) : bool {
        if ($this->imaginary_url === null) {
            $host = $this->options['imaginary_host'] ?? 'http://localhost';
            $port = $this->options['imaginary_port'] ?? 9000;
            $path = $this->options['imaginary_path'] ?? '';
            $this->imaginary_url = $host . ':' . $port . $path . '/resize';
        }

        $uri = new Uri($this->imaginary_url);
        $query = array(
            'type' => $type,
            'url' => $srcUrl,
            'stripmeta' => ($this->options['stripmetadata'] === 0 ? 'false' : 'true')
        );
        if ($type === 'png') {
            $query['compression'] = 9;
        }  else {
            $query['quality'] = $quality;
        }
        if ($width > 0) {
            $query['width'] = $width;
        }
        if ($height > 0) {
            $query['height'] = $height;
        }
        $uri->setQuery($query);

        $this->log("Imaginary: Trying to get resized image: {$uri->toString()}");

        if ($this->http === null) {
            try {
                $this->http = HttpFactory::getHttp();
            } catch (Exception $ex) {
                $this->log("Imaginary: Failed to get Joomla HTTP instance: {$ex->getMessage()}", Log::ERROR);
                return false;
            }
        }

        try {
            $response = $this->http->get($uri);
        } catch (\Exception $ex) {
            $this->log("Imaginary: Failed to get response: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        if ($response->code !== 200) {
            $errorMsg = json_decode($response->body);
            if ($errorMsg !== null) {
                $this->log("Imaginary: {$errorMsg}", Log::ERROR);
            } else {
                $this->log("Imaginary: Failed to get resized image", Log::ERROR);
            }
            return false;
        }

        try {
            File::write($cacheFilePath, $response->body);
        } catch (\Exception $ex) {
            $this->log("Imaginary: Failed to write cache file {$cacheFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        $width = intval($response->headers['Image-Width']);
        $height = intval($response->headers['Image-Height']);

        return true;
    }

    /**
     * @brief Resizes an image with Image Magick and returns @c true on success, otherwise @c false.
     *
     * This will resize the image at @a $origFilePath to the given @a $width and @a $size and writes it to
     * @a $caceFilePath. If @a $width &gt; @c 0, the image will be resized to @a $width preserving aspect ratio. If
     * @a $height &gt; @c 0, the image will be resized to @a $height preservinge aspect ratio. On success, the
     * target width and height will be written back to @a $width and @a $height.
     *
     * @param $cacheFilePath[in]    string Absolute path for the resized cache file to create.
     * @param $origFilePath[in]     string Absolute path to the image file to resize.
     * @param $&widht[in,out]       int    Target width for the image.
     * @param $&height[in,out]      int    Target height for the image.
     * @param $type[in]             string Target type. Supported types are 'webp', 'avif', 'jpeg' and 'png'.
     * @param $quality[in]          int    Target quality.
     */
    public function resizeImageWithImagick(string $cacheFilePath, string $origFilePath, int &$width, int &$height, string $type = 'webp', int $quality = 80) : bool {
        $this->log("Imagick: Trying to get resized image: {$origFilePath}");

        if (!extension_loaded('imagick')) {
            $this->log('Imagick: extension not loaded', Log::WARNING);
            return false;
        }

        if ($this->imagick === null) {
            try {
                $this->imagick = new \Imagick();
            } catch (\Exception $ex) {
                $this->log("Imagick: Failed to get new object: {$ex->getMessage()}", Log::ERROR);
                return false;
            }
        }

        $this->imagick->clear();

        if (!$this->imagick->readImage($origFilePath)) {
            $this->log("Imagick: Failed to read file {$origFilePath}", Log::ERROR);
            return false;
        }

        switch ($this->imagick->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPLEFT:
                break;
            case \Imagick::ORIENTATION_TOPRIGHT:
                $this->imagick->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $this->imagick->rotateImage("#000", 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $this->imagick->flopImage();
                $this->imagick->rotateImage("#000", 180);
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $this->imagick->flopImage();
                $this->imagick->rotateImage("#000", -90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $this->imagick->rotateImage("#000", 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $this->imagick->flopImage();
                $this->imagick->rotateImage("#000", 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $this->imagick->rotateImage("#000", -90);
                break;
            default: // Invalid orientation
                break;
        }
        $this->imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

        if (!$this->imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1)) {
            $this->log("Imagick: Failed to resize image {$origFilePath}", Log::ERROR);
            return false;
        }

        if ($this->options['stripmetadata'] !== 0) {
            if (!$this->imagick->stripImage()) {
                $this->log("Imagick: Failed to strip metadata from {$origFilePath}", Log::WARNING);
            }
        }

        if ($type === 'png') {
            $this->imagick->setOption('png:compression-level', '9');
        } else {
            if (!$this->imagick->setImageCompressionQuality($quality)) {
                $this->log("Imagick: Failed to set compression quality to {$quality} for {$origFilePath}", Log::ERROR);
                return false;
            }
        }

        if (!$this->imagick->writeImage($cacheFilePath)) {
            $this->log("Imagick: Failed to write cache file {$cacheFilePath}", Log::ERROR);
            return false;
        }

        $width = $this->imagick->getImageWidth();
        $height = $this->imagick->getImageHeight();

        return true;
    }

    /**
     * @brief Resizes an image with the Joomla! Image class and returns @c true on success, otherwise @c false.
     *
     * This will resize the image at @a $origFilePath to the given @a $width and @a $size and writes it to
     * @a $caceFilePath. If @a $width &gt; @c 0, the image will be resized to @a $width preserving aspect ratio. If
     * @a $height &gt; @c 0, the image will be resized to @a $height preservinge aspect ratio. On success, the
     * target width and height will be written back to @a $width and @a $height.
     *
     * @param $cacheFilePath[in]    string Absolute path for the resized cache file to create.
     * @param $origFilePath[in]     string Absolute path to the image file to resize.
     * @param $&widht[in,out]       int    Target width for the image.
     * @param $&height[in,out]      int    Target height for the image.
     * @param $type[in]             string Target type. Supported types are 'webp', 'avif', 'jpeg' and 'png'.
     * @param $quality[in]          int    Target quality.
     */
    public function resizeImageWithJoomla(string $cacheFilePath, string $origFilePath, int &$width, int &$height, string $type = 'webp', int $quality = 80) : bool {
        $this->log("JImage: Trying to get resized image: {$origFilePath}");

        try {
            $img = new Image($origFilePath);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to load image {$origFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        $origWidth = $img->getWidth();
        $origHeight = $img->getHeight();

        $targetWidth = 0;
        $targetHeight = 0;

        if ($width > 0) {
            $targetWidth = $width;
            $ratio = $width / $origWidth;
            $targetHeight = (int)round($origHeight * $ratio);
        } else if ($height > 0) {
            $targetHeight = $height;
            $ratio = $height / $origHeight;
            $targetWidth = (int)round($origWidth * $ratio);
        }

        try {
            $img = $img->resize($targetWidth, $targetHeight);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to resize image {$origFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        $imgType = IMAGETYPE_WEBP;

        switch ($type) {
            case 'webp':
                $imgType = IMAGETYPE_WEBP;
                break;
            case 'avif':
                $imgType = IMAGETYPE_AVIF;
                break;
            case 'jpeg':
                $imgType = IMAGETYPE_JPEG;
                break;
            case 'png':
                $imgType = IMAGETYPE_PNG;
                break;
            default:
                $this->log("JImage: Invalid file type: {$type}", Log::ERROR);
                return false;
        }

        $res = false;

        $_quality = $imgType === IMAGETYPE_PNG ? 9 : $quality;

        try {
            $res = $img->toFile($cacheFilePath, $imgType, ['quality' => $_quality]);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to write image {$cacheFilePath}: {$ex->getMessage()}", Log::ERROR);
            return false;
        }

        $width = $img->getWidth();
        $height = $img->getHeight();

        return $res;
    }

    /**
     * @brief Returns the image orientation based on @a $width and @a $height.
     */
    public static function getOrientation(int $width, int $height) : int {
        if ($width > $height) {
            return HbnImages::ORIENTATION_LANDSCAPE;
        } else if ($height > $width) {
            return HbnImages::ORIENTATION_PORTRAIT;
        } else {
            return HbnImages::ORIENTATION_SQUARE;
        }
    }

    /**
     * @brief Returns the cache file name for @a $src as relative path to JPATH_ROOT.
     *
     * If something failes, an empty string will be returned.
     */
    public function getCacheFileName(Uri $src, int $width, int $height = 0, $type = 'webp') : string {
        if ($width > 0) {
            return $this->options['cacheDir'] . '/w' . (string)$width . '/' . File::stripExt($src->getPath()) . '.' . $type;
        } else if ($height > 0) {
            return $this->options['cacheDir'] . '/h' . (string)$height . '/' . File::stripExt($src->getPath()) . '.' . $type;
        } else {
            return '';
        }
    }

    /**
     * @brief Returns the image dimensions for the file at @a $localPath as a named array.
     *
     * @a $localPath has to be relative. The function will prepend the JPATH_ROOT. The returned
     * array will be empty if something failed. On success it will contain the two named values
     * "width" and "height".
     */
    public static function getImageDimensions(string $localPath) : array {
        $localFullPath = JPATH_ROOT . '/' . urldecode($localPath);
        try {
            $img = new Image($localFullPath);
        } catch (\Exception $ex) {
            $this->log("JImage: Failed to load image {$localFullPath}: {$ex->getMessage()}", Log::ERROR);
            return [];
        }

        return ["width" => $img->getWidth(), "height" => $img->getHeight()];
    }

    /**
     * @brief Creates the cache dir for the @a $cacheFilePath and returns @c true on success.
     *
     * This will create the complete directory structure needed by @a $cacheFilePath below JPATH_ROOT.
     * @a $cacheFilePath has to be relatative.
     */
    private function createCacheDir(string $cacheFilePath) : bool {
        $dirName = dirname($cacheFilePath);
        if (file_exists(JPATH_ROOT . '/' . $dirName)) {
            return true;
        }
        $parts = array_filter(explode('/', $dirName));
        if (empty($parts)) {
            return true;
        }

        $currentPath = JPATH_ROOT;
        foreach ($parts as $part) {
            $currentPath .= '/' . $part;
            if (!file_exists($currentPath)) {
                try {
                    Folder::create($currentPath);
                } catch (\Joomla\Filesystem\Exception\FilesystemException $ex) {
                    $this->log("Create Cache Dir: Failed to create directory {$currentPath}: {$ex->getMessage()}", Log::ERROR);
                    return false;
                }
            }
            $indexFile = $currentPath . '/index.html';
            if (!file_exists($indexFile)) {
                if (!File::write($indexFile, '<!DOCTYPE html><title></title>')) {
                    $this->log("Create Cache Dir: Failed to write index file {$indexFile}", Log::ERROR);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @brief Logs @a $message with @a $prio to the Joomla! log.
     */
    private function log(string $message, int $prio = Log::DEBUG) : void {
        Log::add($message, $prio, 'hbn.library.hbnimages');
    }
}
