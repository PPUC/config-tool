<?php
// port => GPIO
//
// Derived from Hardware_Opto_16/Opto_16/Opto_16.kicad_sch by tracing the
// RP2040 pins through the schematic net graph. Do NOT take these from
// Opto_16.xml: that netlist is a stale copy of Out_8x10's (same source path,
// same export date, same component count) and was never regenerated after
// this board was forked.
//
// This board carries opto-isolated inputs only. The schematic has no Out_1..8
// nets at all, so there is no 17..24 block here - unlike IO_16_8_1 and
// IO_16x8_matrix. GPIO19..27 are simply not brought out.
$mapping = [
  // Opto-isolated Input (In_1 .. In_16)
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
  // Special Output
  25 => 29,
];

// RS485 TX => 0
// RS485 RX => 1
// RS485 DE => 2
// Onboard LED => 25
// V Address => 28

var_dump(serialize($mapping));
