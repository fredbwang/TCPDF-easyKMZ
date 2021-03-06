<?php

require_once(dirname(__FILE__).'/tcpdf/tcpdf.php');

require_once(dirname(__FILE__).'/easyKMZ.php');

$pdf = new \TCPDF();

$map = new easyKMZ($pdf, null,  dirname(__FILE__) . 'KMZ-sample/KMZ_3.kmz', 'Your google map api key', 'satellite');

$pdf->addPage();
$map->setBounds(30, 20, 120, 320);
$map->print('OP');
$map->end();

$pdf->Output('I');