# timdev/db-snap

## What is this?

It's (yet another) database snapshot script.  It dumps your (mysql) database and sticks the dump in an S3 bucket. 
It's implemented as a PHP script, and might be useful for folks who work in PHP.  Some day, I'll probably reimplement 
it in golang (or not). 

## Features

* Store bzip2-compressed snapshots of databases in S3.
* Take snapshots over SSH
    * No need to open database ports to backup machine
    * Connect to a bastion host and pull data from other hosts on private network.
* Cron Friendly
    * Regular output to STDOUT, errors/warnings to STDERR.
        * Write cron jobs to direct STDOUT someplace like a log file (`... >> /var/log/db-snap.log`)
        * Configure cron to mail job output to you (via MAILTO) -- you'll only get email when something goes wrong.
    * CLI options are the entire interface

## Warning/Disclaimer/Guarantee

I wrote this to meet my own needs, and it hasn't been tested beyond making it work for me.  Use it at your own risk.
It might destroy your computer, network, or the known universe.  It's guaranteed to have at least a few bugs.

You've been warned.  

## Contributing

If you find this useful, and want to contribute, feel free to open a pull request.

If you want *me* to fix a bug, or implement some feature ... well, it can't hurt to ask.  Feel free to open an issue,
but don't be sad if I ignore it.
      
## Installation

```bash
$ git clone https://github.com/timdev/db-snap.git
$ cd db-snap
$ composer install
```

## How To

A minimal invocation might looks like this (from the project root):

`./bin/db-snap --db=exampledb --s3bucket=my-private-s3-bucket`

Which will work fine assuming:

* Your default credentials in ~/.aws/credentials can write to `my-private-s3-bucket`
* You are able to connect to `exampledb` without providing any parameters (that is, `mysql exampledb` works from your
 shell).
 
## More options
 
There are a bunch of optional CLI args available:
 
```text
Usage: ./snapshot.php [--aws-access-key-id awsAccessKey] [--aws-profile awsProfile (default: )] [--aws-region awsRegion] [--aws-secret-access-key awsSecretKey] [--db dbname] [--db-host dbhost (default: localhost)] [--db-password dbpass] [--db-user dbuser] [--delete-local] [-h, --help] [--hostname hostname] [--local-dir localDir (default: /var/folders/73/96g__8gd7kx9fgcsmc1m_c500000gn/T/db-snaps)] [--no-sweep] [--s3bucket bucket] [--s3prefix bucketPrefix (default: db_backups)] [--ssh-host sshHost] [--sweep-days sweepDays (default: 42)] [-v, --version]

Required Arguments:
	--db dbname
		The name of the database to back up.
	--s3bucket bucket
		The name of the AWS S3 bucket to store the backups

Optional Arguments:
	-h, --help
		Print usage information and exit
	-v, --version
		Print version and exit.
	--s3prefix bucketPrefix (default: db_backups)
		The prefix (folder) under which to store the backup in the s3 bucket.  Backup file path will be: <bucket-prefix>/<hostname>/<hostname>.<dbname>.<timestamp>.sql.bz2
	--hostname hostname
		If specified, overrides the hostname part of the object path in the bucket.
	--db-host dbhost (default: localhost)
		MySQL server to connect to
	--db-user dbuser
		Database user to connect as.  Defaults to current user.
	--db-password dbpass
		Password to use when connecting to the database.
	--aws-profile awsProfile (default: )
		AWS Profile (from ~/.aws/credentials) to use to connect to S3
	--aws-access-key-id awsAccessKey
		AWS Access Key
	--aws-secret-access-key awsSecretKey
		AWS Secret Access Key
	--aws-region awsRegion
		AWS region to connect to
	--local-dir localDir (default: /var/folders/73/96g__8gd7kx9fgcsmc1m_c500000gn/T/db-snaps)
		Local directory for snapshots.
	--ssh-host sshHost
		SSH to this host to perform dump. ie: example.com or user@example.com
	--delete-local
		Delete local snapshot immediately after successful upload to s3.
	--sweep-days sweepDays (default: 42)
		Delete all files in <local-dir> more than <sweep-days> days old. Default: 42 days (6 weeks)
	--no-sweep
		Do not remove local files. Overrides --sweep-days.
```
 
## Snapshot Storage

Snapshots will be stored locally in $TEMP/db-snaps, unless you specify another local directory using the --local-dir 
options.  Using --local-dir is recommended, so your system doesn't automatically clean up local snapshots. 

By default, the script will remove all files from `--local-dir` that are older than 42 days (six weeks). You can change
the retention period by providing a positive integer for `--sweep-days`. If you don't want to delete any files, you can
pass the `--no-sweep` flag.

Alternatively, you can pass `--delete-local`, which will remove the local copy of the current snapshot as soon as it is
successfully uploaded to S3.

Snapshots will be named by composing the host-name, database name, and date: `<hostname>.<dbname>.<datestamp>.sql.bz2`.  
For example, if your database host is `db01.example.com`, and your database is `customers`, the snapshot file will be
`db01.example.com.customers.20180510-2015.sql.bz2` (for a snapshot taken at 20:15 UTC on May 5th, 2018).

Snapshots are stored in the specified S3 bucket.  By default, they a prefix of "db_backups/" is prepended to the 
filename.  This prefix can be changed using the `--s3prefix` option.  If you want your snapshots stored in the root
of the bucket, simply specify an empty prefix (ie: `--s3prefix='''`)

## Dump over SSH

You can pull a dump from a remote server by passing the `--ssh-host=` option.  db-snap will invoke the dump script 
(mysqldump) on the remote host, and stream the output to a local file.  Examples: 

 * `--ssh-host=example.com` -- Connect to example.com
 * `--ssh-host=alice@example.com` -- Connect to example.com as user `alice`
 
 Notes:
 
 * No password support. You must be able to connect non-interactively (ie: ssh keys).
 * Be careful about your ForwardAgent setup while testing.
 
 This feature allows you to set up a central backup server that connects to various hosts to snapshot databases.  

## Example Usage

Coming soon.

## To-Do

There are plenty of things that could be done to improve/extend this:

- [ ] Add some usage examples above.
- [ ] Add support for postgres.
- [ ] Allow S3 object paths to be constructed using string templates.
- [x] Add support for pulling dumps through an SSH tunnel.
- [X] Make local snapshot retention period (currently six weeks) configurable via an option.
- [ ] Figure out how to package this as a .phar
