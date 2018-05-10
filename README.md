# timdev/db-snap

## What is this?

It's (yet another) database snapshot script.  It dumps your database and sticks the dump in an S3 bucket. 
It's implemented as a PHP script, and might be useful for folks who work in PHP.  Some day, I'll probably reimplement it
in golang (or not). 

## Installation

```bash
$ git clone https://github.com/timdev/db-snap.git
$ cd db-snap
$ composer install
```

## How To

A minimal invokation might looks like this (from the project root):

`./bin/db-snap --db=exampledb --s3bucket=my-private-s3-bucket`

Which will work fine assuming:

* Your default credentials in ~/.aws/credentials can write to `my-private-s3-bucket`
* You are able to connect to `exampledb` without providing any parameters (that is, `mysql exampledb` works from your
 shell).
 
## More options
 
There are a bunch of optional CLI args available:
 
```text
Usage: ./db-snap [--aws-access-key-id awsAccessKey] [--aws-profile awsProfile (default: default)] [--aws-secret-access-key awsSecretKey] [--db dbname] [--db-host dbhost (default: localhost)] [--db-password dbpass] [--db-user dbuser (default: yourname)] [--hostname hostname (default: web.example.com)] [--local-dir localDir (default: /var/folders/md/s_kbvcsd1kg_q2t1rl8l7d8w0000gn/T/db-snaps)] [--s3bucket bucketName] [--s3prefix bucketPrefix (default: db_backups)]

Required Arguments:
	--db dbname
		The name of the database to back up.
	--s3bucket bucketName
		The name of the AWS S3 bucket to store the backups

Optional Arguments:
	--s3prefix bucketPrefix (default: db_backups)
		The prefix (folder) under which to store the backup in the s3 bucket.  Backup file path will be: <bucket-prefix>/<hostname>/<hostname>.<dbname>.<timestamp>.sql.bz2
	--hostname hostname
		If specified, overrides the hostname part of the object path in the bucket.
	--db-host dbhost (default: localhost)
		MySQL server to connect to
	--db-user dbuser (default: yourname)
		Database user to connect as.  Defaults to current user.
	--db-password dbpass
		Password to use when connecting to the database.
	--aws-profile awsProfile (default: default)
		AWS Profile (from ~/.aws/credentials) to use to connect to S3
	--aws-access-key-id awsAccessKey
		AWS Access Key
	--aws-secret-access-key awsSecretKey
		AWS Secret Access Key
	--local-dir localDir (default: /var/folders/md/s_kbvcsd1kg_q2t1rl8l7d8w0000gn/T/db-snaps)
		Local directory for snapshots.
```
 
## Snapshot Storage

Snapshots will be stored locally in $TEMP/db-snaps, unless you specify another local directory using the --local-dir 
options.  Using --local-dir is recommended, so your system doesn't automatically clean up local snapshots.  This script
automatically purges local backups that are moe than 6 weeks old at the end of each successful run.

Snapshots will be named by composing the host-name, database name, and date: `<hostname>.<dbname>.<datestamp>.sql.bz2`.  
For example, if your database host is `db01.example.com`, and your database is `customers`, the snapshot file will be
`db01.example.com.customers.20180510-2015.sql.bz2` (for a snapshot taken at 20:15 UTC on May 5th, 2018).

Snapshots are stored in the specified S3 bucket.  By default, they a prefix of "db_backups/" is prepended to the 
filename.  This prefix can be changed using the `--s3prefix` option.  If you want your snapshots stored in the root
of the bucket, simply specify an empty prefix (ie: `--s3prefix='''`)

## To-Do

There are plenty of things that could be done to improve/extend this:

- [ ] Allow S3 object paths to be constructed using string templates.
- [ ] Add support for postgres.
- [ ] Add support for pulling dumps through an SSH tunnel.
- [ ] Make local snapshot retention period (currently six weeks) configurable via an option.
- [ ] Figure out how to package this as a .phar