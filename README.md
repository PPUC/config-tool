# PPUC config-tool

The PPUC config-tool is a web application to configure your controllers. it is
still WIP.

## Installation

The PPUC config-tool is a web appication based on Drupal, written in PHP.
So it needs a webserver to run.

To run a local instance, a docker image is available:
```sh
docker pull ghcr.io/ppuc/config-tool:latest
docker run -p 8080:80 -v config-tool-data:/var/www/web/config-tool-data ghcr.io/ppuc/config-tool:latest
```

Then open `localhost:8080`in a web browser and login as user `ppuc` using the password `ppuc`.

## Creating a game from its manual

Setting up a machine by hand means creating every switch, coil and lamp one
node at a time - about 150 of them for a 1990s game, each needing a number, a
board and a pin. All of that is already written down in three tables in the
operator manual: the switch matrix, the lamp matrix and the solenoid/flashlamp
table.

**Create Game from Manual** (`/game/wizard`) takes those tables as a JSON
document and builds the game, its I/O boards, switches, PWM devices and LED
stripes. It shows the board allocation before creating anything.

The JSON is the input format on purpose, rather than the manual pages
themselves. It is a contract: the pages can be transcribed by hand, extracted
by an AI, or produced by another tool entirely, and what the wizard does with
the result is the same either way.

### Numbers come from the manual

Switches, coils and lamps use the numbers printed in the manual - a matrix
switch is column x 10 + row, a coil is its solenoid number, a lamp is its lamp
matrix number. The wizard does not renumber anything.

Switches *outside* the matrix are the exception, because the ROM only reads
them at numbers the platform defines. For WPC:

| Switch | Number |
|---|---|
| Coin chutes 1-4 | 1, 2, 3, 4 |
| Service credit / escape | 5 |
| Volume down, volume up | 6, 7 |
| Begin test | 8 |
| Flipper buttons (Fliptronic) | 112 lower right, 114 lower left, 116 upper right, 118 upper left |
| Flipper EOS | 200 upwards - PinMAME does not read these |

A platform the wizard has no table for is refused rather than given WPC's
numbers: a switch on a number nothing polls behaves exactly like a broken one.

### Format

```json
{
  "game":     { "title": "Dirty Harry", "platform": "WPC", "rom": "dh_lx2" },
  "switches": [
    { "number": 11, "description": "Gun Handle Trigger" },
    { "number": 31, "description": "Trough Jam", "opto": true },
    { "number": 24, "description": "Plumb Bob Tilt", "location": "cabinet" },
    { "number": 5,  "description": "Service Credit/Escape", "direct": true }
  ],
  "coils":    [
    { "number": 1, "description": "Ball Release", "class": "highPower" },
    { "number": 7, "description": "Knocker", "class": "highPower", "location": "backbox" },
    { "number": 9, "description": "Left Sling", "class": "lowPower", "fastFlipSwitch": 61 },
    { "number": 20, "description": "Gun Motor", "class": "lowPower", "type": "motor" }
  ],
  "flippers": [
    { "name": "Lower Right", "position": "lowerRight", "powerCoil": 29, "holdCoil": 30 }
  ],
  "flashers": [ { "number": 17, "description": "Headquarters" } ],
  "lamps":    [ { "number": 11, "description": "Left Rollover" } ],
  "gi":       [ { "number": 1,  "description": "Right String" } ]
}
```

| Key | Meaning |
|---|---|
| `class` | The manual's SOLENOID TYPE column: `highPower`, `lowPower` or `genPurpose`. It sets the drive power, so it is required and never guessed. |
| `type` | PWM device type: `coil` (default), `lamp`, `motor`, `shaker`. Not `flasher` - a flasher is an LED. |
| `opto` | Puts the switch on an `Opto_16` board. |
| `direct` | A D-column switch. Its number must be one the platform defines. |
| `location` | `playfield` (default), `cabinet` or `backbox`. Backbox devices share the cabinet board. |
| `fastFlipSwitch` | The switch this coil reacts to locally. The wizard puts both on one board. |
| `position` | Which flipper this is, which selects the button number. |

Rows the manual marks "Not Used" are simply left out. The flipper column beside
the switch matrix is a generic Fliptronic template - on Dirty Harry it lists an
upper left flipper the game does not have - so the wizard ignores it and takes
the flippers from the solenoid table instead.

### What it decides for you

Coil power comes from the solenoid type: High Power 255, Low Power and
Gen. Purpose 128. Every coil gets a maximum pulse time, so a wizard-built game
loads without unprotected-coil warnings. The exception is a flipper hold
winding, which is wound to sit energised and is marked `holdWinding` instead -
bounding one would drop the flipper mid-game.

Boards are allocated automatically under three rules:

1. A coil with a fast-flip switch goes on the same board as that switch. The
   board only reacts without waiting for the host when it owns both, which is
   why **flipper buttons land on a playfield board** even though they are
   cabinet hardware.
2. Everything else stays where it is wired: cabinet devices (and backbox ones)
   on the cabinet board, the rest on boards under the playfield.
3. One LED stripe per board, on the LED connector. An `Opto_16` has that
   connector too, so a string can sit on a board that is already there for its
   inputs.

Space under a playfield is the real constraint, so the wizard adds as few boards
as the device counts allow and spreads the load across them rather than filling
each to the brim. Where a handful of coils would otherwise force one more board,
it uses **outputs already going spare on the cabinet board** instead - a few
wires from the cabinet to the playfield cost less than a board that has nowhere
to go. It only does this for a coil with no fast-flip switch, never for a
flipper winding, and it says which coils are affected so the wiring is not a
surprise.

**LED string positions** are filled in matrix order, which is a starting point
rather than a claim about how the string runs around the playfield. Reorder them
to match the wiring.

### Flipper timing on WPC Fliptronic

A Fliptronic flipper has two windings driven as two separate outputs, both
switched on by the flipper button. On the original machine the power winding is
ended by whichever comes first:

- **The EOS contact closing** - the normal path. The finger reaches its stop
  after roughly 15 to 30 ms of travel, the CPU drops the power winding the
  moment the contact closes, and the hold winding keeps the finger up.
- **A fixed 30 to 40 ms timeout** - the safety net, for an EOS contact that is
  broken, misadjusted or unplugged. Without it the power winding burns. The
  finger still stays up on the hold winding afterwards, but with no working EOS
  it loses strength: a heavy ball can push the finger down, and the hold winding
  alone cannot raise it again until the button is released and pressed.

**PPUC does not act on the EOS contact yet.** The maximum pulse time is
therefore the only thing ending the pulse, so it fires on every flip rather than
only on failed ones. The wizard sets **40 ms**, which clears the slowest stroke
so no flip is cut short, and stays inside the range WPC itself considered safe
for the winding. Setting it below 30 ms cuts the flip off before the finger
arrives.

The wizard still creates the EOS switch, so it is wired and ready for when PPUC
can act on it.

## Game YAML export

The generated game YAML includes optional switch and coil roles used by the
runtime safety features:

- Switch nodes and switch matrix switch nodes can be marked as `Button`. When
  checked, the exported switch entry contains `button: true`.
- PWM device nodes can be marked as `Ball search`. When checked, the exported
  PWM output entry contains `ballSearch: true`.
- PWM device nodes can be marked as `Dual-wound coil`, exported as
  `dualWinding: true`, for a coil whose EOS contact transfers to its own hold
  winding mechanically. An `End-of-stroke switch` can be referenced alongside
  it and is exported as `eosSwitch: <number>`.
- PWM device nodes can be marked as `Hold winding`, exported as
  `holdWinding: true`, for the hold half of a flipper whose windings are driven
  as two separate outputs, as on WPC Fliptronic. Both flags tell libppuc the
  coil bounds itself, so it is not reported as having no thermal protection.

## Lua rules

Games can store Lua rules and Blockly workspace data. The game export archive
includes the generated YAML, `rules.lua`, and `rules.blockly.json` when rules
data exists, so another config-tool instance can import and continue the
project.

The rules editor always supports direct Lua editing. Blockly integration is
initialized when Blockly assets are available on the page; otherwise the editor
falls back to the Lua textarea.

## Development

### Linux and macOS

Install [hombrew](https://brew.sh/) and
[DDEV](https://ddev.readthedocs.io/en/stable/).

Just follow the instructions for your operating system. But even if not
documented well, even for Linux we recommended to install DDEV via `brew`!
The PPUC ecosystem will require homebrew anyway. And it is always better to use
a package manager.

For _macOS_ these are the essential steps:
```shell
brew install docker
brew install orbstack
brew install ddev/ddev/ddev
mkcert -install
```

For _Linux_ install docker according to https://ddev.readthedocs.io/en/stable/users/install/docker-installation/#linux
Afterwards install DDEV:
```shell
brew install ddev/ddev/ddev
mkcert -install
```

Now clone this project somewhere in your home directory.
It is recommended to create a PPUC directory first where you can also clone
other PPUC components.
```shell
mkdir PPUC
cd PPUC
git clone https://github.com/PPUC/config-tool.git
cd config-tool
ddev start
ddev drush site:install ppuc --site-name="Pinball Power-Up Controller" --account-name=admin --account-pass=admin --existing-config -y
ddev drush dcdi --folder=sites/default/files/default_content --preserve-ids -y
```

Now you can open https://ppuc-config-tool.ddev.site/ in your browser and login
using _ppuc_ as username and _ppuc_ as password.

When you restart your computer you need to start ddev again:
```shell
cd PPUC/config-tool
ddev start
```

#### Update the PPUC config-tool
Once ddev has been started you can also update to the latest version of the
config-tool. It is recommended to export your games before performing the
update.

Within `PPUC/config-tool` run
```shell
ddev snapshot
git pull
ddev drush deploy
ddev drush dcdi --folder=sites/default/files/default_content --preserve-ids --force-override -y
```

TODO: import/update ppuc profile default content after drush deploy
