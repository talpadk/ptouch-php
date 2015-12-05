<?php

class PtouchPrinter {
  private $usbContext;
  private $usbDeviceList;
  private $errorString;
  private $restoreKernelDriver;

  ///True if $restoreKernelDriver was true and we succeeded in detatching a kernel driver
  private $doRestoreKernelDriver;

  private $dataBuffer;

  //Bit buffering
  private $bitByte;
  private $bitPos;
  
  //Print settings
  private $autoCut;
  private $chainPrinting;
  /** 
   * Constructs a new Ptouch printer instance
   * 
   * @param restoreKernelDriver bool, if true the kernel LPR driver will be reattached when the driver is closed
   * @see open
   */
  public function __construct($restoreKernelDriver = false){
    $this->usbContext = null;
    $this->usbDeviceList = null;
    $this->usbDeviceHandle = null;
    $this->errorString = null;
    $this->restoreKernelDriver = $restoreKernelDriver;
    $this->doRestoreKernelDriver = false;

    $this->autoCut = true;
    $this->chainPrinting = false;
  }

  private function addUsbError($message, $usbResult){
    $errorName = usb_error_name($usbResult);
    $this->errorString .= "$message $errorName ($usbResult)\n";
  }

  private function cmdInvalidAndReset(){
    $this->dataBuffer .= str_pad ("", 100, pack("x"));
    $this->dataBuffer .= pack("CC", 0x1B, 0x40); //reset
  }

  private function cmdRasterMode(){
    $this->dataBuffer .= pack("CCCC", 0x1B, 0x69, 0x61, 1); //raster mode
  }

  private function cmdPrintInformation($length){
    $length = (int)$length;
    $n8 = ($length >> 24) & 0xff;
    $n7 = ($length >> 16) & 0xff;
    $n6 = ($length >> 8)  & 0xff;
    $n5 = ($length)       & 0xff;
    $this->dataBuffer .= pack("CCCCCCCCCCCCC", 0x1B, 0x69, 0x7A, 0x0, 1,0x18,0, $n5,$n6,$n7,$n8,0,0);
  }

  private function cmdModeSettings(){
    $mode = 0;
    if ($this->autoCut) { $mode |= 1<<6; }
    $this->dataBuffer .= pack("CCCC", 0x1B, 0x69, 0x4D, 64);
  }

  private function cmdAdvancedModeSettings(){
    $mode = 0;
    if (!$this->chainPrinting) { $mode |= 1<<3; }
    $this->dataBuffer .= pack("CCCC", 0x1B, 0x69, 0x4B, $mode);
  }

  private function cmdMargin($margin){
    $margin = (int)$margin;
    $n2 = ($margin >> 8) & 0xff;
    $n1 = ($margin)      & 0xff;
    $this->dataBuffer .= pack("CCCCC", 0x1B, 0x69, 0x64, $n1,$n2);
  }

  private function cmdSetCompression($tiffCompression){
    $compression = 0;
    if ($tiffCompression) { $compression = 2; }
    $this->dataBuffer .=pack("CC", 0x4D, $compression);
  }

  private function pushBitInit(){
    $this->bitByte = 0;
    $this->bitPos  = 7;
  }
  
  private function pushBit($bit){
    if ($bit) { $bit = 1<<$this->bitPos; }
    else      { $bit = 0; }
    $this->bitByte |= $bit;
    $this->bitPos--;
    if ($this->bitPos<0){
      $this->dataBuffer .= pack("C", $this->bitByte);
      $this->pushBitInit();
    }
  }
  
  public function printImage($gdImage){
    $imageLength = imagesx($gdImage);
    $imageHeight = imagesy($gdImage);
    print "$imageLength x $imageHeight\n";

    
    $this->dataBuffer = "";
    $this->cmdInvalidAndReset();
    $this->cmdRasterMode();
    $this->cmdPrintInformation($imageLength);
    $this->cmdModeSettings();
    $this->cmdAdvancedModeSettings();
    $this->cmdMargin(15);
    $this->cmdSetCompression(false);

    $this->pushBitInit();
    for ($x=0; $x<$imageLength; $x++){
      $this->dataBuffer .= pack("CCC", 0x47, 16,0); //new raster line
      for ($y=0; $y<128; $y++){
        $colours = imagecolorsforindex($gdImage, imagecolorat($gdImage, $x, $y));
        $this->pushBit($colours['red'] < 128);
      }
    }
    $this->dataBuffer .= pack("C", 0x1A); //print
    
    usb_bulk_transfer($this->usbDeviceHandle, 0x02, $this->dataBuffer, strlen($this->dataBuffer), $txLength, 30*1000);
  } 
  
  private function findPrinterDevice(){
    $usbDevice = null;
    $this->usbDeviceList = array();
    usb_get_device_list($this->usbContext, $this->usbDeviceList);
    if (count($this->usbDeviceList)<=0){
      $this->errorString .= "No USB devices\n";
      $this->usbDeviceList = null;
    }
    else {
      $deviceDescriptor = null;
      foreach ($this->usbDeviceList as $device) {
        usb_get_device_descriptor($device, $deviceDescriptor);
        if ($deviceDescriptor->idVendor == 0x04F9 && (
            $deviceDescriptor->idProduct == 0x2061 ||
            $deviceDescriptor->idProduct == 0x205F ||
            $deviceDescriptor->idProduct == 0x205E)){
          ///@todo implement a way to select a specific printer, for now the first we find will do.
          //iSerialNumber is a good candidate.
          $usbDevice = $device;
          break;
        }
      }
    }
    return $usbDevice;
  }

  /** 
   * Opens the printer and claims the UBS resources required.
   * This must be done prior to printing!
   *
   * @note Also call close if opening fails, just don't attempt to print.
   * @see close
   * 
   * @return returns an error string on failure, null on success.
   */
  public function open(){
    $this->errorString = null;
    $result = usb_init($this->usbContext);
    if ($result != USB_SUCCESS){
      $this->addUsbError("Failed to open libUSB", $result);
      $this->usbContext = null;
    }
    else {
      $device = $this->findPrinterDevice();
      if (is_null($device)){
        $this->errorString .= "No suitable printer was found\n";
      }
      else {
        $result = usb_open($device, $this->usbDeviceHandle);
        if ($result != USB_SUCCESS){
          $this->addUsbError("Unable to open USB device", $result);
          $this->usbDeviceHandle = null;
        }
        else {
          //No error notification as it may bo okay if it fails
          $result = usb_detach_kernel_driver($this->usbDeviceHandle, 0);
          if ($result == USB_SUCCESS){
            $this->doRestoreKernelDriver = $this->restoreKernelDriver;
          }
          else {
            $this->doRestoreKernelDriver = false;
          }
          $result = usb_claim_interface($this->usbDeviceHandle, 0);
          if ($result != USB_SUCCESS){
            $this->addUsbError("Unable to claim USB device", $result);
            if ($this->doRestoreKernelDriver){
              usb_attach_kernel_driver($deviceHandle,0);
              $this->doRestoreKernelDriver=false;
            }
            usb_close($this->usbDeviceHandle);
            $this->usbDeviceHandle = null;
          }
        }
      }
      
    }
    return $this->errorString;
  }

  /** 
   * Closes the printer and releases the USB resources
   * Call this when you are done printing.
   * 
   */
  public function close(){

    if (!is_null($this->usbDeviceHandle)){
      usb_release_interface($this->usbDeviceHandle, 0);
    }

    if ($this->doRestoreKernelDriver){
      usb_attach_kernel_driver($deviceHandle,0);
      $this->doRestoreKernelDriver=false;
    }
    
    if (!is_null($this->usbDeviceHandle)){
      usb_close($this->usbDeviceHandle);
      $this->usbDeviceHandle = null;
    }
    
    if (!is_null($this->usbDeviceList)){
      usb_free_device_list($this->usbDeviceList);
      $this->usbDeviceList = null;
    }
    
    if (!is_null($this->usbContext)){
      usb_exit($this->usbContext);
      $this->usbContext = null;
    }
  }
}
  
?>