<?php
/**
 * @link      http://buildwithcraft.com/
 * @copyright Copyright (c) 2015 Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license
 */

namespace craft\app\io;

use Craft;
use craft\app\errors\Exception;
use craft\app\helpers\ImageHelper;
use craft\app\helpers\IOHelper;
use Imagine\Image\AbstractFont;
use Imagine\Image\FontInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;

/**
 * Class Image
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Image
{
    // Properties
    // =========================================================================

    /**
     * @var int The minimum width that the image should be loaded with if it’s an SVG.
     */
    public $minSvgWidth;

    /**
     * @var int The minimum height that the image should be loaded with if it’s an SVG.
     */
    public $minSvgHeight;

    /**
     * @var string
     */
    private $_imageSourcePath;

    /**
     * @var string
     */
    private $_extension;

    /**
     * @var bool
     */
    private $_isAnimatedGif = false;

    /**
     * @var int
     */
    private $_quality = 0;

    /**
     * @var ImageInterface
     */
    private $_image;

    /**
     * @var ImagineInterface
     */
    private $_instance;

    /**
     * @var RGB
     */
    private $_palette;

    /**
     * @var FontInterface|AbstractFont
     */
    private $_font;

    // Public Methods
    // =========================================================================

    /**
     * @return Image
     */
    public function __construct()
    {
        $extension = mb_strtolower(
            Craft::$app->getConfig()->get('imageDriver')
        );

        // If it's explicitly set, take their word for it.
        if ($extension === 'gd') {
            $this->_instance = new \Imagine\Gd\Imagine();
        } else {
            if ($extension === 'imagick') {
                $this->_instance = new \Imagine\Imagick\Imagine();
            } else {
                // Let's try to auto-detect.
                if (Craft::$app->getImages()->isGd()) {
                    $this->_instance = new \Imagine\Gd\Imagine();
                } else {
                    $this->_instance = new \Imagine\Imagick\Imagine();
                }
            }
        }

        $this->_quality = Craft::$app->getConfig()->get('defaultImageQuality');
    }

    /**
     * @return integer
     */
    public function getWidth()
    {
        return $this->_image->getSize()->getWidth();
    }

    /**
     * @return integer
     */
    public function getHeight()
    {
        return $this->_image->getSize()->getHeight();
    }

    /**
     * @return string
     */
    public function getExtension()
    {
        return $this->_extension;
    }

    /**
     * Loads an image from a file system path.
     *
     * @param string $path
     *
     * @throws Exception
     * @return Image
     */
    public function loadImage($path)
    {
        $imageService = Craft::$app->getImages();

        if (!IOHelper::fileExists($path)) {
            throw new Exception(Craft::t(
                'app',
                'No file exists at the path “{path}”',
                ['path' => $path]
            ));
        }

        if (!$imageService->checkMemoryForImage($path)) {
            throw new Exception(Craft::t(
                'app',
                'Not enough memory available to perform this image operation.'
            ));
        }

        $extension = IOHelper::getExtension($path);

        if ($extension === 'svg') {
            if (!$imageService->isImagick()) {
                throw new Exception(Craft::t(
                    'The file “{path}” does not appear to be an image.',
                    array('path' => $path)
                ));
            }

            $svg = IOHelper::getFileContents($path);

            if ($this->minSvgWidth !== null && $this->minSvgHeight !== null) {
                // Does the <svg> node contain valid `width` and `height` attributes?
                list($width, $height) = ImageHelper::parseSvgSize($svg);

                if ($width !== null && $height !== null) {
                    $scale = 1;

                    if ($width < $this->minSvgWidth) {
                        $scale = $this->minSvgWidth / $width;
                    }

                    if ($height < $this->minSvgHeight) {
                        $scale = max($scale, ($this->minSvgHeight / $height));
                    }

                    $width = round($width * $scale);
                    $height = round($height * $scale);

                    if (preg_match(ImageHelper::SVG_WIDTH_RE, $svg) && preg_match(ImageHelper::SVG_HEIGHT_RE, $svg)) {
                        $svg = preg_replace(
                            ImageHelper::SVG_WIDTH_RE,
                            "\${1}{$width}px\"",
                            $svg
                        );
                        $svg = preg_replace(
                            ImageHelper::SVG_HEIGHT_RE,
                            "\${1}{$height}px\"",
                            $svg
                        );
                    } else {
                        $svg = preg_replace(
                            ImageHelper::SVG_TAG_RE,
                            "\${1} width=\"{$width}px\" height=\"{$height}px\" \${2}",
                            $svg
                        );
                    }
                }
            }

            try {
                $this->_image = $this->_instance->load($svg);
            } catch (\Imagine\Exception\RuntimeException $e) {
                // Invalid SVG. Maybe it's missing its DTD?
                $svg = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'.$svg;
                $this->_image = $this->_instance->load($svg);
            }
        } else {
            try {
                $this->_image = $this->_instance->open($path);
            } catch (\Imagine\Exception\RuntimeException $e) {
                throw new Exception(Craft::t(
                    'The file “{path}” does not appear to be an image.',
                    array('path' => $path)
                ));
            }
        }

        $this->_extension = IOHelper::getExtension($path);
        $this->_imageSourcePath = $path;

        if ($this->_extension == 'gif') {
            if (!$imageService->isGd() && $this->_image->layers()) {
                $this->_isAnimatedGif = true;
            }
        }

        return $this;
    }

    /**
     * Crops the image to the specified coordinates.
     *
     * @param integer $x1
     * @param integer $x2
     * @param integer $y1
     * @param integer $y2
     *
     * @return Image
     */
    public function crop($x1, $x2, $y1, $y2)
    {
        $width = $x2 - $x1;
        $height = $y2 - $y1;

        if ($this->_isAnimatedGif) {

            // Create a new image instance to avoid object references messing up our dimensions.
            $newSize = new \Imagine\Image\Box($width, $height);
            $startingPoint = new Point($x1, $y1);
            $gif = $this->_instance->create($newSize);
            $gif->layers()->remove(0);

            foreach ($this->_image->layers() as $layer) {
                $croppedLayer = $layer->crop($startingPoint, $newSize);
                $gif->layers()->add($croppedLayer);
            }

            $this->_image = $gif;
        } else {
            $this->_image->crop(
                new Point($x1, $y1),
                new \Imagine\Image\Box($width, $height)
            );
        }

        return $this;
    }

    /**
     * Scale the image to fit within the specified size.
     *
     * @param integer      $targetWidth
     * @param integer|null $targetHeight
     * @param boolean      $scaleIfSmaller
     *
     * @return Image
     */
    public function scaleToFit($targetWidth, $targetHeight = null, $scaleIfSmaller = true)
    {
        $this->_normalizeDimensions($targetWidth, $targetHeight);

        if ($scaleIfSmaller || $this->getWidth() > $targetWidth || $this->getHeight() > $targetHeight
        ) {
            $factor = max(
                $this->getWidth() / $targetWidth,
                $this->getHeight() / $targetHeight
            );
            $this->resize(
                round($this->getWidth() / $factor),
                round($this->getHeight() / $factor)
            );
        }

        return $this;
    }

    /**
     * Scale and crop image to exactly fit the specified size.
     *
     * @param integer      $targetWidth
     * @param integer|null $targetHeight
     * @param boolean      $scaleIfSmaller
     * @param string       $cropPositions
     *
     * @return Image
     */
    public function scaleAndCrop($targetWidth, $targetHeight = null, $scaleIfSmaller = true, $cropPositions = 'center-center')
    {
        $this->_normalizeDimensions($targetWidth, $targetHeight);

        list($verticalPosition, $horizontalPosition) = explode(
            "-",
            $cropPositions
        );

        if ($scaleIfSmaller || $this->getWidth() > $targetWidth || $this->getHeight() > $targetHeight
        ) {
            // Scale first.
            $factor = min(
                $this->getWidth() / $targetWidth,
                $this->getHeight() / $targetHeight
            );
            $newHeight = round($this->getHeight() / $factor);
            $newWidth = round($this->getWidth() / $factor);

            $this->resize($newWidth, $newHeight);

            // Now crop.
            if ($newWidth - $targetWidth > 0) {
                switch ($horizontalPosition) {
                    case 'left': {
                        $x1 = 0;
                        $x2 = $x1 + $targetWidth;
                        break;
                    }
                    case 'right': {
                        $x2 = $newWidth;
                        $x1 = $newWidth - $targetWidth;
                        break;
                    }
                    default: {
                        $x1 = round(($newWidth - $targetWidth) / 2);
                        $x2 = $x1 + $targetWidth;
                        break;
                    }
                }

                $y1 = 0;
                $y2 = $y1 + $targetHeight;
            } else if ($newHeight - $targetHeight > 0) {
                switch ($verticalPosition) {
                    case 'top': {
                        $y1 = 0;
                        $y2 = $y1 + $targetHeight;
                        break;
                    }
                    case 'bottom': {
                        $y2 = $newHeight;
                        $y1 = $newHeight - $targetHeight;
                        break;
                    }
                    default: {
                        $y1 = round(($newHeight - $targetHeight) / 2);
                        $y2 = $y1 + $targetHeight;
                        break;
                    }
                }

                $x1 = 0;
                $x2 = $x1 + $targetWidth;
            } else {
                $x1 = round(($newWidth - $targetWidth) / 2);
                $x2 = $x1 + $targetWidth;
                $y1 = round(($newHeight - $targetHeight) / 2);
                $y2 = $y1 + $targetHeight;
            }

            $this->crop($x1, $x2, $y1, $y2);
        }

        return $this;
    }

    /**
     * Re-sizes the image. If $height is not specified, it will default to $width, creating a square.
     *
     * @param integer      $targetWidth
     * @param integer|null $targetHeight
     *
     * @return Image
     */
    public function resize($targetWidth, $targetHeight = null)
    {
        $this->_normalizeDimensions($targetWidth, $targetHeight);

        if ($this->_isAnimatedGif) {

            // Create a new image instance to avoid object references messing up our dimensions.
            $newSize = new \Imagine\Image\Box($targetWidth, $targetHeight);
            $gif = $this->_instance->create($newSize);
            $gif->layers()->remove(0);

            foreach ($this->_image->layers() as $layer) {
                $resizedLayer = $layer->resize(
                    $newSize,
                    $this->_getResizeFilter()
                );
                $gif->layers()->add($resizedLayer);
            }

            $this->_image = $gif;
        } else {
            $this->_image->resize(
                new \Imagine\Image\Box($targetWidth,
                    $targetHeight),
                $this->_getResizeFilter()
            );
        }

        return $this;
    }

    /**
     * Rotate an image by degrees.
     *
     * @param integer $degrees
     *
     * @return Image
     */
    public function rotate($degrees)
    {
        $this->_image->rotate($degrees);

        return $this;
    }

    /**
     * Set image quality.
     *
     * @param integer $quality
     *
     * @return Image
     */
    public function setQuality($quality)
    {
        $this->_quality = $quality;

        return $this;
    }

    /**
     * Saves the image to the target path.
     *
     * @param string  $targetPath
     * @param boolean $sanitizeAndAutoQuality
     *
     * @throws \Imagine\Exception\RuntimeException
     * @return bool
     */
    public function saveAs($targetPath, $sanitizeAndAutoQuality = false)
    {
        $extension = IOHelper::getExtension($targetPath);
        $options = $this->_getSaveOptions(false, $extension);
        $targetPath = IOHelper::getFolderName(
                $targetPath
            ).IOHelper::getFilename(
                $targetPath,
                false
            ).'.'.$extension;

        if (($extension == 'jpeg' || $extension == 'jpg' || $extension == 'png') && $sanitizeAndAutoQuality) {
            clearstatcache();
            $originalSize = IOHelper::getFileSize($this->_imageSourcePath);
            $this->_autoGuessImageQuality(
                $targetPath,
                $originalSize,
                $extension,
                0,
                200
            );
        } else {
            $this->_image->save($targetPath, $options);
        }

        return true;
    }

    /**
     * Returns true if Imagick is installed and says that the image is transparent.
     *
     * @return boolean
     */
    public function isTransparent()
    {
        if (Craft::$app->getImages()->isImagick() && method_exists(
                "Imagick",
                "getImageAlphaChannel"
            )
        ) {
            return $this->_image->getImagick()->getImageAlphaChannel();
        }

        return false;
    }

    /**
     * Return EXIF metadata for a file by it's path
     *
     * @param $filePath
     *
     * @return array
     */
    public function getExifMetadata($filePath)
    {
        try {
            $exifReader = new \Imagine\Image\Metadata\ExifMetadataReader();
            $this->_instance->setMetadataReader($exifReader);
            $exif = $this->_instance->open($filePath)->metadata();

            return $exif->toArray();
        } catch (\Imagine\Exception\NotSupportedException $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return [];
        }
    }

    /**
     * Sets properties for text drawing on the image.
     *
     * @param string  $fontFile Path to the font file on server
     * @param integer $size     Font size to use
     * @param string  $color    Font color to use in hex format
     *
     * @return void
     */
    public function setFontProperties($fontFile, $size, $color)
    {
        if (empty($this->_palette)) {
            $this->_palette = new RGB();
        }

        $this->_font = $this->_instance->font(
            $fontFile,
            $size,
            $this->_palette->color($color)
        );
    }

    /**
     * Returns the bounding text box for a text string and an angle.
     *
     * @param string  $text
     * @param integer $angle
     *
     * @throws Exception
     * @return \Imagine\Image\BoxInterface
     */
    public function getTextBox($text, $angle = 0)
    {
        if (empty($this->_font)) {
            throw new Exception('No font properties have been set. Call Image::setFontProperties() first.');
        }

        return $this->_font->box($text, $angle);
    }

    /**
     * Writes text on an image.
     *
     * @param string  $text
     * @param integer $x
     * @param integer $y
     * @param integer $angle
     *
     * @return void
     * @throws Exception
     */
    public function writeText($text, $x, $y, $angle = 0)
    {
        if (empty($this->_font)) {
            throw new Exception('No font properties have been set. Call Image::setFontProperties() first.');
        }

        $point = new Point($x, $y);
        $this->_image->draw()->text($text, $this->_font, $point, $angle);
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalizes the given dimensions.  If width or height is set to 'AUTO', we calculate the missing dimension.
     *
     * @param integer|string $width
     * @param integer|string $height
     *
     * @throws Exception
     */
    private function _normalizeDimensions(&$width, &$height = null)
    {
        if (preg_match(
            '/^(?P<width>[0-9]+|AUTO)x(?P<height>[0-9]+|AUTO)/',
            $width,
            $matches
        )
        ) {
            $width = $matches['width'] != 'AUTO' ? $matches['width'] : null;
            $height = $matches['height'] != 'AUTO' ? $matches['height'] : null;
        }

        if (!$height || !$width) {
            list($width, $height) = ImageHelper::calculateMissingDimension(
                $width,
                $height,
                $this->getWidth(),
                $this->getHeight()
            );
        }
    }

    /**
     * @param         $tempFilename
     * @param         $originalSize
     * @param         $extension
     * @param         $minQuality
     * @param         $maxQuality
     * @param integer $step
     *
     * @return boolean
     */
    private function _autoGuessImageQuality($tempFilename, $originalSize, $extension, $minQuality, $maxQuality, $step = 0)
    {
        // Give ourselves some extra time.
        @set_time_limit(30);

        if ($step == 0) {
            $tempFilename = IOHelper::getFolderName(
                    $tempFilename
                ).IOHelper::getFilename(
                    $tempFilename,
                    false
                ).'-temp.'.$extension;
        }

        // Find our target quality by splitting the min and max qualities
        $midQuality = (int)ceil(
            $minQuality + (($maxQuality - $minQuality) / 2)
        );

        // Set the min and max acceptable ranges. .10 means anything between 90% and 110% of the original file size is acceptable.
        $acceptableRange = .10;

        clearstatcache();

        // Generate a new temp image and get it's file size.
        $this->_image->save(
            $tempFilename,
            $this->_getSaveOptions($midQuality, $extension)
        );
        $newFileSize = IOHelper::getFileSize($tempFilename);

        // If we're on step 10 OR we're within our acceptable range threshold OR midQuality = maxQuality (1 == 1),
        // let's use the current image.
        if ($step == 10 || abs(
                1 - $originalSize / $newFileSize
            ) < $acceptableRange || $midQuality == $maxQuality
        ) {
            clearstatcache();

            // Generate one last time.
            $this->_image->save(
                $tempFilename,
                $this->_getSaveOptions($midQuality)
            );

            return true;
        }

        $step++;

        if ($newFileSize > $originalSize) {
            return $this->_autoGuessImageQuality(
                $tempFilename,
                $originalSize,
                $extension,
                $minQuality,
                $midQuality,
                $step
            );
        } // Too much.
        else {
            return $this->_autoGuessImageQuality(
                $tempFilename,
                $originalSize,
                $extension,
                $midQuality,
                $maxQuality,
                $step
            );
        }
    }

    /**
     * @return mixed
     */
    private function _getResizeFilter()
    {
        return (Craft::$app->getImages()->isGd() ? ImageInterface::FILTER_UNDEFINED : ImageInterface::FILTER_LANCZOS);
    }

    /**
     * Get save options.
     *
     * @param integer|null $quality
     * @param string       $extension
     *
     * @return array
     */
    private function _getSaveOptions($quality = null, $extension = null)
    {
        // Because it's possible for someone to set the quality to 0.
        $quality = ($quality === null || $quality === false ? $this->_quality : $quality);
        $extension = (!$extension ? $this->getExtension() : $extension);

        switch ($extension) {
            case 'jpeg':
            case 'jpg': {
                return ['jpeg_quality' => $quality, 'flatten' => true];
            }

            case 'gif': {
                $options = ['animated' => $this->_isAnimatedGif];

                if ($this->_isAnimatedGif) {
                    // Imagine library does not provide this value and arbitrarily divides it by 10, when assigning,
                    // so we have to improvise a little
                    $options['animated.delay'] = $this->_image->getImagick()->getImageDelay() * 10;
                }

                return $options;
            }

            case 'png': {
                // Valid PNG quality settings are 0-9, so normalize and flip, because we're talking about compression
                // levels, not quality, like jpg and gif.
                $normalizedQuality = round(($quality * 9) / 100);
                $normalizedQuality = 9 - $normalizedQuality;

                if ($normalizedQuality < 0) {
                    $normalizedQuality = 0;
                }

                if ($normalizedQuality > 9) {
                    $normalizedQuality = 9;
                }

                $options = [
                    'png_compression_level' => $normalizedQuality,
                    'flatten' => false
                ];
                $pngInfo = ImageHelper::getPngImageInfo(
                    $this->_imageSourcePath
                );

                // Even though a 2 channel PNG is valid (Grayscale with alpha channel), Imagick doesn't recognize it as
                // a valid format: http://www.imagemagick.org/script/formats.php
                // So 2 channel PNGs get converted to 4 channel.

                if (is_array(
                        $pngInfo
                    ) && isset($pngInfo['channels']) && $pngInfo['channels'] !== 2
                ) {
                    $format = 'png'.(8 * $pngInfo['channels']);
                } else {
                    $format = 'png32';
                }

                $options['png_format'] = $format;

                return $options;
            }

            default: {
                return [];
            }
        }
    }
}
