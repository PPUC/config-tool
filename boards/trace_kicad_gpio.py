"""Trace RP2040 GPIO -> net label from a KiCad .kicad_sch, by geometry.

    python3 trace_kicad_gpio.py path/to/Board.kicad_sch

Why this exists rather than reading the exported .xml netlist: the netlists
shipped in Hardware_IO_16x8_matrix and Hardware_Opto_16 are stale copies of
Out_8x10's - identical <source> path, export date and component count - so
they describe the wrong board entirely. The .kicad_sch files are current.

How it works, so the result can be checked rather than trusted: wire segments
are unioned into nets, a label names whichever net its anchor point sits on,
and each MCU pin attaches at its connection point transformed into sheet
coordinates. Nothing is inferred from pin ordering or naming.

Validated against IO_16_8_1, where the answer is known independently from
both the (valid) exported netlist and the committed IO_16_8_1.php: the tracer
reproduces it exactly, including the GPIO25 gap for the on-board LED and
Out_Special on GPIO29.

Only symbol rotation 0 is implemented; it asserts rather than guessing if it
meets anything else.
"""
import re, sys, math
from collections import defaultdict

def sexp_tokens(s):
    return s

def get_lib_pins(src, libname_re=r'RP2040'):
    """pin number -> (name, x, y) in symbol coords."""
    # locate the lib_symbols entry for the MCU
    m = re.search(r'\(symbol "([^"]*'+libname_re+r'[^"]*)"', src)
    if not m: return {}
    start = m.start()
    # crude but adequate: scan balanced parens from here
    depth, i = 0, start
    while i < len(src):
        if src[i] == '(': depth += 1
        elif src[i] == ')':
            depth -= 1
            if depth == 0: break
        i += 1
    block = src[start:i+1]
    pins = {}
    for pm in re.finditer(
        r'\(pin\s+\S+\s+\S+\s*\(at ([-\d.]+) ([-\d.]+) ([-\d.]+)\)\s*\(length [-\d.]+\)\s*'
        r'\(name "([^"]*)".*?\(number "([^"]*)"', block, re.S):
        x, y, ang, name, num = pm.groups()
        pins[num] = (name, float(x), float(y))
    return pins

def get_instance(src, libname_re=r'RP2040'):
    m = re.search(r'\(symbol\s*\(lib_id "([^"]*'+libname_re+r'[^"]*)"\)\s*\(at ([-\d.]+) ([-\d.]+) ([-\d.]+)\)', src)
    if not m: return None
    return float(m.group(2)), float(m.group(3)), float(m.group(4))

def key(x, y): return (round(x, 2), round(y, 2))

class DSU:
    def __init__(self): self.p = {}
    def find(self, a):
        self.p.setdefault(a, a)
        while self.p[a] != a:
            self.p[a] = self.p[self.p[a]]; a = self.p[a]
        return a
    def union(self, a, b):
        ra, rb = self.find(a), self.find(b)
        if ra != rb: self.p[ra] = rb

def trace(path):
    src = open(path).read()
    pins = get_lib_pins(src)
    inst = get_instance(src)
    if not inst: return {}
    ix, iy, rot = inst
    assert rot == 0, f"unhandled symbol rotation {rot} in {path}"

    dsu = DSU()
    # wires
    for wm in re.finditer(r'\(wire\s*\(pts\s*\(xy ([-\d.]+) ([-\d.]+)\)\s*\(xy ([-\d.]+) ([-\d.]+)\)\)', src):
        x1, y1, x2, y2 = map(float, wm.groups())
        dsu.union(key(x1, y1), key(x2, y2))

    # labels (local, global, hierarchical) name a point
    names = defaultdict(set)
    for lm in re.finditer(r'\((?:label|global_label|hierarchical_label) "([^"]+)"\s*\(at ([-\d.]+) ([-\d.]+)', src):
        nm, x, y = lm.group(1), float(lm.group(2)), float(lm.group(3))
        names[dsu.find(key(x, y))].add(nm)

    # MCU pin connection points (rot 0: y is mirrored)
    out = {}
    for num, (pname, px, py) in pins.items():
        k = dsu.find(key(ix + px, iy - py))
        got = names.get(k, set())
        if got:
            out[pname] = sorted(got)
    return out

if __name__ == '__main__':
    res = trace(sys.argv[1])
    for pname in sorted(res, key=lambda n: (0, int(re.search(r'\d+', n).group())) if re.match(r'GPIO\d', n) else (1, 0)):
        if pname.startswith('GPIO'):
            print(f"  {pname:<12} {','.join(res[pname])}")
