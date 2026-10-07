<?php
// port => GPIO
//
// Checked against Hardware_Out_8x10/Out_8x10/Out_8x10.kicad_sch with
// trace_kicad_gpio.py. Both groups run downwards: Lo_1 is GPIO12, Hi_1 GPIO24.
$mapping = [
  // Low side switches (Lo_1 .. Lo_10)
  1 => 12,
  2 => 11,
  3 => 10,
  4 => 9,
  5 => 8,
  6 => 7,
  7 => 6,
  8 => 5,
  9 => 4,
  10 => 3,
  // High side switches (Hi_1 .. Hi_8)
  11 => 24,
  12 => 23,
  13 => 22,
  14 => 21,
  15 => 20,
  16 => 19,
  17 => 18,
  18 => 17,
  // Pins 19-24 used to map the test points TP1-TP8 here. They are not
  // outputs - the schematic connects nothing to them - and two of them were
  // given the same GPIO.
  // Special Output
  25 => 29,
];

// RS485 TX => 0
// RS485 RX => 1
// RS485 DE => 2
// Onboard LED / TP4 => 25
// V Address => 28
// TP5 => GND

var_dump(serialize($mapping));
