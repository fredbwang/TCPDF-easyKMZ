<?php

/*********************************************************************
 * TCPDF KMZ parser                                                   *
 *                                                                    *
 * Version: 1.0                                                       *
 * Date:    04-28-2018                                                *
 * Author:  Borui Wang                                                *
 **********************************************************************/
class easyKMZ
{
    // error code
    const NO_VALID_KML = 0;
    const ZIP_ERROR = 1;

    const TILE_SIZE = 256; // default param from google coord system
    const BOUNDS = [15, 40, 195, 280];
    private $pdf_obj;
    private $kmz_obj;
    private $kmz_local_folder;
    private $styles_map;
    private $styles;
    private $placemark_data;
    private $on_page_bounds; // x y bounds of pdf page
    private $LatLngBox; // lat lng bounds from kmz
    private $static_map_size_pixel; // pixel [width, height]
    private $static_map_size_distance; // [lat distance, lng distance]
    private $staitc_map_center; // [lat, lng]
    private $static_map_url;
    private $static_map_path;
    private $static_map;
    private $overlay_path;
    private $overlay;
    private $zoom;
    private $m2p_scale; // map width (pixel) / page width(mm)
    private $file_id;

    public $error_msg;

    /**
     * __construct
     *
     * @param \exTCPDF $pdf
     * @param int $file_id
     * @param string $kmz_path supports url and local path
     * @param mixed bounds  pdf page bounds of google static map AWA base map
     * @param stdClass $placemark_data  issue data from database, if no data then read data from kmz
     * @return void
     */
    public function __construct(\TCPDF $pdf, string $kmz_path, string $google_api_key, string $map_type = 'satellite', $bounds = self::BOUNDS, $displayColor = false,  int $file_id = null)
    {
        $this->pdf_obj = $pdf;
        $this->google_api_key = $google_api_key;

        if ($file_id == null) {
            $this->file_id = uniqid();
        } else {
            $this->file_id = $file_id;
        }
     
        // read info from kmz
        if ($this->readFile($kmz_path) !== true) {
            throw new Exception('Can read kmz!');
            return false;
        }
        $this->loadStyleDataFromKMZ($this->kmz_obj);
        $this->placemark_data = $this->loadPlacemarkDataFromKMZ($this->kmz_obj);
        // var_dump($this->placemark_data, $this->styles, $this->styles_map);
        $this->displayColor = $displayColor;

        $this->on_page_bounds = $bounds === null ? self::BOUNDS : $bounds;
        $this->LatLngBox = $this->getLatLngBox($this->kmz_obj);

        if ($this->LatLngBox == null) {
            $this->hasOverlay = false; // No Overlay
            // throw new Exception('Can not find overlay in kmz');
            // return false;
        } else {
            $this->hasOverlay = true;
        }

        // process info from kmz
        $this->static_map_center = $this->getMapCenter($this->LatLngBox, $this->placemark_data);
        $this->zoom = $this->getMapZoom($this->LatLngBox, $this->static_map_size_pixel);
        $this->static_map_size_pixel = $this->calculateMapSize($this->LatLngBox, $this->zoom);
     
        // get content from overlay file
        $this->overlay_path = $this->getOverlayPath($this->kmz_obj);
        // $this->overlay = file_get_contents($this->overlay_path);

        // get base map, if no map, get it from google static map
        $this->static_map_url = $this->constructURL($this->static_map_center, $this->static_map_size_pixel, $this->zoom, $map_type);

        $this->static_map = file_get_contents($this->static_map_url);
    }

    /**
     * readFile
     * read kmz file
     * @param string $kmz_path
     * @return void
     */
    protected function readFile(string $kmz_path)
    {
        $local_path = dirname(__FILE__) . '/temp_file/' . $this->file_id . '.kmz';
        if ($this->is_url_exists($kmz_path)) {
            if (!file_exists($local_path)) {
                file_put_contents($local_path, fopen($kmz_path, 'r'));
            }
        } else {
            $local_path = $kmz_path;
        }

        $zip = new ZipArchive;
        $relativePath = '/temp_file/kmz_' . $this->file_id;
        $extractPath = dirname(__FILE__) . $relativePath;
        if (!file_exists($extractPath)) {
            if ($zip->open($local_path) === true) {
                $zip->extractTo($extractPath);
                $zip->close();
            } else {
                throw new Exception("can\'t open zip. Error Code: " . self::ZIP_ERROR);
            }
        }

        $this->kmz_local_folder = dirname(__FILE__) . $relativePath;

        $files = glob($extractPath . "/*.kml"); // grab all files ended with .kml
        if ($files == null || count($files) == 0) {
            throw new Exception(self::NO_VALID_KML);
        } else {
            $this->kmz_obj = simplexml_load_file($files[0]);
            return true;
        }
    }

    /**
     * getLatLngBox
     * get latlng box from kmz
     * @param SimpleXMLElement $kmz
     * @return mixed
     */
    protected function getLatLngBox(SimpleXMLElement $kmz)
    {
        $groundOverlay = $this->getGroundOverlayObject($kmz);
        if ($groundOverlay == null) {
            return null;
        } else {
            return json_decode(json_encode($groundOverlay->LatLonBox));
        }
    }

    /**
     * getOverlayPath
     * get overlay path from kmz
     * @param SimpleXMLElement $kmz
     * @return mixed
     */
    protected function getOverlayPath(SimpleXMLElement $kmz)
    {
        $groundOverlay = $this->getGroundOverlayObject($kmz);

        if ($groundOverlay == null) {
            return null;
        } else if ($this->is_url_exists($groundOverlay->Icon->href)) {
            return $groundOverlay->Icon->href;
        } else {
            return dirname(__FILE__) . '/temp_file/kmz_' . $this->file_id . '/' . $groundOverlay->Icon->href;
        }
    }

    protected function getGroundOverlayObject(SimpleXMLElement $kmz)
    {
        $overlay = null;
        foreach ($kmz as $key => $value) {
            if ($key == 'GroundOverlay') {
                return $value;
            } else {
                $overlay = $this->getGroundOverlayObject($value);
            }
        }

        return $overlay;
    }


    protected function setConfig()
    {        

        // $this->pdf_obj->setPrintHeader(false);
        // $this->pdf_obj->setPrintFooter(false);
        
        // store config
        $config = [];
        $config['Margins'] = $this->pdf_obj->getMargins();
        $config['AutoPageBreak'] = $this->pdf_obj->getAutoPageBreak();

        // reset config: remove margin and transparency
        $this->pdf_obj->SetMargins(0, 0, 0, false);
        $this->pdf_obj->SetAutoPageBreak(false);

        return $config;
    }

    protected function restoreConfig($config)
    {
        // reset config
        // $this->pdf_obj->setPrintHeader(true);
        // $this->pdf_obj->setPrintFooter(true);

        $this->pdf_obj->SetMargins($config['Margins']['left'], $config['Margins']['top'], $config['Margins']['right'], false);
        $this->pdf_obj->SetAutoPageBreak($config['AutoPageBreak'], $config['Margins']['bottom']);
    }

    public function setBounds(float $x1, float $y1, float $x2, float $y2)
    {
        $this->on_page_bounds = [$x1, $y1, $x2, $y2];
    }

    public function getMapSize()
    {
        return $this->static_map_size_pixel;
    }

    /**
     * print
     *
     * @param string $layer choose the layer to be rendered: O (overlay), M (base_map), P (placemarks), L(legend)
     * @return void
     */
    public function print(string $layer = 'OP')
    {
        $layer = str_split($layer);
        $this->pdf_config = $this->setConfig();

        if (in_array('M', $layer)) {
            $this->printBaseMap($this->static_map, $this->on_page_bounds);
        }

        if (in_array('O', $layer) && $this->hasOverlay) {
            $this->static_map_bounds = $this->getStaticMapBounds($this->static_map_center, $this->zoom, $this->static_map_size_pixel);
            $this->projectOverlay($this->LatLngBox, $this->static_map_size_pixel, $this->on_page_bounds, $this->zoom, $layer);
        }

        if (in_array('P', $layer)) {
            $this->printPlacemarkList($this->placemark_data, in_array('L', $layer));
        }

        $this->restoreConfig($this->pdf_config);
        return;
    }

    /**
     * printBaseMap
     *
     * @param string $map_path
     * @param array $bounds
     * @return void
     */
    protected function printBaseMap($map, array $bounds)
    {
        $this->pdf_obj->SetAlpha(1);

        $this->pdf_obj->Image('@' . $map, $bounds[0], $bounds[1], $bounds[2] - $bounds[0], null, 'PNG');

        $this->pdf_obj->SetAlpha(1);
    }

    /**
     * projectOverlay
     * render overlay on base map
     * @param stdClass $overlay_latlngbox
     * @param array $static_map_size
     * @param array $on_page_bounds
     * @param int $zoom
     * @param array $layer
     * @return void
     */
    protected function projectOverlay(stdClass $overlay_latlngbox, array $static_map_size, array $on_page_bounds, int $zoom, array $layer)
    {
        $overlay_pixcoord_nw = $this->LatLngToPixCoord($overlay_latlngbox->north, $overlay_latlngbox->west, $zoom);
        $overlay_pixcoord_se = $this->LatLngToPixCoord($overlay_latlngbox->south, $overlay_latlngbox->east, $zoom);
        $overlay_width = (float)abs($overlay_pixcoord_se[0] - $overlay_pixcoord_nw[0]);
        $overlay_height = (float)abs($overlay_pixcoord_nw[1] - $overlay_pixcoord_se[1]);
        $overlay_rotation = $overlay_latlngbox->rotation;

        $map_width = (float)$static_map_size[0];
        $map_height = (float)$static_map_size[1];

        // $this->printBounds();
        $on_page_width = (float)$on_page_bounds[2] - $on_page_bounds[0];
        $on_page_height = (float)$on_page_bounds[3] - $on_page_bounds[1];

        if (in_array('M', $layer)) {
            $scale = $on_page_width / $map_width;
            $on_page_center = [$static_map_size[0] * $scale / 2 + $on_page_bounds[0], $static_map_size[1] * $scale / 2 + $on_page_bounds[1]];

            $start_x = $on_page_bounds[0] - ($overlay_width - $map_width) * $scale / 2;
            $start_y = $on_page_bounds[1] - ($overlay_height - $map_height) * $scale / 2;
        } else { // if no map layer, take only overlay into consideration
            if (abs($this->LatLngBox->rotation % 180 - 90) < 45) {
                $scale = $on_page_width / $overlay_height;
                $on_page_center = [$overlay_height * $scale / 2 + $on_page_bounds[0], $overlay_width * $scale / 2 + $on_page_bounds[1]];

                $start_x = $on_page_center[0] - $overlay_width * $scale / 2;
                $start_y = $on_page_center[1] - $overlay_height * $scale / 2;
            } else {
                $scale = $on_page_width / $overlay_width;
                $on_page_center = [$overlay_width * $scale / 2 + $on_page_bounds[0], $overlay_height * $scale / 2 + $on_page_bounds[1]];

                $start_x = $on_page_bounds[0];
                $start_y = $on_page_bounds[1];
            }
        }
        $this->m2p_scale = $scale;
        $this->on_page_center = $on_page_center;
        $overlay_path = $this->is_url_exists($this->overlay_path) ? '@' . file_get_contents($this->overlay_path) : $this->overlay_path;
        
        if ($overlay_rotation != 0) {
            // Start Rotate
            $this->pdf_obj->StartTransform();
            $this->pdf_obj->Rotate($overlay_rotation, $on_page_center[0], $on_page_center[1]);
            $this->pdf_obj->Image((string) $overlay_path, $start_x, $start_y, $overlay_width * $scale, $overlay_height * $scale, '', '', '', true, 300);
            // Stop Rotate
            $this->pdf_obj->StopTransform();
        } else {
            $this->pdf_obj->Image($overlay_path, $start_x, $start_y, $overlay_width * $scale, $overlay_height * $scale, '', '', '', true, 300);
        }
    }

    /**
     * getStaticMapBounds
     * use static map center (lat, lng), zoom and static_map_size to calculate bound box of static_map
     * @param array $center
     * @param int $zoom
     * @param array $static_map_size
     * @return void
     */
    protected function getStaticMapBounds(array $center, int $zoom, array $static_map_size)
    {
        $center_pixel = $this->LatLngToPixCoord($center[0], $center[1], $zoom);
        $x1 = $center_pixel[0] - $static_map_size[0] / 2;
        $y1 = $center_pixel[1] + $static_map_size[1] / 2;
        $x2 = $center_pixel[0] + $static_map_size[0] / 2;
        $y2 = $center_pixel[1] - $static_map_size[1] / 2;

        $latlng_nw = $this->PixCoordToLatLng($x1, $y1, $zoom);
        $latlng_se = $this->PixCoordToLatLng($x2, $y2, $zoom);
        return (object)['north' => $latlng_nw[0], 'south' => $latlng_se[0], 'east' => $latlng_nw[1], 'west' => $latlng_se[1]];
    }

    /**
     * loadIssueDataFromKMZ
     *
     * @param SimpleXMLElement $kmz
     * @return void
     */
    protected function loadPlacemarkDataFromKMZ(SimpleXMLElement $kmz)
    {
        $placemark_data = [];
        try {
            $this->getPlacemark($kmz->Document, $placemark_data);
        } catch (Exception $e) {
            throw new Exception('Failed retrieving placemarks');
        }

        return $placemark_data;
    }

    /**
     * getPlaceMark
     * recursively search the document to get all placemarks
     * @param SimpleXMLElement $content
     * @param array &$placemark_data
     * @return void
     */
    protected function getPlaceMark($content, array &$placemark_data)
    {
        foreach ($content as $key => $value) {
            if ($key == 'Placemark') {
                $place_mark = $value;
                $coordinates = explode(',', $place_mark->Point->coordinates);
                $place_mark->lng = $coordinates[0];
                $place_mark->lat = $coordinates[1];
                array_push($placemark_data, $place_mark);
            } else {
                $this->getPlaceMark($value, $placemark_data);
            }
        }
    }

    /**
     * loadStyleDataFromKMZ
     * reindex style data with its id
     * @param SimpleXMLElement $kmz
     * @return void
     */
    protected function loadStyleDataFromKMZ(SimpleXMLElement $kmz)
    {
        if (isset($kmz->Document->Style)) {
            $styles_raw = $kmz->Document->Style;
            $styles = [];

            foreach ($styles_raw as $key => $style) {
                $styles[(string)$style->attributes()['id']] = $style;
            }

            $this->styles = $styles;
        }

        if (isset($kmz->Document->StyleMap)) {
            $style_maps_raw = $kmz->Document->StyleMap;
            $styles_map = [];

            foreach ($style_maps_raw as $key => $style_map) {
                $styles_map[(string)$style_map->attributes()['id']] = [
                    (string)$style_map->Pair[0]->key => (string)$style_map->Pair[0]->styleUrl,
                    (string)$style_map->Pair[1]->key => (string)$style_map->Pair[1]->styleUrl
                ];
            }

            $this->styles_map = $styles_map;
        }
    }

    protected function printPlacemarkList(array $placemark_data, $shouldPrintLegend)
    {
        if ($placemark_data == null || count($placemark_data) == 0) {
            return;
        }
        // in case static map is not initialized, we consider first and last points to determine a fake static map center
        $this->checkStaticMapSetting($placemark_data);

        foreach ($placemark_data as $index => $a_placemark_data) {
            $placemark_style_id = str_replace('#', '', $a_placemark_data->styleUrl);
            $pos = $this->getPlacemarkLocationOnPage(floatval($a_placemark_data->lat), floatval($a_placemark_data->lng), $this->m2p_scale);
            $this->drawPoint($pos, $placemark_style_id);
        }

        if ($shouldPrintLegend) {
            $this->printLegend();
        }

    }

    /**
     * checkStaticMapSetting
     *
     * @return void
     */
    protected function checkStaticMapSetting(array $placemark_data)
    {
        if (!isset($this->m2p_scale)) {
            $on_page_width = abs($this->on_page_bounds[2] - $this->on_page_bounds[0]);
            if (isset($this->static_map_size_pixel)) {
                $this->m2p_scale = $on_page_width / $this->static_map_size_pixel[0];
            } else {
                $overlay_height = abs(
                    $this->LatLngToPixCoord($this->LatLngBox->north, $this->LatLngBox->west, $this->zoom)[1]
                        - $this->LatLngToPixCoord($this->LatLngBox->south, $this->LatLngBox->east, $this->zoom)[1]
                );
                $overlay_width = abs(
                    $this->LatLngToPixCoord($this->LatLngBox->north, $this->LatLngBox->west, $this->zoom)[0]
                        - $this->LatLngToPixCoord($this->LatLngBox->south, $this->LatLngBox->east, $this->zoom)[0]
                );
                if (abs($this->LatLngBox->rotation % 180 - 90) < 45) {
                    $this->m2p_scale = $on_page_width / $overlay_height;
                } else {
                    $this->m2p_scale = $on_page_width / $overlay_width;
                }
            }
        }

        if (!isset($this->on_page_center)) {
            $this->on_page_center = [
                ($this->on_page_bounds[0] + $this->on_page_bounds[2]) / 2,
                $this->on_page_bounds[1] + $this->static_map_size_pixel[1] * $this->m2p_scale / 2
            ];
        }
    }

    /**
     * drawPoint
     *
     * @param array $pos
     * @param string $issue_type
     * @param mixed float
     * @return void
     */
    protected function drawPoint(array $pos, string $style_id, float $scale = 1)
    {
        if (array_key_exists($style_id, $this->styles_map)) {
            $style_map = $this->styles_map[$style_id];
            $style_id = str_replace('#', '', $style_map['normal']);
            $icon_style = $this->styles[$style_id]->IconStyle;
        } else if (array_key_exists($style_id, $this->styles)) {
            $icon_style = $this->styles[$style_id]->IconStyle;
        } else {
            $icon_path = dirname(__FILE__) . '/icons/leaf.png'; // default icon
            $this->pdf_obj->Image($icon_path, $pos[0] - $scale / 2, $pos[1] - $scale / 2, $scale);
            return;
        }

        if (isset($icon_style->color)) {
            $color = $this->hex2rgb($icon_style->color)['rgb'];
        }
        $scale = $icon_style->scale;

        if ($this->is_url_exists((string)$icon_style->Icon->href)) {
            $icon_path = (string)$icon_style->Icon->href;
        } else {
            $icon_path = $this->kmz_local_folder . '/' . (string)$icon_style->Icon->href;
        }

        if ($this->is_url_exists($icon_path)) {
            $icon_path = '*' . $icon_path;
        }
        $size = (float)$scale * 5;

        // color is not applicable in google's icon, tcpdf won't fill color into the icon file, so i use a simple round with color to replace it.
        if ($this->displayColor) { 
            $this->pdf_obj->Circle($pos[0], $pos[1], $scale, 0, 360, 'F', [], $color);
        } else {
            $this->pdf_obj->Image($icon_path, $pos[0] - $size / 2, $pos[1] - $size / 2, $size, $size, '', '', '', false, 500);
        }
    }

    /**
     * printIssueLegend
     *
     * @return void
     */
    protected function printLegend()
    {
        $line = 0;
        $line_height = 5;
        $border_style = ['width' => 0.3, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => [159, 212, 239]];
        $color = [240, 240, 240];
        // $this->pdf_obj->SetAlpha(0.8);
        // $this->pdf_obj->RoundedRect($this->pdf_obj->getX() - 2, $this->pdf_obj->getY(), 45, $line_height * 7.5, 3.50, '1111', 'DF', $border_style, $color);;
        // $this->pdf_obj->SetAlpha(1);

        foreach ($this->styles as $key => $style) {
            $x = 15;
            $y = 30 + $line * $line_height;
            $this->drawPoint([$x, $y + $line_height / 2], $key, 2);
            $this->pdf_obj->setXY($x + 5, $y);
            $this->pdf_obj->Write($line_height, $key);
            $this->pdf_obj->Ln();
            $line += 1;
        }
    }

    /**
     * getPlacemarkLocationOnPage
     *
     * @param float $lat
     * @param float $lng
     * @param float $scale
     * @return void
     */
    protected function getPlacemarkLocationOnPage(float $lat, float $lng, float $scale)
    {
        $overlay_center_pixcoord = $this->LatLngToPixCoord($this->static_map_center[0], $this->static_map_center[1], $this->zoom);

        $coord = $this->LatLngToPixCoord($lat, $lng, $this->zoom);

        $xDiff = $coord[0] - $overlay_center_pixcoord[0];
        $yDiff = $coord[1] - $overlay_center_pixcoord[1];

        $x = $xDiff * $scale + $this->on_page_center[0];
        $y = $yDiff * $scale + $this->on_page_center[1];

        return [$x, $y];
    }

    /**
     * LatLngToPixCoord
     * transfer lat lng to world coordinates then to pixel coord
     * reference:
     * https://developers.google.com/maps/documentation/javascript/examples/map-coordinates
     * @param float $lat
     * @param float $lng
     * @param int $zoom
     * @return void
     */
    protected function LatLngToPixCoord(float $lat, float $lng, int $zoom)
    {
        $scale = 1 << $zoom;

        $siny = sin($lat * M_PI / 180);
        $siny = max(min($siny, 0.9999), -0.9999);

        $wcx = self::TILE_SIZE * (0.5 + $lng / 360);
        $wcy = self::TILE_SIZE * (0.5 - log((1 + $siny) / (1 - $siny)) / (4 * M_PI));

        return [$wcx * $scale, $wcy * $scale];
    }

    /**
     * PixCoordToLatLng
     *
     * @param int $pcx
     * @param int $pcy
     * @param int $zoom
     * @return void
     */
    protected function PixCoordToLatLng(int $pcx, int $pcy, int $zoom)
    {
        $wcx = $pcx / pow(2, $zoom);
        $wcy = $pcy / pow(2, $zoom);

        $siny = 1 - (2 / (1 + pow(M_E, 4 * M_PI * (0.5 - $wcy / self::TILE_SIZE))));
        $lat = asin($siny) * 180 / M_PI;
        $lng = ($wcx / self::TILE_SIZE - 0.5) * 360;

        return [$lat, $lng];
    }

    /**
     * constructURL
     * Generate google static map url
     * @param array $center
     * @param array $size
     * @param int $zoom
     * @return void
     */
    protected function constructURL(array $center, array $size, int $zoom, string $map_type)
    {
        $url = 'http://maps.googleapis.com/maps/api/staticmap?maptype=' . $map_type . '&scale=2';
        $center = $center[0] . ',' . $center[1];
        $size = $size[0] . 'x' . $size[1];
        $zoom = $zoom;
        $language = 'en';
        $url .= '&zoom=' . $zoom . '&center=' . $center . '&size=' . $size . '&language=' . $language;
        $url .= '&key=' . $this->google_api_key;
        return $url;
    }


    /**
     * getMapZoom
     *
     * @param stdClass $LatLngBox
     * @return void
     */
    protected function getMapZoom(stdClass $LatLngBox)
    {
        $zoom = 0;
        for ($i = 0; $i += 1; $i < 21) {
            $pix_coord_nw = $this->LatLngToPixCoord($LatLngBox->north, $LatLngBox->west, $i);
            $pix_coord_se = $this->LatLngToPixCoord($LatLngBox->south, $LatLngBox->east, $i);
            $lat_pix_diff = abs($pix_coord_nw[0] - $pix_coord_se[0]);
            $lng_pix_diff = abs($pix_coord_nw[1] - $pix_coord_se[1]);
            if ($lat_pix_diff >= 600 || $lng_pix_diff >= 600) {
                $zoom = $i - 1;
                break;
            }
        }
        return $zoom;
    }

    /**
     * getMapCenter
     *
     * @param mixed $LatLngBox
     * @param array $placemark_data
     * @return array
     */
    protected function getMapCenter($LatLngBox, array $placemark_data)
    {
        if ($LatLngBox == null) {
            if ($placemark_data == null || count($placemark_data) == 0) {
                throw \Exception('No placemark or overlay to render');
            } else {
                $this->LatLngBox = $this->getLatLngBoxFromPlacemark($placemark_data);
                $clat = ($this->LatLngBox->north + $this->LatLngBox->south) / 2;
                $clng = ($this->LatLngBox->east + $this->LatLngBox->west) / 2;
            }
        } else {
            $clat = ((float)$LatLngBox->south + (float)$LatLngBox->north) / 2;
            $clng = ((float)$LatLngBox->east + (float)$LatLngBox->west) / 2;
        }

        return [$clat, $clng];
    }

    /**
     * getLatLngBoxFromPlacemark
     * get latlngbox from placemark data in case it is null
     * @param array $placemark_data
     * @return object
     */
    private function getLatLngBoxFromPlacemark(array $placemark_data)
    {
        $minLat = $maxLat = floatval($placemark_data[0]->lat);
        $minLng = $maxLng = floatval($placemark_data[0]->lng);

        foreach ($placemark_data as $place_mark) {
            $minLat = min(floatval($place_mark->lat), $minLat);
            $maxLat = max(floatval($place_mark->lat), $maxLat);
            $minLng = min(floatval($place_mark->lng), $minLng);
            $maxLng = max(floatval($place_mark->lng), $maxLng);
        }

        $LatLngBox = new \stdClass();
        $LatLngBox->north = $maxLat;
        $LatLngBox->south = $minLat;
        $LatLngBox->east = $maxLng;
        $LatLngBox->west = $minLng;
        $LatLngBox->rotation = 0;

        return $LatLngBox;
    }

    /**
     * calculateMapSize
     *
     * @param stdClass $LatLngBox
     * @param int $zoom
     * @return void
     */
    protected function calculateMapSize(stdClass $LatLngBox, int $zoom)
    {
        [$lngDistance, $latDistance] = $this->getRotatedSize($LatLngBox, $zoom);
        $percentage = max($latDistance, $lngDistance) / 640;
        $rate = $lngDistance / $latDistance; // width height rate

        if ($rate < 1) {
            $height = $percentage > 0.8 ? 640 : $latDistance * 1.2;
            $width = (int)($height * $rate);
        } else {
            $width = $percentage > 0.8 ? 640 : $lngDistance * 1.2;
            $height = (int)($width / $rate);
        }

        // if the overlay is flopped, we need to exchange width and height
        if (abs($this->LatLngBox->rotation % 180 - 90) < 45) {
            return [floor($height), floor($width)];
        } else {
            return [floor($width), floor($height)];
        }
    }

    protected function getRotatedSize($LatLngBox, int $zoom) {
        $pix_coord_nw = $this->LatLngToPixCoord($LatLngBox->north, $LatLngBox->west, $zoom);
        $pix_coord_se = $this->LatLngToPixCoord($LatLngBox->south, $LatLngBox->east, $zoom);
        $diagArc = atan(($pix_coord_nw[1] - $pix_coord_se[1]) / ($pix_coord_nw[0] - $pix_coord_se[0]));
        $diagLen = abs($pix_coord_nw[0] - $pix_coord_se[0]) / cos($diagArc);
        $lngDistance = $diagLen * cos($diagArc - floatval($LatLngBox->rotation) / 180 * M_PI);
        $latDistance = $diagLen * sin($diagArc - floatval($LatLngBox->rotation) / 180 * M_PI);
        return [$lngDistance, $latDistance];
    }

    public function end()
    {
        unset($this->pdf_obj);
        unset($this->kmz_obj);
        unset($this->on_page_bounds);
        unset($this->LatLngBox);
        unset($this->static_map_size_pixel);
        unset($this->static_map_center);
        unset($this->static_map_url);
        unset($this->static_map_path);
        unset($this->static_map);
        unset($this->overlay_path);
        unset($this->overlay);
        unset($this->zoom);
        unset($this->m2p_scale);
        unset($this->file_id);

        $delete_success = $this->rrmdir($this->kmz_local_folder);
        unset($this->kmz_local_folder);
    }

    public function is_url_exists($url)
    {
        set_error_handler([$this, "warning_handler"], E_WARNING);
        try {
            $headers = get_headers($url);
        } catch (Exception $e) {
            restore_error_handler();
            return false;
        }
        return stripos($headers[0], "200 OK") ? true : false;
    }

    function warning_handler($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            // This error code is not included in error_reporting
            return;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    protected function rrmdir($src)
    {
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $full = $src . '/' . $file;
                if (is_dir($full)) {
                    $this->rrmdir($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }

    /**
     * hex2rgb
     * kml color format eg. ffb0279c, first two digits represents alpha
     * @param string $hex
     * @return void
     */
    protected function hex2rgb(string $hex)
    {
        $hex = str_replace("#", "", $hex);
        if (strlen($hex) == 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $a = 1;
        } else if (strlen($hex) == 8) {
            $r = hexdec(substr($hex, 2, 2));
            $g = hexdec(substr($hex, 4, 2));
            $b = hexdec(substr($hex, 6, 2));
            $a = hexdec(substr($hex, 0, 2));
        }

        $rgb = [$r, $g, $b];
        return ['rgb' => $rgb, 'a' => $a];
    }
}

?>
