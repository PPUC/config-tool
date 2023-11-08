<?php
// port => GPIO
$mapping = [
  // Input / Low Power Output
  1 => 3,
  2 => 4,
  3 => 5,
  4 => 6,
  5 => 7,
  6 => 8,
  7 => 9,
  8 => 10,
  9 => 11,
  10 => 12,
  11 => 13,
  12 => 14,
  13 => 15,
  14 => 16,
  15 => 17,
  16 => 18,
  // High Power Output
  17 => 19,
  18 => 20,
  19 => 21,
  20 => 22,
  21 => 23,
  22 => 24,
  23 => 26,
  24 => 27,
  // Special Output
  25 => 29,
];

// RS485 TX => 0
// RS485 RX => 1
// RS485 DE => 2
// Onboard LED => 25
// V Address => 28

var_dump(serialize($mapping));
