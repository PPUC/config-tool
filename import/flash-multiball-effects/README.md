# Flash multiball effects, as default_content_deploy content

Three `led_effect` nodes for the Flash cabinet LED stripe, which the multiball
rule triggers by name. See `ppuc_games/flash/MULTIBALL_TECHNICAL.md`.

| machine name | mode | priority | repeat | duration |
|---|---|---|---|---|
| `multiball-ready` | 12, Rainbow Cycle | 9 | **-1** | 0 |
| `multiball-start` | 27, Strobe Rainbow | 10 | 0 | 1000 |
| `multiball-lost` | 0, Static, black | 10 | 0 | 300 |

`repeat: -1` on the waiting light is deliberate, and the field's own help text
says why: -1 runs endlessly "or until it gets terminated by an effect with
higher priority", while -2 is resumed once the higher-priority effect is over.
A -2 rainbow would come back the moment the strobe finished.

## Importing

Upload `flash-multiball-effects.tar.gz` through the config tool's game import
controller, which imports game content **without** `--preserve-ids`. Ids belong
to the instance; only standard content like the fx_modes taxonomy is shared
between instances and imported with ids preserved. The node ids in these files
are therefore ignored — they are here because an export carries them.

Matching is by uuid, so importing twice updates rather than duplicates.

## What they reference

Every uuid below was read from the running config tool rather than from an
export, and each was confirmed to be in use:

- `field_string` → the `Cabinet` addressable_leds node, nid 6,
  `ba4cd7e3-b1de-487a-b08c-766fdbc9d544`, which already carries flash-flasher,
  attract-sparkle, spinner, jet, top-rollover, eject-hole and extra-ball. The
  effect points at the stripe, so the stripe node itself is not modified.
- `field_effect` → the `fx_modes` terms Rainbow Cycle
  (`dbe3f057-cb60-4c68-80d6-b06d7de7f3b0`), Strobe Rainbow
  (`62a159b2-b1bc-4d1d-b198-f47a0840eed9`) and Static
  (`d527e92f-5c67-42ff-b14d-efb497ea0e73`).
- author → `ppuc`, `f7760c1e-2497-482d-8e95-dd7f4f5e02e3`, the same author as
  the existing effects.
