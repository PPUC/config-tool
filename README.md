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
brew install drud/ddev/ddev
mkcert -install
```

For _Linux_ install docker according to https://ddev.readthedocs.io/en/stable/users/install/docker-installation/#linux
Afterwards install DDEV:
```shell
brew install drud/ddev/ddev
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
