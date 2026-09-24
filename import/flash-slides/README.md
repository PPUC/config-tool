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

## The images

`images/` holds the scans the script uploads:

| file | what it is |
|---|---|
| `flyer-backglass.jpg` | the flyer cover: backglass, "On location RECORD BREAKING earnings" |
| `flyer-high-voltage.jpg` | "Electrifying... high voltage action!" |
| `flyer-cabinet.jpg` | the cabinet, a player at the machine |
| `flyer-us-playfield.jpg` | the playfield page, with the feature list |
| `flyer-japan-sega.jpg` | Sega Enterprises' Japanese flyer |

Once uploaded they are file entities like any other, so `/node/195/zip` carries
them into the game archive the same way it carries the translite, the ROM and
the manual: base64 inside the file entity, not a path that has to exist on the
other machine.

A slide whose image is missing is still created, with its words and no
photograph.
