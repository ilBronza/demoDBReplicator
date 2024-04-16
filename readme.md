# demoDBReplicator
 
this package is a simple database replicator for Laravel 5.5+ that allows you to replicate a database from a source to a destination. It is useful to replicate a production database to a development environment to have a real data set to work with.

## Installation

run `composer require ilbronza/demodbreplicator`

publish the configuration file with `php artisan vendor:publish --provider="IlBronza\DemoDBReplicator\DemoDBReplicatorServiceProvider"`

## Configuration

edit the `config/demodbreplicator.php` file with the source and destination database connections

create the .[whatever_you_used_in_config] file (or files if you want to split them) to store the database credentials for the two commands

to stick to base configuration you could create

.dbReplicatorConf
`
[mysqldump]
user = "root"
password = ""

[mysql]
user = "root"
password = ""

`


## Usage

run `php artisan dbreplicator:replicate` to replicate the source database to the destination database


or call `your_website.something/dbreplicator/replicate-db` with a superadmin role user
