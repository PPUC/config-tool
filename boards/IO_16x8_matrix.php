<?php
// port => GPIO
//
// Derived from Hardware_IO_16x8_matrix/IO_16x8_matrix/IO_16x8_matrix.kicad_sch
// by tracing the RP2040 pins through the schematic net graph. Do NOT take
// these from IO_16x8_matrix.xml: that netlist is a stale copy of Out_8x10's
// (same source path, same export date, same component count) and was never
// regenerated after this board was forked.
//
// NOTE the output order. On IO_16_8_1 the high power outputs ascend
// (Out_1 => GPIO19 ... Out_8 => GPIO27). On this board they run the other way
// (Out_1 => GPIO27 ... Out_8 => GPIO19). Copying the IO_16_8_1 block here
// would drive the wrong device on every one of the eight outputs.
$mapping = [
  // Input (In_1 .. In_16)
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
  // Signal Output (Out_1 .. Out_8) - descending GPIO, see note above
  17 => 27,
  18 => 26,
  19 => 24,
  20 => 23,
  21 => 22,
  22 => 21,
  23 => 20,
  24 => 19,
  // Special Output
  25 => 29,
];

// RS485 TX => 0
// RS485 RX => 1
// RS485 DE => 2
// Onboard LED => 25
// V Address => 28

var_dump(serialize($mapping));
