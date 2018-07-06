# TCPDF-easyKMZ

This library is built to render kmz and kml file into pdf file. Originally kmz and kml file could be read through google map and google earth pro. When I tried to print a map with overlay and points onto a pdf page, I can only take a screenshot and insert it into pdf as an image. It becomes obscure when you zoom in and you cannot remove default layer of google map out of that image. So I decide to write a little tool for parsing and rendering geo-info on pdf page like an overlay.

Firstly I choose to implemented basic placemark, then my most-in-demand feature Ground Overlay. More features like lines will be done in future. And help from more advanced pdf or geo coder is welcomed.

## Prerequisites

You will have to install php and a webserver in your computer, which in my case XAMPP. And you have to install tcpdf in your directory. I simple put the tcpdf php source code in my directory and import it in my php script:

```php
require_once(dirname(__FILE__).'/tcpdf/tcpdf.php');
```

And to use easyKMZ, simply put easyKMZ under the same directory and require it in your php script:

```php
require_once(dirname(FILE).'/easyKMZ.php'); 
```

You also have to have a google map api key for basic map.

## Usage

Your will create an instance TCPDF and of easyKMZ.  Configurable parameters are as follows:

```php
$map = new easyKMZ($pdf, 'path/to/KMZ.kmz', 'google map api key', 'map type', 'bounds', 'whether to display color',  'file id');

```

- $pdf: tcpdf instance you just created;
- $path to kmz file;
- $bound is an array which specifies the coordinates of the area that you want to put the map in;
- $displayColor actually is an issue to be fixed, I can't fill color to some icon as google does to some of their icons, so I use colored circles instead temporarily. If you desire colored placemark, set it to true;
- I use a google map api key to get a base map (in case you need it), right now I use google map as my basic layer. if you have other source, you can modify the code to change the basic map source.
- File ID is for kmz temp folder. I use a temp folder(which will be deleted after creating pdf) to store extracted kmz, and if you want to name extracted file specifically, set the id yourself.

Then add page, set bounds and print map, end printing. You can specify layers when printing:

```php
$map->print('OP');
```

Here *O* stands for overlay, *P* stands for placemarks, you can also add *M* for basic map and *L* for legends.

## Example

Examples can be found in KMZ-sample folder

## License

TCPDF-easyKMZ is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).  

  

