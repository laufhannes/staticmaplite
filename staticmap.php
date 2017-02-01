<?php

/**
 * staticMapLite 0.3.1
 *
 * Copyright 2009 Gerhard Koch
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 * @author modified by Hannes Christiansen <hannes AT runalyze.com>
 *
 * USAGE:
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=carto_nolabels
 *
 */

error_reporting(0);
ini_set('display_errors', 'off');

class staticMapLite
{

    protected $maxWidth = 2048;
    protected $maxHeight = 2048;

    protected $tileSize = 256;
    protected $tileSrcUrl = array(
        'carto_nolabels' => 'http://a.basemaps.cartocdn.com/light_nolabels/{Z}/{X}/{Y}.png',
        'carto_nolabels_dark' => 'http://a.basemaps.cartocdn.com/dark_nolabels/{Z}/{X}/{Y}.png',
        'hydda' => 'http://a.tile.openstreetmap.se/hydda/base/{Z}/{X}/{Y}.png',
        'stamen' => 'http://stamen-tiles-a.a.ssl.fastly.net/toner-background/{Z}/{X}/{Y}.png',
        'esri_ocean' => 'http://server.arcgisonline.com/ArcGIS/rest/services/Ocean_Basemap/MapServer/tile/{Z}/{Y}/{X}',
        'esri_world' => 'http://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{Z}/{Y}/{X}',
        'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',
    );

    protected $tileDefaultSrc = 'mapnik';
    protected $osmLogo = 'images/osm_logo.png';

    protected $useTileCache = true;
    protected $tileCacheBaseDir = './cache/tiles';

    protected $useMapCache = true;
    protected $mapCacheBaseDir = './cache/maps';
    protected $mapCacheID = '';
    protected $mapCacheFile = '';
    protected $mapCacheExtension = 'png';

    protected $zoom, $lat, $lon, $width, $height, $image, $maptype;
    protected $centerLat, $centerLon, $centerX, $centerY, $offsetX, $offsetY;

    public function __construct()
    {
        $this->zoom = 0;
        $this->lat = 0;
        $this->lon = 0;
        $this->width = 500;
        $this->height = 350;
        $this->maptype = $this->tileDefaultSrc;
    }

    public function parseParams()
    {
        $this->zoom = $_GET['zoom'] ? min(18, intval($_GET['zoom'])) : 0;

        if (isset($_GET['bounds'])) {
            list($lat1, $lon1, $lat2, $lon2) = explode(',', $_GET['bounds']);

            $this->lat = [floatval($lat1), floatval($lat2)];
            $this->lon = [floatval($lon1), floatval($lon2)];

            if ($this->lat[0] < $this->lat[1]) {
                $this->lat = [$this->lat[1], $this->lat[0]];
            }

            if ($this->lon[0] > $this->lon[1]) {
                $this->lon = [$this->lon[1], $this->lon[0]];
            }
        } else {
            // get lat and lon from GET paramter
            list($this->lat, $this->lon) = explode(',', $_GET['center']);

            $this->lat = floatval($this->lat);
            $this->lon = floatval($this->lon);
        }

        // get size from GET paramter
        if ($_GET['size']) {
            list($this->width, $this->height) = explode('x', $_GET['size']);

            $this->width = intval($this->width);
            $this->height = intval($this->height);

            if ($this->width > $this->maxWidth) {
                $this->width = $this->maxWidth;
            }

            if ($this->height > $this->maxHeight) {
                $this->height = $this->maxHeight;
            }
        }

        if ($_GET['maptype'] && array_key_exists($_GET['maptype'], $this->tileSrcUrl)) {
            $this->maptype = $_GET['maptype'];
        }
    }

    public function lonToTile($long, $zoom)
    {
        return (($long + 180) / 360) * pow(2, $zoom);
    }

    public function latToTile($lat, $zoom)
    {
        return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
    }

    public function tileToLatLon($x, $y, $zoom)
    {
        return [
            atan(sinh(pi() * (1 - 2 * $y / pow(2, $zoom)))) * 180 / pi(),
            $x / pow(2, $zoom) * 360 - 180
        ];
    }

    public function initCoords()
    {
        if (is_array($this->lon)) {
            $this->calculateZoomToFitBounds();
        } else {
            $this->centerX = $this->lonToTile($this->lon, $this->zoom);
            $this->centerY = $this->latToTile($this->lat, $this->zoom);
        }

        $this->offsetX = 0;
        $this->offsetY = 0;
    }

    protected function calculateZoomToFitBounds()
    {
        $this->centerLat = ($this->lat[0] + $this->lat[1]) / 2;
        $this->centerLon = ($this->lon[0] + $this->lon[1]) / 2;

        if ($this->lon[1] - $this->lon[0] > 180) {
            if ($this->centerLon < 0) {
                $this->centerLon += 180;
            } else {
                $this->centerLon -= 180;
            }
        }

        for ($zoom = 18; $zoom >= 0; --$zoom) {
            $centerX = $this->lonToTile($this->centerLon, $zoom);
            $centerY = $this->latToTile($this->centerLat, $zoom);
            $startX = floor($centerX - ($this->width / $this->tileSize) / 2);
            $startY = floor($centerY - ($this->height / $this->tileSize) / 2);
            $endX = ceil($centerX + ($this->width / $this->tileSize) / 2);
            $endY = ceil($centerY + ($this->height / $this->tileSize) / 2);

            list($startLat, $startLon) = $this->tileToLatLon($startX, $startY, $zoom);
            list($endLat, $endLon) = $this->tileToLatLon($endX, $endY, $zoom);

            if ($startLat > $this->lat[0] && $startLon < $this->lon[0] && $endLat < $this->lat[1] && $endLon > $this->lon[1]) {
                $this->zoom = $zoom;
                $this->width = (1 + $endX - $startX) * $this->tileSize;
                $this->height = (1 + $endY - $startY) * $this->tileSize;
                $this->centerX = ($startX + $endX) / 2;
                $this->centerY = ($startY + $endY) / 2;

                return;
            }
        }
    }

    public function createBaseMap()
    {
        $this->image = imagecreatetruecolor($this->width, $this->height);
        $startX = floor($this->centerX - ($this->width / $this->tileSize) / 2);
        $startY = floor($this->centerY - ($this->height / $this->tileSize) / 2);
        $endX = ceil($this->centerX + ($this->width / $this->tileSize) / 2);
        $endY = ceil($this->centerY + ($this->height / $this->tileSize) / 2);

        for ($x = $startX; $x <= $endX; $x++) {
            for ($y = $startY; $y <= $endY; $y++) {
                $url = str_replace(array('{Z}', '{X}', '{Y}'), array($this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
                $tileData =$this->fetchTile($url);
                if ($tileData) {
                    $tileImage = imagecreatefromstring($tileData);
                } else {
                    $tileImage = imagecreate($this->tileSize, $this->tileSize);
                    $color = imagecolorallocate($tileImage, rand(0,255), rand(0,255), rand(0,255));
                    @imagestring($tileImage, 1, 127, 127, 'err', $color);
                }
                $destX = ($x - $startX) * $this->tileSize + $this->offsetX;
                $destY = ($y - $startY) * $this->tileSize + $this->offsetY;
                imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
            }
        }

        if (is_array($this->lat)) {
            $this->cropImageToBoundaries();
        }
    }

    protected function cropImageToBoundaries()
    {
        $requestedCenterX = $this->lonToTile($this->centerLon, $this->zoom);
        $requestedCenterY = $this->latToTile($this->centerLat, $this->zoom);
        $imageBoundary = [ // upper, right, lower, left
            floor($this->centerY - ($this->height / $this->tileSize) / 2),
            ceil($this->centerX + ($this->width / $this->tileSize) / 2),
            ceil($this->centerY + ($this->height / $this->tileSize) / 2),
            floor($this->centerX - ($this->width / $this->tileSize) / 2)
        ];
        $cropBoundary = [ // upper, right, lower, left
            $this->latToTile($this->lat[0], $this->zoom),
            $this->lonToTile($this->lon[1], $this->zoom),
            $this->latToTile($this->lat[1], $this->zoom),
            $this->lonToTile($this->lon[0], $this->zoom),
        ];

        $this->image = imagecrop($this->image, [
            'x' => ($cropBoundary[3] - $imageBoundary[3]) * $this->tileSize,
            'y' => ($cropBoundary[0] - $imageBoundary[0]) * $this->tileSize,
            'width' => ($cropBoundary[1] - $cropBoundary[3]) * $this->tileSize,
            'height' => ($cropBoundary[2] - $cropBoundary[0]) * $this->tileSize
        ]);
    }

    public function tileUrlToFilename($url)
    {
        return $this->tileCacheBaseDir . "/" . str_replace(array('http://'), '', $url);
    }

    public function checkTileCache($url)
    {
        $filename = $this->tileUrlToFilename($url);

        if (file_exists($filename)) {
            return file_get_contents($filename);
        }
    }

    public function checkMapCache()
    {
        $this->mapCacheID = md5($this->serializeParams());
        $filename = $this->mapCacheIDToFilename();

        if (file_exists($filename)) {
            return true;
        }
    }

    public function serializeParams()
    {
        return join("&", array($this->zoom, serialize($this->lat), serialize($this->lon), $this->width, $this->height, $this->maptype));
    }

    public function mapCacheIDToFilename()
    {
        if (!$this->mapCacheFile) {
            $this->mapCacheFile = $this->mapCacheBaseDir . "/" . $this->maptype . "/" . $this->zoom . "/cache_" . substr($this->mapCacheID, 0, 2) . "/" . substr($this->mapCacheID, 2, 2) . "/" . substr($this->mapCacheID, 4);
        }

        return $this->mapCacheFile . "." . $this->mapCacheExtension;
    }


    public function mkdir_recursive($pathname, $mode)
    {
        is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);

        return is_dir($pathname) || @mkdir($pathname, $mode);
    }

    public function writeTileToCache($url, $data)
    {
        $filename = $this->tileUrlToFilename($url);
        $this->mkdir_recursive(dirname($filename), 0777);
        file_put_contents($filename, $data);
    }

    public function fetchTile($url)
    {
        if ($this->useTileCache && ($cached = $this->checkTileCache($url))) {
            return $cached;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0");
        curl_setopt($ch, CURLOPT_URL, $url);
        $tile = curl_exec($ch);
        curl_close($ch);

        if ($tile && $this->useTileCache) {
            $this->writeTileToCache($url, $tile);
        }

        return $tile;
    }

    public function copyrightNotice()
    {
        $logoImg = imagecreatefrompng($this->osmLogo);
        imagecopy($this->image, $logoImg, imagesx($this->image) - imagesx($logoImg), imagesy($this->image) - imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));
    }

    public function sendHeader()
    {
        $expires = 60 * 60 * 24 * 14;

        header('Content-Type: image/png');
        header("Pragma: public");
        header("Cache-Control: maxage=" . $expires);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
    }

    public function makeMap()
    {
        $this->initCoords();
        $this->createBaseMap();

        if ($this->osmLogo) {
            //$this->copyrightNotice();
        }
    }

    public function showMap()
    {
        $this->parseParams();

        if ($this->useMapCache) {
            if (!$this->checkMapCache()) {
                $this->makeMap();
                $this->mkdir_recursive(dirname($this->mapCacheIDToFilename()), 0777);
                imagepng($this->image, $this->mapCacheIDToFilename(), 9);
                $this->sendHeader();

                if (file_exists($this->mapCacheIDToFilename())) {
                    return file_get_contents($this->mapCacheIDToFilename());
                } else {
                    return imagepng($this->image);
                }
            } else {
                $this->sendHeader();

                return file_get_contents($this->mapCacheIDToFilename());
            }
        } else {
            $this->makeMap();
            $this->sendHeader();

            return imagepng($this->image);
        }
    }
}

$map = new staticMapLite();
print $map->showMap();
