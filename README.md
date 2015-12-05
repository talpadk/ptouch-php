#A php class for printing out labels on Brother P-touch printers
###Supported models include (PT-P700, PT-H500, PT-E500)

The code is being developed / tested using a PT-P700, but the protocol should be the same on all three models.

The php class is intended to be used as part of a web page allowing it to print labels on a printer connected to the web server.   
However the code also includes some php scripts to allow printing from the command line.


This is **NOT A CUPS DRIVER** nor is cups or any other printer systems required to print.

#### Requirements
* PHP 
* libusb module for PHP see: [php-usb](http://sandbox.n-3.so/php-usb/) for details on installing it
* GD module 

*sudo apt-get install php5 php5-gd*

Note:   
The user running the command/script must have permission to use the USB device.   
Please consider looking into using  [udev](https://www.linux.com/news/hardware/peripherals/180950-udev) to grant you permission to use the printer.  
Alternatively you can test the script using 'sudo'.

#### Contents
* ptouch-utils/PtouchPrinter.php The printer class file
* ptouch-utils/ptouch-img A script that prints an image on a label
* test/test.png A test image that fit on 24mm labels

##### PtouchPrinter.php
A class for printing GD images, see the code for doxygen comments explaining how to use it



##### ptouch-img
Usage: ptouch-img path_to_file

The image file may be a .png .jpg or .gif it must currently be 128 pixels high.   
The red value of the pixels are thresholded in order to determine if a black pixel should be printet 