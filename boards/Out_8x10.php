<?php
// port => GPIO
$mapping = [
  // Low
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
  // High Power Output
  11 => 24,
  12 => 23,
  13 => 22,
  14 => 21,
  15 => 20,
  16 => 19,
  17 => 18,
  18 => 17,
  // Test Points
  19 => 26, // TP1
  20 => 26, // TP2
  21 => 15, // TP3
  22 => 14, // TP6
  23 => 13, // TP7
  24 => 16, // TP8
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
