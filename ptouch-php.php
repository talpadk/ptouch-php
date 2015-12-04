<?php

function analyseStatus($buffer){
  print "== Status ==\n";
  print "media width: ".unpack("C", $buffer[10])[1]."\n";
  print "media type: ".unpack("C", $buffer[11])[1]."\n";
  print "status: ".unpack("C", $buffer[18])[1]."\n";
  print "error: ";
  print unpack("C", $buffer[8])[1].",";
  print unpack("C", $buffer[9])[1]."\n";
}

function getStatus($deviceHandle){
  $data = str_pad ("", 100, pack("x"));
  $data .= pack("CC", 0x1B, 0x40);
  
  $data .= pack("CCC", 0x1B, 0x69, 0x53);
  $result = usb_bulk_transfer($deviceHandle, 0x02, $data, strlen($data), $txLength, 2*1000);
  if ($result == USB_SUCCESS){
    $buffer = str_pad ("", 32, pack("C", 1));
    $result = usb_bulk_transfer($deviceHandle, 0x81, $buffer, 32, $txLength, 2*1000);
    if ($result == USB_SUCCESS){
      print "Got status\n";
      analyseStatus($buffer);
    }
    else {
      $errorName = usb_error_name($result);
      print "Read failed $errorName ($result)\n";
    }
  }
  else {
    $errorName = usb_error_name($result);
    print "Write failed $errorName ($result)\n";
  }
}

function gfxTest($deviceHandle){
  $data = "";
  $data = str_pad ("", 100, pack("x"));
  $data .= pack("CC", 0x1B, 0x40); //reset

  $data .= pack("CCCC", 0x1B, 0x69, 0x61, 1); //raster mode

  $data .= pack("CCCCCCCCCCCCC", 0x1B, 0x69, 0x7A, 0x0, 1,0x18,0, 64,0,0,0,0,0); //print on 24mm

  $data .= pack("CCCC", 0x1B, 0x69, 0x4D, 64); //auto cut

  $data .= pack("CCCC", 0x1B, 0x69, 0x4B, 1<<3); //Special setting, no chain print,

  $data .= pack("CCCCC", 0x1B, 0x69, 0x64, 55,0); //magin 15 dots (Minimum length is 174 dots)
  
  $data .= pack("CC", 0x4D, 0); //no compression

  // 
  //  $data .= pack("CCC", 0x47, 0xf0,1); //raster gfx 31x128 pixels = 0x1F0 bytes (31 lines of 16 bytes)

  for ($i=0; $i<64; $i++){
    $data .= pack("CCC", 0x47, 16,0); //raster gfx 31x128 pixels = 0x1F0 bytes (31 lines of 16 bytes)
    $data .= str_pad ("", 16, pack("C", 0xaa));
  }

  $data .= pack("C", 0x1A); //print

  $result = usb_bulk_transfer($deviceHandle, 0x02, $data, strlen($data), $txLength, 5*1000);
  if ($result == USB_SUCCESS){
    $len =strlen($data);
      print "Sendt $len print\n";
  }
  else {
    $errorName = usb_error_name($result);
    print "Print failed $errorName ($result)\n";
  }

  
  $buffer = str_pad ("", 32, pack("C", 1));
  $result = usb_bulk_transfer($deviceHandle, 0x81, $buffer, 32, $txLength, 2*1000);
  if ($result == USB_SUCCESS){
    analyseStatus($buffer);
  }
}


$context = null;
$result_init = usb_init($context);
if ($result_init != USB_SUCCESS) {
  die('failed to usb_init(). ' . usb_error_name($result_init));
}
$device_resources = array();
$result_devices = usb_get_device_list($context, $device_resources);
if ($result_devices < 0) {
die('failed to usb_get_device_list(). ' . usb_error_name($result_devices));
}
foreach ($device_resources as $device) {
  $device_descriptor;
  $config_descriptor;
  usb_get_device_descriptor($device, $device_descriptor);
  if ($device_descriptor->idVendor==0x04F9){
/*var_dump($device_descriptor);
    usb_get_config_descriptor($device, $device_descriptor->bNumConfigurations - 1, $config_descriptor);
    var_dump($config_descriptor);*/

    $result = usb_open($device, $deviceHandle);
    if ($result == USB_SUCCESS){
      print "=== STARTING Claim ===\n";
      usb_detach_kernel_driver($deviceHandle, 0);
      $result = usb_claim_interface($deviceHandle, 0);
      if ($result == USB_SUCCESS){

getStatus($deviceHandle);
gfxTest($deviceHandle);
sleep(3);
        usb_release_interface($deviceHandle, 0);
      }
      else {
         $errorName = usb_error_name($result);
         print "Claim failed $errorName ($result)\n";
      }
      usb_attach_kernel_driver($deviceHandle,0);
      usb_close($deviceHandle);
    }
    else {
      $errorName = usb_error_name($result);
      print "Open failed $errorName ($result)\n";
    }
  }
}
usb_free_device_list($device_resources);
usb_exit($context);
?>