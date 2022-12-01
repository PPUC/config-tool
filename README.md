# PPUC config-tool

The PPUC config-tool is a web application to configure your controllers. it is still WIP.

## Development

### macOS

Install [hombrew](https://brew.sh/).

Now install DDEV. Details about that process could be found [here](https://ddev.readthedocs.io/en/latest/users/install/docker-installation/#macos).
But these are the essential steps:
```sh
brew install docker
brew install colima
brew install drud/ddev/ddev
mkcert -install
colima start --cpu 4 --memory 6 --disk 100 --dns=1.1.1.1
```
When your computer restarts, you’ll need to run `colima start` again.

Now clone this project somewhere in your home directory.
It is recommended to create a PPUC directory first where you can also clone other PPUC components.
```
mkdir PPUC
cd PPUC
git clone https://github.com/PPUC/config-tool.git

cd config-tool
ddev start
```

```
ddev ssh
drush si ppuc --site-name="Pinball Power-Up Controller" --account-name=ppuc --account-pass=ppuc
```
