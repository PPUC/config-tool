# The Flash how-to-play slides

`create-slides.php` authors the 26 slides shown on the backbox screen while the
machine stands idle: the rules, the tips, the new multiball, the trivia, and
the original 1978 flyers.

```
ddev drush php:script import/flash-slides/create-slides.php
```

It matches by title within the game, so running it again updates the slides
rather than doubling them. That also means it overwrites anything edited in the
UI: once a slide has been edited in the config tool, edit it there, and treat
this script as the record of how the set started rather than as its master.

The slides with no image are the rules and the tips. Photographs of this
machine's own playfield go on those later, with markers pointing at the shots
being described.

## The flyer scans are not in git

`images/` holds five 1978 flyer and press scans, and is deliberately ignored.
They are Williams and Sega material, reproduced from scans that are not freely
licensed, and this repository is public. Showing them on the machine they are
about is one thing; redistributing them in a git repository is another, and it
cannot be undone once pushed.

Put the files back under `images/` before running the script:

| file | what it is |
|---|---|
| `flyer-high-voltage.jpg` | "Electrifying... high voltage action!" |
| `flyer-backglass.jpg` | backglass, "On location RECORD BREAKING earnings" |
| `flyer-us-playfield.jpg` | US flyer, playfield photograph with callouts |
| `flyer-cabinet.jpg` | cabinet photograph, a player at the machine |
| `flyer-japan-sega.jpg` | Sega Enterprises' Japanese flyer |

A slide whose image is missing is still created, with its words and no
photograph.
