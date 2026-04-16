<?php

/**
 * Standalone image thumbnailer for the FindIQ module.
 *
 * Produces square thumbnails using PHP's bundled GD extension. Unlike OpenCart's
 * model_tool_image it has no dependency on Imagick, which matters when the sync
 * cron runs under a CLI PHP build that differs from the FPM one serving the storefront.
 *
 * Generated files are cached at DIR_IMAGE/cache/find_iq/... and served as regular
 * static assets. Cache entries are invalidated when the source file is modified.
 *
 * Supported formats: JPEG, PNG, GIF, WEBP.
 * Animated GIF and WEBP inputs produce a static thumbnail from the first frame —
 * GD does not expose further frames, which matches FindIQ's requirement anyway.
 *
 * @package FindIQ
 */
class FindIQImage
{
    /** @var string Absolute filesystem path to OpenCart's image directory, with trailing slash. */
    private $imageDir;

    /** @var string Absolute filesystem path where thumbnails are cached, with trailing slash. */
    private $cacheDir;

    /** @var string Public HTTP base URL, with trailing slash. */
    private $httpServer;

    /**
     * @param Registry $registry OpenCart registry (used to resolve the storefront URL).
     */
    public function __construct($registry)
    {
        $this->imageDir = rtrim(DIR_IMAGE, '/') . '/';
        $this->cacheDir = $this->imageDir . 'cache/find_iq/';

        $configuredUrl    = (string)$registry->get('config')->get('config_url');
        $this->httpServer = $configuredUrl !== ''
            ? rtrim($configuredUrl, '/') . '/'
            : rtrim(HTTP_SERVER, '/') . '/';
    }

    /**
     * Return the public URL of a cached thumbnail, generating it on demand.
     *
     * The source path is resolved inside DIR_IMAGE. If the file is missing,
     * the method transparently falls back to "no_image.png".
     *
     * @param string $filename Relative path inside DIR_IMAGE (e.g. "catalog/foo/bar.jpg").
     * @param int    $width    Target canvas width in pixels.
     * @param int    $height   Target canvas height in pixels.
     *
     * @return string Public HTTP URL to the thumbnail, or an empty string on failure.
     */
    public function resize(string $filename, int $width, int $height): string
    {
        $filename = ltrim($filename, '/');
        $source   = $this->imageDir . $filename;

        if (!is_file($source)) {
            $filename = 'no_image.png';
            $source   = $this->imageDir . $filename;

            if (!is_file($source)) {
                return '';
            }
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return '';
        }

        $pathinfo  = pathinfo($filename);
        $extension = strtolower($pathinfo['extension'] ?? 'jpg');
        $directory = ($pathinfo['dirname'] !== '.' ? $pathinfo['dirname'] . '/' : '');
        $cacheRel  = $directory . $pathinfo['filename'] . '-' . $width . 'x' . $height . '.' . $extension;
        $cacheAbs  = $this->cacheDir . $cacheRel;

        // Regenerate if cached file is missing or stale relative to the source.
        if (!is_file($cacheAbs) || filemtime($cacheAbs) < filemtime($source)) {
            if (!$this->generate($source, $cacheAbs, $info, $width, $height)) {
                return '';
            }
        }

        return $this->httpServer . 'image/cache/find_iq/' . $cacheRel;
    }

    /**
     * Render a thumbnail from the source image and write it to disk.
     *
     * The source is scaled to fit within the target canvas while preserving
     * aspect ratio, then centered on a transparent or white background depending
     * on whether the format supports an alpha channel.
     *
     * @param string $source   Absolute path of the source image.
     * @param string $cacheAbs Absolute path where the thumbnail should be saved.
     * @param array  $info     Output of getimagesize() for the source.
     * @param int    $width    Target canvas width.
     * @param int    $height   Target canvas height.
     *
     * @return bool True on success, false if the directory, decoder or encoder failed.
     */
    private function generate(string $source, string $cacheAbs, array $info, int $width, int $height): bool
    {
        $directory = dirname($cacheAbs);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return false;
        }

        [$sourceWidth, $sourceHeight] = $info;
        $type = $info[2];

        $sourceImage = $this->createFromFile($source, $type);
        if (!$sourceImage) {
            return false;
        }

        // Scale to fit inside the target canvas, preserving aspect ratio.
        $scale        = min($width / $sourceWidth, $height / $sourceHeight);
        $scaledWidth  = max(1, (int)round($sourceWidth  * $scale));
        $scaledHeight = max(1, (int)round($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($width, $height);

        if ($this->supportsAlpha($type)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $background = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        } else {
            $background = imagecolorallocate($canvas, 255, 255, 255);
        }
        imagefilledrectangle($canvas, 0, 0, $width, $height, $background);

        // Center the scaled image on the canvas.
        $offsetX = (int)(($width  - $scaledWidth)  / 2);
        $offsetY = (int)(($height - $scaledHeight) / 2);

        imagecopyresampled(
            $canvas,
            $sourceImage,
            $offsetX,
            $offsetY,
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $sourceWidth,
            $sourceHeight
        );

        $saved = $this->saveToFile($canvas, $cacheAbs, $type);

        imagedestroy($sourceImage);
        imagedestroy($canvas);

        return $saved;
    }

    /**
     * Decode an image file into a GD resource.
     *
     * For animated GIF and WEBP only the first frame is returned — that is the
     * default behaviour of the underlying GD functions and matches what the
     * FindIQ search widget expects (static thumbnails).
     *
     * @param string $path Absolute path of the source file.
     * @param int    $type IMAGETYPE_* constant from getimagesize().
     *
     * @return \GdImage|resource|false GD resource on success, false otherwise.
     */
    private function createFromFile(string $path, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($path);

            case IMAGETYPE_PNG:
                return @imagecreatefrompng($path);

            case IMAGETYPE_GIF:
                return @imagecreatefromgif($path);

            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp')
                    ? @imagecreatefromwebp($path)
                    : false;

            default:
                return false;
        }
    }

    /**
     * Encode a GD resource to disk, choosing the writer that matches the source type.
     *
     * @param \GdImage|resource $image GD resource to write.
     * @param string            $path  Absolute destination path.
     * @param int               $type  IMAGETYPE_* constant from getimagesize().
     *
     * @return bool True on success, false otherwise.
     */
    private function saveToFile($image, string $path, int $type): bool
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagejpeg($image, $path, 85);

            case IMAGETYPE_PNG:
                return @imagepng($image, $path, 6);

            case IMAGETYPE_GIF:
                return @imagegif($image, $path);

            case IMAGETYPE_WEBP:
                return function_exists('imagewebp')
                    ? @imagewebp($image, $path, 85)
                    : false;

            default:
                return false;
        }
    }

    /**
     * Whether the given image type carries an alpha channel.
     *
     * @param int $type IMAGETYPE_* constant.
     *
     * @return bool
     */
    private function supportsAlpha(int $type): bool
    {
        return in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    }
}
