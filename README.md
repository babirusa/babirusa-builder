# babirusa-builder

If for some reason, default [babirusa runtimes](https://github.com/babirusa/babirusa-runtime) are not enough for you.
E.g. it does not contain PHP extension, or you lambda function requires different PHP version.
You can build your own runtime with `babirusa-builder`.

## Installation
Easiest way to install `babirusa-builder` is to download latest `babirusa-builder.phar` from [releases page](https://github.com/babirusa/babirusa-builder/releases).

`babirusa-builder` requires [Docker](https://www.docker.com/) running.

## Usage
In this example we make an assumption that you want to build PHP `7.2.5` for `aws` platform.

### Create custom Dockerfile
`$ mkdir aws && touch aws/Dockerfile`

### Run babirusa-builder to build your runtime
`$ ~/Downloads/babirusa-builder.phar -paws -t. 7.2.5`

As a result you should see new file `php-7.2.5` in your working directory.
