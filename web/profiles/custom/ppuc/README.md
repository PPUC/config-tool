# PPUC profile default content

The board types, device classes and the other taxonomy every game refers to ship
as default content, exported by `default_content_deploy` into

```
web/sites/default/files/default_content/
```

Import it on a fresh install, or after pulling:

```sh
drush dcdi --folder=sites/default/files/default_content --preserve-ids -y
```

## Changing it

Edit the terms in the site, then export the whole entity type again:

```sh
drush dcde taxonomy_term --folder=sites/default/files/default_content
```

`dcde` writes **two** files per entity: the entity itself under
`taxonomy_term/<uuid>.json`, and a `_thumbs/taxonomy_term/<uuid>.json` holding
its `_dcd_metadata`. The importer builds its list from `_thumbs`, not from the
entity folders, so an entity file without its thumb is never imported - and
nothing says so, because the importer does not know the file is there. That is
how the `Opto_16` board type came to be missing on imported sites.

So export with `dcde`; do not hand-write a file into `taxonomy_term/`. If a pair
has already drifted apart, rebuild the index from the entity folders:

```sh
drush default-content-deploy:sync-thumbs --folder=sites/default/files/default_content
```

`DefaultContentIndexTest` in `ppuc_games` checks that the two halves still agree,
and that each thumb's `sort_key` is the entity id `--preserve-ids` will restore.

Default content alone only reaches a site that does not have the entity id yet.
A site whose taxonomy already uses the id keeps what it has, because the import
runs with `--preserve-ids` and skips a taken id. Anything existing sites must end
up with therefore needs an update hook in `ppuc_games.install` as well - see
`ppuc_games_update_11004()`, which adds `Opto_16` - so that `drush deploy` covers
them too.

## History

Before Drupal 10.3 this profile used the `default_content` module, which took a
list of uuids in `ppuc.info.yml` and exported them with `drush dcem ppuc`. Both
are gone: there is no uuid list to keep up to date any more, and `dcem` is not a
command `default_content_deploy` provides.
